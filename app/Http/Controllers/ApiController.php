<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Lead;
use App\Models\Suppression;
use App\Services\Stats;
use Illuminate\Http\Request;

/** PC (Lead Manager) yahan se baat karta hai */
class ApiController extends Controller
{
    /** Ready leads upload. Duplicate email/domain aur do-not-email wale yahin ruk jaate hain. */
    public function leads(Request $r)
    {
        $out = ['added' => 0, 'refreshed' => 0, 'duplicate' => 0, 'suppressed' => 0, 'invalid' => 0, 'items' => []];
        foreach (array_slice((array) $r->input('leads', []), 0, 2000) as $x) {
            $email = strtolower(trim((string) ($x['email'] ?? '')));
            $domain = strtolower(preg_replace('/^www\./', '', trim((string) ($x['domain'] ?? ''))));
            $res = 'ok';
            if (! filter_var($email, FILTER_VALIDATE_EMAIL) || ! $domain || empty($x['subject']) || empty($x['body1']) || empty($x['body2']) || empty($x['body3']) || empty($x['verified_at'])) {
                $res = 'invalid';
            } elseif ($why = Suppression::blocked($email, $domain)) {
                $res = 'suppressed';
            } else {
                $ex = Lead::where('domain', $domain)->orWhere('email', $email)->first();
                if ($ex && $ex->status === 'stale' && $ex->domain === $domain) {
                    // PC ne dobara verify kiya - wapas line mein
                    $ex->update(['email' => $email, 'verified_at' => $x['verified_at'], 'status' => 'queued', 'account_id' => null, 'stop_reason' => null]);
                    $res = 'refreshed';
                } elseif ($ex) {
                    $res = 'duplicate';
                } else {
                    Lead::create([
                        'domain' => $domain, 'email' => $email, 'first_name' => $x['first_name'] ?? null, 'last_name' => $x['last_name'] ?? null,
                        'company' => $x['company'] ?? null, 'website' => $x['website'] ?? null, 'grp' => $x['grp'] ?? null,
                        'zone' => in_array($x['zone'] ?? 'E', ['E', 'W'], true) ? ($x['zone'] ?? 'E') : 'E',
                        'subject' => $x['subject'], 'body1' => $x['body1'], 'body2' => $x['body2'], 'body3' => $x['body3'],
                        'verified_at' => $x['verified_at'], 'received_at' => now(),
                    ]);
                    $res = 'added';
                }
            }
            $out[$res === 'ok' ? 'added' : $res]++;
            $out['items'][] = ['domain' => $domain, 'result' => $res];
        }

        return response()->json($out);
    }

    /** PC ke liye: naye events (sent, reply, positive, bounce...) + cloud ki nayi suppressions + stock */
    public function sync(Request $r)
    {
        $since = (int) $r->query('since', 0);
        $supSince = $r->query('sup_since');
        $events = Event::where('id', '>', $since)->orderBy('id')->limit(2000)->get(['id', 'domain', 'type', 'detail', 'created_at']);
        $sups = Suppression::where('source', 'cloud')->when($supSince, fn ($q) => $q->where('updated_at', '>', $supSince))->orderBy('updated_at')->limit(5000)->get(['value', 'kind', 'reason', 'updated_at']);
        $s = Stats::summary();

        return response()->json(['events' => $events, 'suppressions' => $sups, 'server_time' => now()->toIso8601String(),
            'stock' => $s['stock'], 'stock_days' => $s['stock_days'], 'new_per_day' => $s['new_per_day'], 'active_ids' => $s['active_ids']]);
    }

    /** PC ki suppressions (past clients, unsubscribe, bounce, do-not-email) */
    public function suppressions(Request $r)
    {
        $n = 0;
        foreach (array_slice((array) $r->input('items', []), 0, 20000) as $x) {
            $v = strtolower(trim((string) ($x['value'] ?? '')));
            if ($v === '') {
                continue;
            }
            $ex = Suppression::where('value', $v)->first();
            if (! $ex) {
                Suppression::add($v, substr((string) ($x['reason'] ?? 'manual'), 0, 30), 'pc');
                $n++;
            }
            // queued/active lead turant roko
            Lead::whereIn('status', ['queued', 'active'])->where(fn ($q) => $q->where('email', $v)->orWhere('domain', $v))
                ->update(['status' => 'suppressed', 'stop_reason' => 'PC ki do-not-email list', 'next_send_at' => null]);
        }

        return response()->json(['added' => $n]);
    }
}
