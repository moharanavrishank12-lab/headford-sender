<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Setting;

class Signature
{
    public static function for(Account $a): string
    {
        $company = trim((string) Setting::get('company_line', '')) ?: 'HeadFord e-Commerce';
        $address = trim((string) Setting::get('address', '')) ?: "Milrose Lane\nMonsey - 19052";
        $lines = array_filter(['Regards,', $a->from_name, $company, $address], fn ($x) => trim((string) $x) !== '');

        return implode("\n", $lines);
    }

    public static function unsubUrl(int $leadId): ?string
    {
        $base = rtrim((string) config('app.url'), '/');
        if (! $base || str_contains($base, 'localhost')) {
            return null;
        }

        return $base.'/u/'.$leadId.'/'.self::token($leadId);
    }

    public static function token(int $leadId): string
    {
        return substr(hash_hmac('sha256', 'unsub:'.$leadId, (string) config('app.key')), 0, 24);
    }
}
