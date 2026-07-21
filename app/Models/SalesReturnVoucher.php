<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesReturnVoucher extends Model
{
    protected $table = 'sales_return_vouchers';
    protected $guarded = [];

    protected $casts = [
        'return_date' => 'date',
        'approved_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'subtotal' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'taxable_amount' => 'decimal:2',
        'cgst_amount' => 'decimal:2',
        'sgst_amount' => 'decimal:2',
        'igst_amount' => 'decimal:2',
        'cess_amount' => 'decimal:2',
        'round_off' => 'decimal:2',
        'grand_total' => 'decimal:2',
        'refund_amount' => 'decimal:2',
        'adjustment_amount' => 'decimal:2',
        'balance_amount' => 'decimal:2',
    ];

    public function sale()
    {
        return $this->belongsTo(SalesVoucher::class, 'sales_voucher_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

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
        return $this->hasMany(SalesReturnItem::class);
    }

    public function refunds()
    {
        return $this->hasMany(SalesReturnRefund::class);
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
