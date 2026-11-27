<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\ReplyEngine;
use Illuminate\Console\Command;

class SenderReplies extends Command
{
    protected $signature = 'sender:replies';

    protected $description = 'Har 5 minute: saari IDs ke inbox padho (reply, bounce, out-of-office)';

    public function handle(ReplyEngine $rep): int
    {
        Setting::put('beat_replies', now()->toIso8601String());
        $c = $rep->pollAll();
        if (array_sum($c)) {
            $this->info(now()->format('H:i').' '.json_encode($c));
        }

        return 0;
    }
}
