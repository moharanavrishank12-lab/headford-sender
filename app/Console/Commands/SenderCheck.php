<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Services\GmailClient;
use Illuminate\Console\Command;
use Throwable;

class SenderCheck extends Command
{
    protected $signature = 'sender:check';

    protected $description = 'Har ID ka Google access check (setup ke baad ek baar chalao)';

    public function handle(GmailClient $g): int
    {
        $ok = 0;
        foreach (Account::orderBy('email')->get() as $a) {
            try {
                $p = $g->profile($a->email);
                $this->info("OK   {$a->email} ({$p['messagesTotal']} mails)");
                $a->update(['last_error' => null]);
                $ok++;
            } catch (Throwable $e) {
                $this->error("FAIL {$a->email}: ".substr($e->getMessage(), 0, 200));
                $a->update(['last_error' => substr('access check: '.$e->getMessage(), 0, 490)]);
            }
        }
        $this->line("$ok / ".Account::count().' IDs theek');

        return 0;
    }
}
