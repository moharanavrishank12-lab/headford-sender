<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Lead;
use App\Models\Reply;
use App\Models\Setting;
use App\Models\Suppression;
use App\Models\Event;
use App\Services\Alerts;
use App\Services\ReplyEngine;
use App\Services\Signature;
use App\Services\Stats;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class DashboardController extends Controller
{
    public function loginForm()
    {
        return view('login');
    }

    public function login(Request $r)
    {
        $key = 'login:'.$r->ip();
        if (RateLimiter::tooManyAttempts($key, 8)) {
            return back()->with('err', 'Bahut galat try - 1 minute ruko');
        }
        $pw = (string) config('sender.dashboard_password');
        if (strlen($pw) >= 8 && hash_equals($pw, (string) $r->input('password'))) {
            $r->session()->regenerate();
            $r->session()->put('hs_ok', true);

            return redirect('/');
        }
        RateLimiter::hit($key, 60);

        return back()->with('err', 'Password galat');
    }

    public function logout(Request $r)
    {
        $r->session()->flush();

        return redirect('/login');
    }

    public function home()
    {
        $s = Stats::summary();
        if ($s['stock_days'] < config('sender.stock_days')) {
            Alerts::raise('stock', "Cloud mein sirf {$s['stock_days']} din ke leads bache ({$s['stock']}). PC se aur ready leads bhejo.");
        } else {
            Alerts::clear('stock');
        }

        return view('home', [
            's' => $s, 'alerts' => Alerts::all(),
            'review' => Reply::where('action', 'like', 'HAATH SE%')->latest()->limit(30)->get(),
            'replies' => Reply::where('kind', 'reply')->latest()->limit(30)->get(),
            'positives' => Lead::whereNotNull('lead_no')->orderByDesc('lead_no')->limit(20)->get(),
            'gkey' => is_file(config('sender.google_sa_json')) ? (json_decode((string) file_get_contents(config('sender.google_sa_json')), true)['client_id'] ?? 'hai') : null,
            'settings' => ['company_line' => Setting::get('company_line', 'HeadFord'), 'address' => Setting::get('address', '')],
        ]);
    }

    public function account(Request $r, Account $account)
    {
        $act = $r->input('do');
        if ($act === 'pause') {
            $account->update(['status' => 'paused', 'paused_reason' => 'dashboard se roka']);
        } elseif ($act === 'resume') {
            $account->update(['status' => 'active', 'paused_reason' => null, 'last_error' => null]);
            Alerts::clear('bounce_'.$account->id);
        } elseif ($act === 'cap') {
            $account->update(['daily_cap' => max(1, min(50, (int) $r->input('cap')))]);
        } elseif ($act === 'remove') {
            $account->delete();
        }

        return back();
    }

    public function addAccounts(Request $r)
    {
        $n = 0;
        foreach (preg_split('/\r?\n/', (string) $r->input('lines')) as $line) {
            $p = array_map('trim', preg_split('/[,\t]/', $line, 2));
            if (! filter_var($p[0] ?? '', FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            Account::firstOrCreate(['email' => strtolower($p[0])], ['from_name' => $p[1] ?? '', 'ramp_start' => now('America/New_York')->toDateString(), 'daily_cap' => config('sender.daily_cap')]);
            $n++;
        }

        return back()->with('msg', "$n IDs jodi/pehle se thi");
    }

    public function settings(Request $r)
    {
        Setting::put('company_line', trim((string) $r->input('company_line')));
        Setting::put('address', trim((string) $r->input('address')));

        return back()->with('msg', 'Signature settings save');
    }

    public function decide(Request $r, Reply $reply)
    {
        $c = $r->input('class');
        if (in_array($c, ['INTERESTED', 'NOT_INTERESTED', 'NOT_NOW', 'UNSUBSCRIBE', 'WRONG_PERSON'], true)) {
            app(ReplyEngine::class)->decide($reply, $c);
        }

        return back();
    }

    // ---- Unsubscribe (email ke header se, ek click) ----
    public function unsubForm(int $id, string $token)
    {
        abort_unless(hash_equals(Signature::token($id), $token), 404);

        return response('<html><body style="font-family:sans-serif;max-width:480px;margin:60px auto"><h3>Unsubscribe</h3><form method="post"><input type="hidden" name="_token" value="'.csrf_token().'"><button style="padding:10px 18px">Yes, unsubscribe me</button></form></body></html>');
    }

    public function unsub(int $id, string $token)
    {
        abort_unless(hash_equals(Signature::token($id), $token), 404);
        if ($lead = Lead::find($id)) {
            Suppression::add($lead->email, 'unsubscribe');
            $lead->update(['status' => 'unsubscribed', 'next_send_at' => null, 'stop_reason' => 'unsubscribe link']);
            Event::log($lead->domain, 'reply', ['class' => 'UNSUBSCRIBE', 'summary' => 'unsubscribe link dabaya']);
        }

        return response('<html><body style="font-family:sans-serif;max-width:480px;margin:60px auto">You have been unsubscribed. You will not hear from us again.</body></html>');
    }

    public function googleKey(Request $r)
    {
        $f = $r->file('key');
        if (! $f || $f->getSize() > 20000) {
            return back()->with('msg', 'JSON file chuno (Google Cloud se download ki hui).');
        }
        $j = json_decode((string) file_get_contents($f->getRealPath()), true);
        if (! is_array($j) || ($j['type'] ?? '') !== 'service_account' || empty($j['client_email']) || empty($j['private_key'])) {
            return back()->with('msg', 'Yeh service account key nahi lagti. Google Cloud > Service account > Keys > JSON wali file chahiye.');
        }
        $path = config('sender.google_sa_json');
        @mkdir(dirname($path), 0700, true);
        file_put_contents($path, json_encode($j));
        @chmod($path, 0600);

        return back()->with('msg', 'Google key save ho gayi: '.$j['client_email'].' (Client ID: '.($j['client_id'] ?? '?').'). Ab "Sab IDs check karo" dabao.');
    }

    public function check()
    {
        \Illuminate\Support\Facades\Artisan::call('sender:check');

        return back()->with('msg', trim(\Illuminate\Support\Facades\Artisan::output()));
    }
}
