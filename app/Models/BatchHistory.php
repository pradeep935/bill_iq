<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BatchHistory extends Model
{
    protected $table = 'batch_histories';
    protected $guarded = [];

    protected $casts = [
        'quantity' => 'decimal:3',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }
}
