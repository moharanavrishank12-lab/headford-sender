<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Event;
use App\Models\Lead;
use App\Models\Reply;
use App\Models\Send;
use App\Models\Suppression;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Inbox padhna. Sirf HAMARE threads ke reply (Instantly warmup ke mail apne aap chhoot jaate hain - woh hamare thread mein nahi).
 * Bounce (mailer-daemon) aur out-of-office alag pehchaane jaate hain. Koi bhi insaani reply = us lead ke follow-up band.
 */
class ReplyEngine
{
    public function __construct(private GmailClient $gmail, private Classifier $cls) {}

    public function pollAll(): array
    {
        $c = ['replies' => 0, 'bounces' => 0, 'ooo' => 0, 'errors' => 0];
        foreach (Account::orderBy('id')->get() as $a) {
            try {
                $r = $this->poll($a);
                foreach ($r as $k => $v) {
                    $c[$k] += $v;
                }
                $a->update(['last_checked_at' => now()]);
            } catch (Throwable $e) {
                $c['errors']++;
                $a->update(['last_error' => substr('inbox: '.$e->getMessage(), 0, 490)]);
            }
        }
        $this->processPending();

        return $c;
    }

    private static function isDaemon(string $from): bool
    {
        return (bool) preg_match('/mailer-daemon|postmaster|mail delivery (sub)?system/i', $from);
    }

    public function poll(Account $a): array
    {
        $c = ['replies' => 0, 'bounces' => 0, 'ooo' => 0, 'errors' => 0];
        $threads = array_flip(Send::where('account_id', $a->id)->whereNotNull('thread_id')->distinct()->pluck('thread_id')->all());
        $seenKey = 'seen:'.$a->id;
        $seen = Cache::get($seenKey, []);
        foreach ($this->gmail->list($a->email, 'in:inbox -from:me newer_than:4d', 300) as $m) {
            if (isset($seen[$m['id']]) || Reply::where('gmail_id', $m['id'])->exists()) {
                continue;
            }
            $ours = isset($threads[$m['threadId']]);
            $full = null;
            if (! $ours) {
                // hamare thread ka nahi: sirf bounce ho sakta hai (warna warmup/doosra mail - chhodo)
                $meta = $this->gmail->get($a->email, $m['id'], 'metadata');
                if (! self::isDaemon((string) GmailClient::header($meta, 'From'))) {
                    $seen[$m['id']] = 1;

                    continue;
                }
            }
            $full = $this->gmail->get($a->email, $m['id']);
            $kind = $this->store($a, $full, $ours);
            if ($kind) {
                $c[$kind === 'bounce' ? 'bounces' : ($kind === 'ooo' ? 'ooo' : 'replies')]++;
            }
            $seen[$m['id']] = 1;
        }
        if (count($seen) > 5000) {
            $seen = array_slice($seen, -3000, null, true);
        }
        Cache::put($seenKey, $seen, now()->addDays(10));

        return $c;
    }

    /** Ek message save karo + turant asar (bounce/ooo). Reply ka matlab processPending() mein. */
    public function store(Account $a, array $msg, bool $ours): ?string
    {
        $from = (string) GmailClient::header($msg, 'From');
        $subject = (string) GmailClient::header($msg, 'Subject');
        $body = GmailClient::text($msg);
        $fromEmail = strtolower(preg_match('/<([^>]+)>/', $from, $mm) ? $mm[1] : trim($from));
        $lead = $ours ? Lead::where('thread_id', $msg['threadId'])->first() : null;
        $kind = 'reply';
        if (self::isDaemon($from) || str_contains(strtolower((string) GmailClient::header($msg, 'Content-Type')), 'report-type=delivery-status')) {
            $kind = 'bounce';
            if (! $lead) {
                $failed = GmailClient::header($msg, 'X-Failed-Recipients')
                    ?: (preg_match('/(?:Final|Original)-Recipient:\s*rfc822;\s*([^\s<>]+@[^\s<>]+)/i', $body, $x) ? $x[1] : null)
                    ?: (preg_match('/(?:wasn\'t delivered to|delivery to the following recipient[s]? failed[^\n]*\n\s*)\s*([^\s<>]+@[^\s<>]+)/i', $body, $y) ? $y[1] : null);
                if ($failed) {
                    $lead = Lead::where('email', strtolower(trim($failed)))->where('account_id', $a->id)->first();
                }
            }
            if (! $lead) {
                return null; // hamara nahi (warmup ka bounce wagairah)
            }
        } elseif (preg_match('/auto-replied|auto-generated/i', (string) GmailClient::header($msg, 'Auto-Submitted')) || GmailClient::header($msg, 'X-Autoreply') || GmailClient::header($msg, 'X-Autorespond')
            || preg_match('/^(automatic reply|auto(matic)?[- ]?reply|out of (the )?office|away from)/i', $subject)) {
            $kind = 'ooo';
        }
        try {
            $r = Reply::create([
                'gmail_id' => $msg['id'], 'lead_id' => $lead?->id, 'account_id' => $a->id, 'thread_id' => $msg['threadId'] ?? null,
                'from_email' => $fromEmail, 'subject' => mb_substr($subject, 0, 250), 'body' => mb_substr($body, 0, 20000), 'kind' => $kind,
                'class' => $kind === 'bounce' ? 'BOUNCE' : ($kind === 'ooo' ? 'OUT_OF_OFFICE' : null),
                'received_at' => isset($msg['internalDate']) ? \Carbon\Carbon::createFromTimestampMs((int) $msg['internalDate']) : now(),
            ]);
        } catch (QueryException $e) {
            return null; // pehle se save
        }
        if ($kind === 'bounce' && $lead) {
            $lead->update(['status' => 'bounced', 'stop_reason' => 'bounce', 'next_send_at' => null]);
            Suppression::add($lead->email, 'bounce');
            $r->update(['action' => 'bounce - follow-up band, email suppression mein']);
            Event::log($lead->domain, 'bounce', ['email' => $lead->email, 'account' => $a->email]);
            $this->bounceGuard($a);
        } elseif ($kind === 'ooo') {
            $r->update(['action' => 'out-of-office - sequence chalta rahega']);
        }

        return $kind;
    }

    /** ID ka bounce 7 din mein 3% se upar (kam se kam 30 email) = ID ruko */
    public function bounceGuard(Account $a): ?float
    {
        $since = now()->subDays(7);
        $sent = Send::where('account_id', $a->id)->where('status', 'sent')->whereBetween('step', [1, 3])->where('sent_at', '>=', $since)->count();
        $b = Reply::where('account_id', $a->id)->where('kind', 'bounce')->whereNotNull('lead_id')->where('created_at', '>=', $since)->count();
        if ($sent < config('sender.bounce_min_sends') && $b < 3) {
            return null;
        }
        $pct = $sent ? $b * 100 / $sent : 100;
        if ($pct > config('sender.bounce_pause_pct') && $a->status === 'active') {
            $a->update(['status' => 'paused', 'paused_reason' => sprintf('bounce %.1f%% (7 din, %d/%d) - auto-pause', $pct, $b, $sent)]);
            Event::log(null, 'account_paused', ['account' => $a->email, 'why' => 'bounce', 'pct' => round($pct, 1)]);
            Alerts::raise('bounce_'.$a->id, "{$a->email} ka bounce ".round($pct, 1)."% - ID rok di gayi. Data/verify dekho.");
        }

        return $pct;
    }

    /** Insaani replies ka matlab nikaalo aur kaam karo */
    public function processPending(): int
    {
        $n = 0;
        foreach (Reply::where('kind', 'reply')->whereNull('action')->where('tries', '<', 6)->orderBy('id')->limit(50)->get() as $r) {
            $r->increment('tries');
            $lead = $r->lead_id ? Lead::find($r->lead_id) : null;
            $res = $this->cls->classify((string) $r->body, (string) $r->subject);
            $class = $res['class'];
            // koi bhi insaani reply = us lead ke aage ke follow-up band
            if ($lead && in_array($lead->status, ['active', 'queued'], true)) {
                $lead->update(['status' => 'replied', 'next_send_at' => null, 'stop_reason' => 'reply aaya']);
            }
            if (! $class) {
                if ($r->tries >= 6 || $res['by'] !== 'retry') {
                    // samajh nahi aaya: Mark ko nahi bhejte - dashboard par "haath se dekho"; aap "Positive" dabao to handoff jayega
                    $r->update(['class' => 'UNCLEAR', 'summary' => $res['summary'], 'action' => 'HAATH SE DEKHO - samajh nahi aaya']);
                    $lead?->update(['status' => 'review']);
                    Event::log($lead?->domain, 'review', ['summary' => $res['summary'], 'reply' => mb_substr((string) $r->body, 0, 4000), 'from' => $r->from_email]);
                    $n++;

                    continue;
                } else {
                    continue;
                }
            } else {
                $r->update(['class' => $class, 'summary' => $res['summary']]);
            }
            $this->act($r->fresh(), $lead, $class);
            $n++;
        }

        return $n;
    }

    /** Dashboard se: "haath se dekho" wale reply ko aap positive / negative bana sakte ho */
    public function decide(Reply $r, string $class): void
    {
        $r->update(['class' => $class, 'action' => null]);
        $this->act($r->fresh(), $r->lead_id ? Lead::find($r->lead_id) : null, $class);
    }

    private function act(Reply $r, ?Lead $lead, string $class): void
    {
        $dom = $lead?->domain;
        if (in_array($class, config('sender.positive_classes'), true) && $lead) {
            DB::transaction(function () use ($lead) {
                if (! $lead->fresh()->lead_no) {
                    $no = (int) Lead::max('lead_no') + 1;
                    $lead->update(['lead_no' => $no]);
                }
            });
            $lead->refresh();
            $lead->update(['status' => 'positive']);
            [$lo, $hi] = config('sender.handoff_delay_min');
            $r->update(['action' => $r->action ?: 'POSITIVE - Mark ko handoff', 'handoff_at' => null]);
            Cache::put('handoff_due:'.$r->id, now()->addMinutes(random_int($lo, $hi))->timestamp, now()->addDays(3));
            Event::log($dom, 'positive', ['lead_no' => $lead->lead_no, 'class' => $class, 'summary' => $r->summary, 'reply' => mb_substr((string) $r->body, 0, 4000),
                'from' => $r->from_email, 'account' => Account::find($r->account_id)?->email, 'thread' => $r->thread_id, 'reply_id' => $r->id]);

            return;
        }
        $map = [
            'NOT_INTERESTED' => ['not_interested', 'mana kiya - follow-up band'],
            'UNSUBSCRIBE' => ['unsubscribe', 'unsubscribe - kabhi email nahi'],
            'WRONG_PERSON' => [null, 'galat aadmi - follow-up band'],
            'NOT_NOW' => [null, 'baad mein - follow-up band (PC vault mein)'],
            'OUT_OF_OFFICE' => [null, 'out-of-office - sequence chalta rahega'],
        ];
        [$sup, $why] = $map[$class] ?? [null, $class];
        if ($class === 'OUT_OF_OFFICE' && $lead && $lead->status === 'replied' && $lead->step < 3) {
            $lead->update(['status' => 'active', 'stop_reason' => null, 'next_send_at' => $lead->next_send_at ?? now()]); // OOO par sequence mat roko
        }
        if ($sup && $lead) {
            Suppression::add($lead->email, $sup);
            if ($sup === 'unsubscribe') {
                $lead->update(['status' => 'unsubscribed']);
            }
        }
        $r->update(['action' => $why]);
        Event::log($dom, 'reply', ['class' => $class, 'summary' => $r->summary, 'reply' => mb_substr((string) $r->body, 0, 4000), 'from' => $r->from_email]);
    }

    /** Positive lead ko 3-10 minute baad usi thread mein jawab: "our expert Mark will be in touch shortly" + Mark CC */
    public function sendHandoffs(): int
    {
        $n = 0;
        foreach (Reply::where('action', 'like', 'POSITIVE%')->whereNull('handoff_at')->limit(20)->get() as $r) {
            $due = Cache::get('handoff_due:'.$r->id, 0);
            if ($due > now()->timestamp) {
                continue;
            }
            $lead = Lead::find($r->lead_id);
            $a = Account::find($r->account_id);
            if (! $lead || ! $a) {
                continue;
            }
            $msgid = Mime::msgId($a->email);
            try {
                $send = Send::create(['lead_id' => $lead->id, 'account_id' => $a->id, 'step' => 99, 'rfc_msgid' => $msgid, 'send_day' => SendEngine::day()]);
            } catch (QueryException $e) {
                $r->update(['handoff_at' => now()]);

                continue; // pehle hi gaya
            }
            $first = trim((string) $lead->first_name) ?: 'there';
            $mark = config('sender.mark_name');
            $body = "Hi $first,\n\nThanks for getting back to me. Our expert $mark will be in touch shortly - I've copied $mark here so you have his direct email.\n\nReference: Lead #{$lead->lead_no}\n\n".Signature::for($a);
            try {
                $orig = $this->gmail->get($a->email, $r->gmail_id, 'metadata');
                $inReply = GmailClient::header($orig, 'Message-ID');
                $raw = Mime::build(['from' => $a->email, 'from_name' => $a->from_name, 'to' => $r->from_email ?: $lead->email, 'cc' => config('sender.mark_email'),
                    'subject' => 'Re: '.preg_replace('/^(re:\s*)+/i', '', (string) ($r->subject ?: $lead->subject)), 'body' => $body, 'msgid' => $msgid,
                    'in_reply_to' => $inReply, 'references' => trim($lead->first_msgid.' '.$inReply)]);
                $res = $this->gmail->send($a->email, $raw, $r->thread_id);
                $send->update(['status' => 'sent', 'gmail_id' => $res['id'] ?? null, 'thread_id' => $res['threadId'] ?? null, 'sent_at' => now()]);
                $r->update(['handoff_at' => now(), 'action' => 'POSITIVE - handoff gaya (Mark CC)']);
                Event::log($lead->domain, 'handoff', ['lead_no' => $lead->lead_no, 'cc' => config('sender.mark_email')]);
                $n++;
            } catch (Throwable $e) {
                $send->delete();
                Log::warning('handoff fail', ['e' => $e->getMessage()]);
            }
        }

        return $n;
    }
}
