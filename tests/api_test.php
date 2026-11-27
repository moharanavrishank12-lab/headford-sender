<?php
require __DIR__.'/boot.php'; require __DIR__.'/FakeGmail.php';
use App\Models\{Account, Lead, Suppression, Event, Reply}; use Illuminate\Http\Request;
config(['sender.api_token' => str_repeat('t', 32), 'sender.dashboard_password' => 'secret-pass-1', 'app.url' => 'https://sender.example.com']);
$g = new FakeGmail(); app()->instance(App\Services\GmailClient::class, $g);
$kernel = app(Illuminate\Contracts\Http\Kernel::class);
$call = function ($m, $uri, $data = [], $tok = true, $cookies = []) use ($kernel) {
    $r = Request::create($uri, $m, [], $cookies, [], array_filter(['HTTP_AUTHORIZATION' => $tok ? 'Bearer '.str_repeat('t', 32) : null, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json']), $data ? json_encode($data) : null);
    $res = $kernel->handle($r); return $res;
};
ok($call('GET', '/api/ping', [], false)->getStatusCode() === 401, 'API bina token = 401');
$lead = fn ($d, $e = null, $v = '2026-11-20') => ['domain' => $d, 'email' => $e ?? "o@$d", 'first_name' => 'Sam', 'subject' => 's', 'body1' => 'b1', 'body2' => 'b2', 'body3' => 'b3', 'zone' => 'W', 'grp' => 'A', 'verified_at' => $v];
Suppression::add('pastclient.com', 'past_client', 'pc');
$res = json_decode($call('POST', '/api/leads', ['leads' => [$lead('a.com'), $lead('www.b.com'), $lead('a.com', 'x@a.com'), $lead('c.com', 'o@a.com'), $lead('pastclient.com'), ['domain' => 'bad.com']]])->getContent(), true);
ok($res['added'] === 2 && $res['duplicate'] === 2 && $res['suppressed'] === 1 && $res['invalid'] === 1, 'upload: 2 naye, 2 duplicate (same domain / same email), 1 suppressed, 1 kharab '.json_encode(array_diff_key($res, ['items' => 1])));
ok(Lead::where('domain', 'b.com')->exists(), 'www. hata ke domain');
Lead::where('domain', 'a.com')->update(['status' => 'stale']);
$res = json_decode($call('POST', '/api/leads', ['leads' => [$lead('a.com', null, '2026-11-24')]])->getContent(), true);
ok($res['refreshed'] === 1 && Lead::where('domain', 'a.com')->value('status') === 'queued', 'stale lead dobara verify hoke wapas line mein');
$res = json_decode($call('POST', '/api/suppressions', ['items' => [['value' => 'b.com', 'reason' => 'past_client']]])->getContent(), true);
ok($res['added'] === 1 && Lead::where('domain', 'b.com')->value('status') === 'suppressed', 'PC suppression = queued lead turant ruka');
Event::log('a.com', 'positive', ['lead_no' => 1]); Suppression::add('z@z.com', 'bounce');
$sy = json_decode($call('GET', '/api/sync?since=0')->getContent(), true);
ok(count($sy['events']) >= 1 && collect($sy['suppressions'])->pluck('value')->contains('z@z.com') && ! collect($sy['suppressions'])->pluck('value')->contains('b.com'), 'sync: events + sirf cloud ki suppressions');
// unsubscribe link
$id = Lead::where('domain', 'a.com')->value('id'); $tok = App\Services\Signature::token($id);
ok($call('POST', "/u/$id/$tok", [], false)->getStatusCode() === 200 && Lead::find($id)->status === 'unsubscribed' && Suppression::blocked('o@a.com') === 'unsubscribe', 'ek-click unsubscribe');
ok($call('POST', "/u/$id/wrongtoken", [], false)->getStatusCode() === 404, 'galat unsubscribe token = 404');
ok(str_contains(App\Services\Mime::build(['from' => 'a@x.com', 'to' => 'b@y.com', 'subject' => 's', 'body' => 'b', 'msgid' => '<1@x>', 'unsub_url' => App\Services\Signature::unsubUrl(5)]), 'List-Unsubscribe-Post: List-Unsubscribe=One-Click'), 'List-Unsubscribe header');
// dashboard
$r = $call('GET', '/', [], false); ok($r->getStatusCode() === 302, 'dashboard bina login = login page');
$sess = app('session.store'); $sess->start(); $sess->put('hs_ok', true); $sess->save();
$r = Request::create('/', 'GET', [], [config('session.cookie') => $sess->getId()]);
$app = app(); $app['session']->driver()->setId($sess->getId());
Account::create(['email' => 'anna@cold1.com', 'from_name' => 'Anna', 'status' => 'active', 'daily_cap' => 20, 'ramp_start' => '2026-10-01']);
Reply::create(['gmail_id' => 'q1', 'kind' => 'reply', 'from_email' => 'x@y.com', 'body' => 'who?', 'action' => 'HAATH SE DEKHO - samajh nahi aaya', 'class' => 'UNCLEAR']);
$html = view('home', ['s' => App\Services\Stats::summary(), 'alerts' => ['stock' => ['msg' => 'Stock kam', 'at' => now()->toIso8601String()]], 'review' => Reply::all(), 'replies' => Reply::all(), 'positives' => Lead::whereNotNull('lead_no')->get(), 'gkey' => null, 'settings' => ['company_line' => 'HeadFord', 'address' => '']])->render();
ok(str_contains($html, 'anna@cold1.com') && str_contains($html, 'Haath se dekho') && str_contains($html, 'Stock kam'), 'dashboard page banta hai');
ok(str_contains(view('login')->render(), 'Password'), 'login page');
// health + backup
Illuminate\Support\Facades\Artisan::call('sender:health', ['--print' => true]); $out = Illuminate\Support\Facades\Artisan::output();
ok(str_contains($out, 'roz ki report') && str_contains($out, 'anna@cold1.com'), 'health report text');
Illuminate\Support\Facades\Artisan::call('sender:health'); ok(count($g->sent) === 1 && str_contains($g->sent[0]['raw'], 'To: jiten.headford@gmail.com'), 'health report email gaya');
Illuminate\Support\Facades\Artisan::call('sender:backup'); ok(str_contains(Illuminate\Support\Facades\Artisan::output(), 'Backup: sender-'), 'backup bana');
// health: fresh tick => scheduler_ok
App\Models\Setting::put('beat_tick', now()->toIso8601String());
$h = json_decode($call('GET', '/api/health')->getContent(), true);
ok($h['scheduler_ok'] === true && isset($h['mins_since_tick']), 'health: fresh tick = scheduler ok');
// health: stale tick => scheduler band
App\Models\Setting::put('beat_tick', now()->subMinutes(30)->toIso8601String());
$h2 = json_decode($call('GET', '/api/health')->getContent(), true);
ok($h2['scheduler_ok'] === false, 'health: 30-min purana tick = scheduler band');
// backup/latest: koi na ho to bana ke 200 bheje
$bk = $call('GET', '/api/backup/latest');
ok($bk->getStatusCode() === 200, 'backup/latest: 200 (backup bana ke bheja)');

done();
