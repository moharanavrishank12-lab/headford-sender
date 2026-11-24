<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Lead;
use App\Models\Reply;
use App\Models\Send;

class Stats
{
    public static function summary(): array
    {
        $eng = app(SendEngine::class);
        $day = SendEngine::day();
        $accs = Account::orderBy('email')->get()->map(function ($a) use ($eng, $day) {
            $since = now()->subDays(7);
            $sent7 = Send::where('account_id', $a->id)->where('status', 'sent')->whereBetween('step', [1, 3])->where('sent_at', '>=', $since)->count();
            $b7 = Reply::where('account_id', $a->id)->where('kind', 'bounce')->whereNotNull('lead_id')->where('created_at', '>=', $since)->count();

            return ['id' => $a->id, 'email' => $a->email, 'name' => $a->from_name, 'status' => $a->status, 'paused_reason' => $a->paused_reason,
                'cap' => $eng->capToday($a), 'today' => $eng->usedToday($a), 'sent7' => $sent7, 'bounce7' => $b7,
                'bounce_pct' => $sent7 ? round($b7 * 100 / $sent7, 1) : 0, 'last_sent' => optional($a->last_sent_at)->diffForHumans(), 'error' => $a->last_error,
                'checked' => optional($a->last_checked_at)->diffForHumans()];
        });
        $capDay = $accs->where('status', 'active')->sum('cap');
        $newPerDay = max(1, (int) floor($capDay / 3)); // har lead = 1 email + 2 follow-up
        $fresh = now()->subDays(config('sender.reverify_days'));
        $stock = Lead::where('status', 'queued')->where('verified_at', '>=', $fresh)->count();

        return [
            'accounts' => $accs, 'active_ids' => $accs->where('status', 'active')->count(), 'cap_day' => $capDay, 'new_per_day' => $newPerDay,
            'stock' => $stock, 'stock_days' => round($stock / $newPerDay, 1),
            'sent_today' => Send::where('send_day', $day)->where('status', 'sent')->whereBetween('step', [1, 3])->count(),
            'new_today' => Send::where('send_day', $day)->where('status', 'sent')->where('step', 1)->count(),
            'replies_today' => Reply::where('kind', 'reply')->where('created_at', '>=', now()->startOfDay())->count(),
            'positive_total' => Lead::whereNotNull('lead_no')->count(),
            'review' => Reply::where('action', 'like', 'HAATH SE%')->count(),
            'by_status' => Lead::selectRaw('status, count(*) n')->groupBy('status')->pluck('n', 'status')->all(),
            'stuck_reserved' => Send::where('status', 'reserved')->where('created_at', '<', now()->subMinutes(30))->count(),
        ];
    }
}
