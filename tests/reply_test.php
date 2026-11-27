<?php
require __DIR__.'/boot.php'; require __DIR__.'/FakeGmail.php';
use App\Models\{Account, Lead, Send, Suppression, Event, Reply}; use App\Services\{SendEngine, ReplyEngine}; use Carbon\Carbon; use Carbon\CarbonImmutable;
config(['sender.claude_key' => null]);
$g = new FakeGmail(); app()->instance(App\Services\GmailClient::class, $g);
$eng = app(SendEngine::class); $re = app(ReplyEngine::class);
Carbon::setTestNow('2026-11-24 13:30:00'); CarbonImmutable::setTestNow('2026-11-24 13:30:00');
$a = Account::create(['email' => 'anna@cold1.com', 'from_name' => 'Anna Lee', 'ramp_start' => '2026-10-01', 'status' => 'active', 'daily_cap' => 20]);
$leads = [];
foreach (['yes', 'no', 'unsub', 'ooo', 'bounce', 'hmm'] as $i => $k) {
    $l = Lead::create(['domain' => "$k.com", 'email' => "o@$k.com", 'first_name' => 'Sam', 'subject' => "Idea for $k", 'body1' => 'b1', 'body2' => 'b2', 'body3' => 'b3', 'zone' => 'E', 'verified_at' => '2026-11-20', 'status' => 'active', 'account_id' => $a->id, 'next_send_at' => now()]);
    $eng->sendStep($a, $l, 1); $leads[$k] = $l->fresh();
}
$msg = function ($id, $lead, $from, $subj, $body, $hdr = []) use ($a) {
    $h = [['name' => 'From', 'value' => $from], ['name' => 'Subject', 'value' => $subj], ['name' => 'Message-ID', 'value' => "<$id@x>"]];
    foreach ($hdr as $k => $v) $h[] = ['name' => $k, 'value' => $v];
    return ['user' => $a->email, 'id' => $id, 'threadId' => $lead ? $lead->thread_id : 'warm'.$id, 'internalDate' => (string) (time() * 1000),
        'payload' => ['mimeType' => 'text/plain', 'headers' => $h, 'body' => ['data' => rtrim(strtr(base64_encode($body), '+/', '-_'), '=')]]];
};
$g->inbox = [
    $msg('r1', $leads['yes'], 'Sam <o@yes.com>', 'Re: Idea for yes', "Yes, how much would this cost?\n\nOn Mon, Anna wrote:\n> b1"),
    $msg('r2', $leads['no'], 'o@no.com', 'Re: Idea for no', 'No thanks, we are good.'),
    $msg('r3', $leads['unsub'], 'o@unsub.com', 'Re: Idea', 'Please remove me from your list'),
    $msg('r4', $leads['ooo'], 'o@ooo.com', 'Automatic reply: Idea', 'I am out of the office until Monday', ['Auto-Submitted' => 'auto-replied']),
    $msg('r5', null, 'Mail Delivery Subsystem <mailer-daemon@googlemail.com>', 'Delivery Status Notification (Failure)', "Address not found\nYour message wasn't delivered to o@bounce.com because the address couldn't be found", ['X-Failed-Recipients' => 'o@bounce.com']),
    $msg('r6', null, 'Warm Buddy <x@warmup.io>', 'Thanks for the update k3p9', 'Instantly warmup mail'),
    $msg('r7', $leads['hmm'], 'o@hmm.com', 'Re: Idea', 'Who is this?'),
];
$c = $re->pollAll();
ok($c['replies'] === 4 && $c['bounces'] === 1 && $c['ooo'] === 1, 'gine: 4 reply, 1 bounce, 1 ooo (warmup chhoda) '.json_encode($c));
ok(! Reply::where('gmail_id', 'r6')->exists(), 'Instantly warmup mail reply nahi maana');
ok($leads['yes']->fresh()->status === 'positive' && $leads['yes']->fresh()->lead_no === 1, 'YES = positive, Lead #1');
ok(Lead::where('domain', 'no.com')->value('status') === 'replied' && Suppression::blocked('o@no.com'), 'NO = follow-up band + suppression');
ok(Lead::where('domain', 'unsub.com')->value('status') === 'unsubscribed' && Suppression::blocked('o@unsub.com') === 'unsubscribe', 'unsubscribe');
ok(Lead::where('domain', 'ooo.com')->value('status') === 'active', 'out-of-office = sequence chalta rahe');
ok(Lead::where('domain', 'bounce.com')->value('status') === 'bounced' && Suppression::blocked('o@bounce.com') === 'bounce', 'bounce pakda (thread ke bina bhi)');
ok(Reply::where('gmail_id', 'r7')->value('action') === 'HAATH SE DEKHO - samajh nahi aaya' && Lead::where('domain', 'hmm.com')->value('status') === 'review', 'samajh na aaye = haath se dekho, follow-up band, Mark ko nahi');
// handoff
Carbon::setTestNow(Carbon::now()->addMinutes(11)); CarbonImmutable::setTestNow(Carbon::now());
$before = count($g->sent);
ok($re->sendHandoffs() === 1, 'handoff gaya');
$h = $g->sent[$before]['raw'] ?? '';
ok(str_contains($h, 'Cc: mark@headfordtechnology.biz') && str_contains($h, 'Our expert Mark will be in touch shortly') && str_contains($h, 'Lead #1') && $g->sent[$before]['threadId'] === $leads['yes']->thread_id, 'handoff: Mark CC, usi thread, Lead #1');
ok($re->sendHandoffs() === 0, 'handoff dobara nahi');
ok(Event::where('type', 'positive')->count() === 1, 'PC ke liye positive event');
// dobara poll = kuch naya nahi
ok(array_sum($re->pollAll()) === 0, 'dobara padhne par wahi mail dobara nahi');
// bounce guard: 30 sent, 2 bounces = ~6.7% -> pause
for ($i = 0; $i < 30; $i++) { $l = Lead::create(['domain' => "b$i.com", 'email' => "x@b$i.com", 'subject' => 's', 'body1' => 'b', 'body2' => 'b', 'body3' => 'b', 'verified_at' => now(), 'status' => 'active', 'account_id' => $a->id]); $eng->sendStep($a, $l, 1); }
Reply::create(['gmail_id' => 'bx', 'lead_id' => 8, 'account_id' => $a->id, 'kind' => 'bounce']);
$re->bounceGuard($a->fresh());
ok($a->fresh()->status === 'paused' && str_contains($a->fresh()->paused_reason, 'bounce'), 'bounce 3% se upar = ID auto-pause ('.$a->fresh()->paused_reason.')');
$re->decide(Reply::where('gmail_id', 'r7')->first(), 'INTERESTED'); ok(Lead::where('domain', 'hmm.com')->value('lead_no') === 2, 'dashboard se Positive dabaya = Lead #2');
done();
