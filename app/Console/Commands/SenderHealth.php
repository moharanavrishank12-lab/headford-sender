<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Event;
use App\Models\Setting;
use App\Services\Alerts;
use App\Services\GmailClient;
use App\Services\Mime;
use App\Services\Stats;
use Illuminate\Console\Command;
use Throwable;

class SenderHealth extends Command
{
    protected $signature = 'sender:health {--print : sirf screen par, email nahi}';

    protected $description = 'Roz ki health report email (jiten.headford@gmail.com)';

    public function handle(GmailClient $g): int
    {
        $s = Stats::summary();
        if ($s['stock_days'] < config('sender.stock_days')) {
            Alerts::raise('stock', "Cloud mein sirf {$s['stock_days']} din ke leads bache ({$s['stock']}). PC se aur ready leads bhejo.");
        }
        foreach (['beat_tick' => 5, 'beat_replies' => 20] as $k => $mins) {
            $b = Setting::get($k);
            if (! $b || now()->diffInMinutes(\Carbon\Carbon::parse($b), true) > $mins) {
                Alerts::raise('cron_'.$k, 'Cron nahi chal raha ('.$k.' aakhri: '.($b ?: 'kabhi nahi').') - Cloudways Cron Job dekho.');
            } else {
                Alerts::clear('cron_'.$k);
            }
        }
        $y = now()->subDay();
        $ev = Event::where('created_at', '>=', $y)->selectRaw('type, count(*) n')->groupBy('type')->pluck('n', 'type')->all();
        $L = ['HeadFord Sender - roz ki report ('.now('Asia/Kolkata')->format('d M Y').')', ''];
        $L[] = 'Pichhle 24 ghante: bheje '.($ev['sent'] ?? 0).', positive '.($ev['positive'] ?? 0).', replies '.($ev['reply'] ?? 0).', bounce '.($ev['bounce'] ?? 0).', haath se dekhna '.($ev['review'] ?? 0);
        $L[] = "Chalu IDs: {$s['active_ids']} / ".count($s['accounts']).", roz ki kul seema {$s['cap_day']} (follow-up milake), naye leads ~{$s['new_per_day']}/din";
        $L[] = "Stock: {$s['stock']} leads = {$s['stock_days']} din ka";
        $L[] = '';
        $alerts = Alerts::all();
        $L[] = $alerts ? 'DHYAN DO:' : 'Koi dikkat nahi.';
        foreach ($alerts as $a) {
            $L[] = ' - '.$a['msg'];
        }
        $L[] = '';
        $L[] = 'IDs:';
        foreach ($s['accounts'] as $a) {
            $L[] = sprintf(' %s  %s  aaj %d/%d  7din %d bheje, bounce %.1f%%%s', $a['status'] === 'active' ? 'OK ' : 'RUKI', $a['email'], $a['today'], $a['cap'], $a['sent7'], $a['bounce_pct'], $a['paused_reason'] ? '  ('.$a['paused_reason'].')' : '');
        }
        $text = implode("\n", $L);
        if ($this->option('print')) {
            $this->line($text);

            return 0;
        }
        $from = Account::where('status', 'active')->orderBy('id')->first() ?? Account::orderBy('id')->first();
        if (! $from) {
            $this->warn('Koi ID nahi - report nahi bheji');

            return 0;
        }
        try {
            $g->send($from->email, Mime::build(['from' => $from->email, 'from_name' => 'HeadFord Sender', 'to' => config('sender.report_email'),
                'subject' => 'Sender report: '.($ev['sent'] ?? 0).' bheje, '.($ev['positive'] ?? 0).' positive'.($alerts ? ' - '.count($alerts).' dikkat' : ''),
                'body' => $text, 'msgid' => Mime::msgId($from->email)]));
            $this->info('Report bheji: '.config('sender.report_email'));
        } catch (Throwable $e) {
            $this->error('Report nahi gayi: '.$e->getMessage());
        }

        return 0;
    }
}
