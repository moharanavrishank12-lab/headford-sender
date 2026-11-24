<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    protected $guarded = [];

    public static function log(?string $domain, string $type, $detail = null): void
    {
        static::create(['domain' => $domain, 'type' => $type, 'detail' => is_string($detail) ? $detail : json_encode($detail)]);
    }
}
