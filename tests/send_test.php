<?php
require __DIR__.'/boot.php'; require __DIR__.'/FakeGmail.php';
use App\Models\{Account, Lead, Send, Suppression, Event}; use App\Services\SendEngine; use Carbon\Carbon; use Carbon\CarbonImmutable;
App\Models\Setting::put('address', '');
$g = new FakeGmail(); app()->instance(App\Services\GmailClient::class, $g);
$eng = app(SendEngine::class);
// Tue 24 Nov 2026, 8:30 AM ET = East window khula
Carbon::setTestNow('2026-11-24 13:30:00'); CarbonImmutable::setTestNow('2026-11-24 13:30:00');
$a1 = Account::create(['email' => 'anna@cold1.com', 'from_name' => 'Anna Lee', 'ramp_start' => '2026-10-01']);
$a2 = Account::create(['email' => 'ben@cold2.com', 'from_name' => 'Ben Ray', 'ramp_start' => '2026-11-24']); // naya: cap 5
$mk = fn ($d, $z = 'E', $g = 'B', $v = '2026-11-20') => Lead::create(['domain' => $d, 'email' => "owner@$d", 'first_name' => 'Sam', 'subject' => "Quick idea for $d", 'body1' => "Hi Sam,\nbody1", 'body2' => 'fu1', 'body3' => 'fu2', 'zone' => $z, 'grp' => $g, 'verified_at' => $v]);
for ($i = 1; $i <= 30; $i++) $mk("shop$i.com");
$mk('west1.com', 'W'); $mk('old1.com', 'E', 'A', '2026-09-01'); $mk('blocked1.com', 'E', 'A');
Suppression::add('blocked1.com', 'unsubscribe', 'pc');
$r = $eng->tick();
ok($r['sent'] === 0 && ($r['note'] ?? '') === 'address nahi' && isset(App\Services\Alerts::all()['address']), 'address nahi = koi email nahi + alert');
App\Models\Setting::put('address', '123 Test St, Brooklyn, NY');
$r = $eng->tick();
ok($r['sent'] === 2, 'pehla tick: dono IDs ne 1-1 bheja');
ok(Lead::where('domain', 'old1.com')->value('status') === 'stale', '30 din purana verify = stale (bheja nahi)');
ok(Lead::where('domain', 'blocked1.com')->value('status') === 'suppressed', 'suppression list wala domain = nahi bheja');
ok(Lead::where('domain', 'west1.com')->value('status') === 'queued', 'West lead East ke subah nahi gaya');
$first = Lead::whereNotNull('thread_id')->orderBy('id')->first();
ok($first->step === 1 && $first->status === 'active' && $first->next_send_at->toDateTimeString() === '2026-12-01 13:00:00', 'step 1 ke baad follow-up 3 kaam ke din baad (holiday skip)');
ok(str_contains($g->sent[0]['raw'], "Anna Lee\r\nHeadFord") && str_contains($g->sent[0]['raw'], 'Subject: Quick idea'), 'signature + subject');
$r = $eng->tick(); ok($r['sent'] === 0, 'turant dobara tick = pacing, kuch nahi');
// 20 minute aage chalao bar bar - cap tak
for ($m = 0; $m < 160; $m += 1) { Carbon::setTestNow(Carbon::parse('2026-11-24 13:30')->addMinutes($m)); CarbonImmutable::setTestNow(Carbon::now()); $eng->tick(); }
ok($eng->usedToday($a2) === 5, 'nayi ID (ramp day 1) = sirf 5');
ok($eng->usedToday($a1) <= 20 && $eng->usedToday($a1) >= 15, 'purani ID 20 tak (mila '.$eng->usedToday($a1).')');
ok(Calendar::class ?? true, '');
// Follow-up: 1 Dec 8:00 ET
Carbon::setTestNow('2026-12-01 13:05:00'); CarbonImmutable::setTestNow('2026-12-01 13:05:00');
$a1->update(['last_sent_at' => null]); $a2->update(['last_sent_at' => null]);
$before = count($g->sent); $eng->tick();
$fu = array_slice($g->sent, $before);
$fuLead = Lead::where('step', 2)->first();
ok($fuLead && $fuLead->account_id && $g->sent[$before]['user'] === Account::find($fuLead->account_id)->email, 'follow-up usi ID se');
ok($fuLead && $fu[0]['threadId'] === $fuLead->thread_id && str_contains($fu[0]['raw'], 'In-Reply-To: '.$fuLead->first_msgid) && str_contains($fu[0]['raw'], 'Subject: Re: Quick idea'), 'follow-up usi thread mein (In-Reply-To + Re:)');
// Double send: same lead same step dobara
$l = Lead::where('step', 2)->first();
ok($eng->sendStep(Account::find($l->account_id), $l, 2) === 'skip', 'same step dobara = skip (double-send nahi)');
// Crash: timeout ke baad reserved -> Gmail mein mila -> sent
$l2 = Lead::where('status', 'queued')->where('zone', 'E')->first();
$a1->refresh(); $l2->update(['account_id' => $a1->id, 'status' => 'active', 'next_send_at' => now()]);
$g->failNext = 'Gmail post /messages/send: HTTP 503 timeout';
try { $eng->sendStep($a1, $l2, 1); } catch (Throwable $e) {}
ok(Send::where('lead_id', $l2->id)->value('status') === 'reserved', 'timeout = reserved (shak)');
// Maan lo asal mein Gmail ne bhej diya tha
$g->sent[] = ['user' => $a1->email, 'raw' => 'x', 'id' => 'gX', 'threadId' => 'tX', 'msgid' => Send::where('lead_id', $l2->id)->value('rfc_msgid')];
Carbon::setTestNow(Carbon::now()->addMinutes(10)); CarbonImmutable::setTestNow(Carbon::now());
$eng->recover();
ok(Send::where('lead_id', $l2->id)->value('status') === 'sent' && Lead::find($l2->id)->step === 1, 'recover: Gmail Sent mein mila = sent (dobara nahi bheja)');
// 400 = lead failed
$l3 = Lead::where('status', 'queued')->where('zone', 'E')->first(); $l3->update(['account_id' => $a1->id, 'status' => 'active', 'next_send_at' => now()]);
$g->failNext = 'Gmail post: HTTP 400 Invalid To header';
try { $eng->sendStep($a1, $l3, 1); } catch (Throwable $e) {}
ok(Lead::find($l3->id)->status === 'failed' && ! Send::where('lead_id', $l3->id)->exists(), '400 = lead failed, reservation hati');
// 403 = account paused
$l4 = Lead::where('status', 'queued')->where('zone', 'E')->first(); $l4->update(['account_id' => $a2->id, 'status' => 'active', 'next_send_at' => now()]);
$g->failNext = 'Gmail post: HTTP 403 Delegation denied';
try { $eng->sendStep($a2, $l4, 1); } catch (Throwable $e) {}
ok($a2->fresh()->status === 'paused', '403 = ID paused');
// Step 3 ke baad done
$l5 = Lead::where('step', 2)->first(); $a = Account::find($l5->account_id);
$l5->update(['next_send_at' => now()]); $eng->sendStep($a, $l5, 3);
ok($l5->fresh()->status === 'done', '3 emails ke baad done');
ok(Event::where('type', 'sent')->count() > 10, 'PC ke liye events bane');
done();
