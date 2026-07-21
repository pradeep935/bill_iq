<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderNotification extends Model
{
    protected $table = 'order_notifications';
    protected $guarded = [];
    protected $casts = ['payload_json' => 'array', 'sent_at' => 'datetime'];
}
