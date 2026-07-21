<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OpeningStockVoucher extends Model
{
    protected $table = 'opening_stock_vouchers';

    protected $guarded = [];

    protected $casts = [
        'opening_date' => 'date',
        'approved_at' => 'datetime',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items()
    {
        return $this->hasMany(OpeningStockItem::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
