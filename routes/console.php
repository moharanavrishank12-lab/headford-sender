<?php

use Illuminate\Support\Facades\Schedule;

// Cloudways Cron (har minute):  php artisan schedule:run
Schedule::command('sender:tick')->everyMinute()->withoutOverlapping(10);
Schedule::command('sender:replies')->everyFiveMinutes()->withoutOverlapping(30);
Schedule::command('sender:health')->dailyAt('09:00')->timezone('Asia/Kolkata');
Schedule::command('sender:backup')->dailyAt('02:30')->timezone('Asia/Kolkata');
