<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BusinessSubscription extends Model
{
    protected $table = 'business_subscriptions';
    protected $guarded = [];

    protected $casts = [
        'starts_at' => 'date',
        'ends_at' => 'date',
        'trial_ends_at' => 'date',
    ];
}
