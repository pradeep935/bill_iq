<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesVoucher extends Model
{
    protected $table = 'sales_vouchers';
    protected $guarded = [];

    protected $casts = [
        'invoice_date' => 'date',
        'due_date' => 'date',
        'approved_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'subtotal' => 'decimal:2',
        'item_discount_amount' => 'decimal:2',
        'voucher_discount_value' => 'decimal:2',
        'voucher_discount_amount' => 'decimal:2',
        'taxable_amount' => 'decimal:2',
        'cgst_amount' => 'decimal:2',
        'sgst_amount' => 'decimal:2',
        'igst_amount' => 'decimal:2',
        'cess_amount' => 'decimal:2',
        'shipping_amount' => 'decimal:2',
        'other_charges' => 'decimal:2',
        'round_off' => 'decimal:2',
        'grand_total' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'balance_amount' => 'decimal:2',
        'change_returned' => 'decimal:2',
    ];

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
        return $this->hasMany(SalesItem::class);
    }

    public function payments()
    {
        return $this->hasMany(SalesPayment::class);
    }

    public function returns()
    {
        return $this->hasMany(SalesReturnVoucher::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function salesperson()
    {
        return $this->belongsTo(User::class, 'salesperson_id');
    }
}
