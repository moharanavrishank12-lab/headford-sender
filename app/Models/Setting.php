<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public static function get(string $key, $default = null)
    {
        $v = static::find($key)?->value;

        return $v === null ? $default : $v;
    }

    public static function put(string $key, $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
