<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    protected $guarded = [];

    protected $casts = ['ramp_start' => 'date', 'last_sent_at' => 'datetime', 'last_checked_at' => 'datetime'];
}
