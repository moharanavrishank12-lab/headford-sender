<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SenderBackup extends Command
{
    protected $signature = 'sender:backup';

    protected $description = 'Roz ka backup (saari tables, gzip JSON) - aakhri 7 din';

    public function handle(): int
    {
        $dir = storage_path('app/private/backups');
        @mkdir($dir, 0750, true);
        $file = $dir.'/sender-'.now()->format('Y-m-d').'.json.gz';
        $gz = gzopen($file, 'w6');
        foreach (['accounts', 'leads', 'sends', 'replies', 'suppressions', 'settings', 'events'] as $t) {
            gzwrite($gz, json_encode(['table' => $t])."\n");
            DB::table($t)->orderBy(DB::raw('1'))->chunk(2000, function ($rows) use ($gz) {
                foreach ($rows as $r) {
                    gzwrite($gz, json_encode($r)."\n");
                }
            });
        }
        gzclose($gz);
        $all = glob($dir.'/sender-*.json.gz');
        sort($all);
        foreach (array_slice($all, 0, max(0, count($all) - 7)) as $old) {
            @unlink($old);
        }
        $this->info('Backup: '.basename($file).' ('.round(filesize($file) / 1024).' KB)');

        return 0;
    }
}
