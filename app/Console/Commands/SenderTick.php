<?php

namespace App\Console\Commands;

use App\Services\ReplyEngine;
use App\Services\SendEngine;
use App\Models\Setting;
use Illuminate\Console\Command;

class SenderTick extends Command
{
    protected $signature = 'sender:tick';

    protected $description = 'Har minute: atke email pakke karo, bhejo, positive handoff';

    public function handle(SendEngine $send, ReplyEngine $rep): int
    {
        Setting::put('beat_tick', now()->toIso8601String());
        $rec = $send->recover();
        $r = $send->tick();
        $h = $rep->sendHandoffs();
        if ($r['sent'] || $r['errors'] || $h || $rec) {
            $this->info(now()->format('H:i').' bheje '.$r['sent'].', galti '.$r['errors'].', handoff '.$h.', recover '.$rec);
        }

        return 0;
    }
}
