<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Lead extends Model
{
    protected $guarded = [];

    protected $casts = ['next_send_at' => 'datetime', 'verified_at' => 'datetime', 'received_at' => 'datetime'];

    public function account()
    {
        return $this->belongsTo(Account::class);
    }
}
