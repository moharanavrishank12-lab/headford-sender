<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Setting;

/** Zaroori dikkatein - dashboard par lal dabba + roz ki report mein */
class Alerts
{
    public static function raise(string $key, string $msg): void
    {
        $all = json_decode(Setting::get('alerts', '{}'), true) ?: [];
        if (! isset($all[$key])) {
            Event::log(null, 'alert', $msg);
        }
        $all[$key] = ['msg' => $msg, 'at' => now()->toIso8601String()];
        Setting::put('alerts', json_encode($all));
    }

    public static function clear(string $key): void
    {
        $all = json_decode(Setting::get('alerts', '{}'), true) ?: [];
        unset($all[$key]);
        Setting::put('alerts', json_encode($all));
    }

    public static function all(): array
    {
        return json_decode(Setting::get('alerts', '{}'), true) ?: [];
    }
}
