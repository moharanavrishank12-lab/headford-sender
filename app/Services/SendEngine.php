<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Event;
use App\Models\Lead;
use App\Models\Send;
use App\Models\Suppression;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Bhejne ka dimaag. Har tick par har ID zyada se zyada 1 email bhejti hai (dheere-dheere, window mein faila ke).
 * Niyam: lead ki local subah (East/West), Mon-Fri, US chhuttiyan nahi, ID ka roz ka cap (follow-up milake),
 * nayi ID ka ramp, follow-up usi ID + usi thread se, crash ke baad bhi double-send nahi.
 */
class SendEngine
{
    public function __construct(private GmailClient $gmail) {}

    public static function day(): string
    {
        return CarbonImmutable::now('America/New_York')->toDateString();
    }

    public function capToday(Account $a): int
    {
        if ($a->status !== 'active') {
            return 0;
        }

        return Calendar::rampCap($a->ramp_start ? CarbonImmutable::parse($a->ramp_start) : null, (int) $a->daily_cap);
    }

    public function usedToday(Account $a): int
    {
        return Send::where('account_id', $a->id)->where('send_day', self::day())->whereBetween('step', [1, 3])->whereIn('status', ['reserved', 'sent'])->count();
    }

    /** Aaj is ID ke kitne follow-up abhi baaki hain (unke liye jagah rakho) */
    public function followupsDueToday(Account $a): int
    {
        $end = CarbonImmutable::now('America/Los_Angeles')->endOfDay()->setTimezone('UTC');

        return Lead::where('account_id', $a->id)->where('status', 'active')->where('next_send_at', '<=', $end)->count();
    }

    private function openZones(): array
    {
        return array_values(array_filter(['E', 'W'], fn ($z) => Calendar::windowOpen($z)));
    }

    /** Ek chakkar: har ID ko mauka. Lautata hai kitne bheje. */
    public function tick(): array
    {
        $out = ['sent' => 0, 'skipped' => 0, 'errors' => 0];
        $zones = $this->openZones();
        if (! $zones) {
            return $out + ['note' => 'koi window khula nahi'];
        }
        if (trim((string) \App\Models\Setting::get('address', '')) === '') {
            Alerts::raise('address', 'Signature mein business address nahi hai - jab tak nahi daaloge, koi email nahi jayegi (US CAN-SPAM).');

            return $out + ['note' => 'address nahi'];
        }
        Alerts::clear('address');
        $gapMin = Calendar::windowMinutes() * 0.85;
        foreach (Account::where('status', 'active')->orderBy('last_sent_at')->get() as $a) {
            $cap = $this->capToday($a);
            $used = $this->usedToday($a);
            if ($used >= $cap) {
                continue;
            }
            // dheere: window ko cap mein baanto (+ thoda random) - ek saath sab nahi
            $gap = ($gapMin / max(1, $cap)) * (0.8 + mt_rand(0, 40) / 100);
            if ($a->last_sent_at && $a->last_sent_at->diffInMinutes(now(), true) < $gap) {
                continue;
            }
            $lead = $this->pickFollowup($a, $zones);
            if (! $lead) {
                $free = $cap - $used - $this->followupsDueToday($a);
                $lead = $free > 0 ? $this->pickNew($a, $zones) : null;
            }
            if (! $lead) {
                continue;
            }
            try {
                $r = $this->sendStep($a, $lead, $lead->step + 1);
                $r === 'sent' ? $out['sent']++ : $out['skipped']++;
            } catch (Throwable $e) {
                $out['errors']++;
                $a->update(['last_error' => substr($e->getMessage(), 0, 490)]);
                Log::warning('send error', ['acc' => $a->email, 'lead' => $lead->domain, 'e' => $e->getMessage()]);
            }
        }

        return $out;
    }

    private function pickFollowup(Account $a, array $zones): ?Lead
    {
        return Lead::where('account_id', $a->id)->where('status', 'active')->whereIn('zone', $zones)
            ->where('next_send_at', '<=', now())->orderBy('next_send_at')->first();
    }

    private function pickNew(Account $a, array $zones): ?Lead
    {
        $fresh = now()->subDays(config('sender.reverify_days'));
        for ($i = 0; $i < 20; $i++) {
            // Atomic claim: sirf ek ID ek lead le (do process ek saath chalein to bhi)
            $lead = Lead::where('status', 'queued')->whereNull('account_id')->whereIn('zone', $zones)
                ->orderByRaw("CASE grp WHEN 'A' THEN 0 WHEN 'B' THEN 1 ELSE 2 END")->orderBy('id')->first();
            if (! $lead) {
                return null;
            }
            if (! $lead->verified_at || $lead->verified_at < $fresh) {
                $lead->update(['status' => 'stale', 'stop_reason' => 'verify 30 din se purana - PC dobara verify kare']);
                Event::log($lead->domain, 'reverify', ['email' => $lead->email]);

                continue;
            }
            if ($why = Suppression::blocked($lead->email, $lead->domain)) {
                $lead->update(['status' => 'suppressed', 'stop_reason' => $why]);
                Event::log($lead->domain, 'suppressed', $why);

                continue;
            }
            $n = Lead::where('id', $lead->id)->whereNull('account_id')->where('status', 'queued')->update(['account_id' => $a->id, 'status' => 'active', 'next_send_at' => now()]);
            if ($n) {
                return $lead->fresh();
            }
        }

        return null;
    }

    /** Ek email bhejo (step 1/2/3). 'sent' | 'skip' */
    public function sendStep(Account $a, Lead $lead, int $step): string
    {
        if ($step < 1 || $step > 3) {
            return 'skip';
        }
        if ($why = Suppression::blocked($lead->email, $lead->domain)) {
            $lead->update(['status' => 'suppressed', 'stop_reason' => $why, 'next_send_at' => null]);
            Event::log($lead->domain, 'suppressed', $why);

            return 'skip';
        }
        $msgid = Mime::msgId($a->email);
        // 1) Pehle "reserve" - unique(lead, step). Crash/do process = doosri baar yahin ruk jayega.
        try {
            $send = Send::create(['lead_id' => $lead->id, 'account_id' => $a->id, 'step' => $step, 'rfc_msgid' => $msgid, 'send_day' => self::day()]);
        } catch (QueryException $e) {
            return 'skip';
        }
        $body = ['', $lead->body1, $lead->body2, $lead->body3][$step]."\n\n".Signature::for($a);
        $raw = Mime::build([
            'from' => $a->email, 'from_name' => $a->from_name, 'to' => $lead->email, 'to_name' => trim($lead->first_name.' '.$lead->last_name),
            'subject' => $step === 1 ? $lead->subject : 'Re: '.$lead->subject, 'body' => $body, 'msgid' => $msgid,
            'in_reply_to' => $step > 1 ? $lead->last_msgid : null,
            'references' => $step > 1 ? trim($lead->first_msgid.' '.($lead->last_msgid !== $lead->first_msgid ? $lead->last_msgid : '')) : null,
            'unsub_url' => Signature::unsubUrl($lead->id),
        ]);
        try {
            $res = $this->gmail->send($a->email, $raw, $step > 1 ? $lead->thread_id : null);
        } catch (Throwable $e) {
            // 400 = is lead ka pata/email galat -> lead band. 401/403 = ID ka Google access nahi -> ID ruko.
            // Baaki (timeout, 5xx, 429) = gaya ya nahi pata nahi -> reservation rakho, recover() Gmail Sent mein dekhega.
            $m = $e->getMessage();
            if (str_contains($m, 'HTTP 400')) {
                $send->delete();
                $lead->update(['status' => 'failed', 'stop_reason' => substr('Gmail ne mana kiya: '.$m, 0, 250), 'next_send_at' => null]);
                Event::log($lead->domain, 'failed', substr($m, 0, 300));
            } elseif (preg_match('/HTTP 40[13]|token nahi mila|JWT/', $m)) {
                $send->delete();
                $a->update(['status' => 'paused', 'paused_reason' => 'Google access nahi mila (domain-wide delegation?) - '.substr($m, 0, 200)]);
                Event::log(null, 'account_paused', ['account' => $a->email, 'why' => 'google access']);
            } else {
                $send->update(['error' => substr($m, 0, 490)]);
            }
            throw $e;
        }
        $this->finalize($a, $lead, $send, $res['id'] ?? null, $res['threadId'] ?? null);

        return 'sent';
    }

    private function finalize(Account $a, Lead $lead, Send $send, ?string $gid, ?string $thread): void
    {
        $real = $send->rfc_msgid;
        if ($gid) {
            try { // Gmail ne Message-ID badla ho to asli wala lo (follow-up threading ke liye)
                $real = GmailClient::header($this->gmail->get($a->email, $gid, 'metadata'), 'Message-ID') ?: $real;
            } catch (Throwable $e) {
            }
        }
        $step = $send->step;
        DB::transaction(function () use ($a, $lead, $send, $gid, $thread, $real, $step) {
            $send->update(['status' => 'sent', 'gmail_id' => $gid, 'thread_id' => $thread, 'sent_at' => now(), 'error' => null]);
            $upd = ['step' => max($lead->step, $step), 'account_id' => $a->id, 'last_msgid' => $real];
            if ($step === 1) {
                $upd += ['thread_id' => $thread, 'first_msgid' => $real];
            }
            if ($step < 3) {
                $upd += ['status' => 'active', 'next_send_at' => Calendar::afterWorkdays($lead->zone ?: 'E', config('sender.followup_days')[$step - 1])];
            } else {
                $upd += ['status' => 'done', 'next_send_at' => null];
            }
            $lead->update($upd);
            $a->update(['last_sent_at' => now(), 'last_error' => null]);
        });
        Event::log($lead->domain, 'sent', ['step' => $step, 'from' => $a->email, 'at' => now()->toIso8601String()]);
    }

    /** Crash/timeout ke baad: "reserved" emails Gmail Sent mein hain? Hain = pakka; nahi = reservation hatao (dobara bheja jayega) */
    public function recover(): int
    {
        $n = 0;
        foreach (Send::where('status', 'reserved')->where('created_at', '<', now()->subMinutes(5))->limit(50)->get() as $s) {
            $a = Account::find($s->account_id);
            $lead = Lead::find($s->lead_id);
            if (! $a || ! $lead) {
                $s->delete();

                continue;
            }
            try {
                $hit = $this->gmail->findSent($a->email, $s->rfc_msgid, $lead->email, $s->created_at->timestamp);
            } catch (Throwable $e) {
                continue; // Gmail se pata nahi chala - agli baar
            }
            if ($hit) {
                $this->finalize($a, $lead, $s, $hit['id'], $hit['threadId'] ?? null);
            } else {
                $s->delete();
            }
            $n++;
        }

        return $n;
    }
}
