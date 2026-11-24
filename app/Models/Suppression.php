<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Suppression extends Model
{
    protected $guarded = [];

    // Email ya uska domain do-not-email list mein hai?
    public static function blocked(?string $email, ?string $domain = null): ?string
    {
        $email = strtolower(trim((string) $email));
        $dom = strtolower(trim((string) ($domain ?: substr(strrchr($email, '@') ?: '', 1))));
        $q = static::query()->where(function ($w) use ($email, $dom) {
            if ($email !== '') {
                $w->orWhere(fn ($x) => $x->where('kind', 'email')->where('value', $email));
            }
            if ($dom !== '') {
                $w->orWhere(fn ($x) => $x->where('kind', 'domain')->where('value', $dom));
            }
        })->first();

        return $q?->reason;
    }

    public static function add(string $value, string $reason, string $source = 'cloud'): void
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return;
        }
        $kind = str_contains($value, '@') ? 'email' : 'domain';
        static::updateOrCreate(['value' => $value], ['kind' => $kind, 'reason' => $reason, 'source' => $source]);
    }
}
