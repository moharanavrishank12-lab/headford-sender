<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Reply extends Model
{
    protected $guarded = [];

    protected $casts = ['handoff_at' => 'datetime', 'received_at' => 'datetime'];
}
