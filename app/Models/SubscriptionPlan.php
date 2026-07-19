<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionPlan extends Model
{
    protected $table = 'subscription_plans';
    protected $guarded = [];

    protected $casts = [
        'feature_limits' => 'array',
    ];
}
