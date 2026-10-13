<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    protected $table = 'companies';
    protected $guarded = [];

    protected $casts = [
        'a4_print_options' => 'array',
        'thermal_print_options' => 'array',
        'auto_print_after_payment' => 'boolean',
        'show_logo_on_invoice' => 'boolean',
        'show_logo_on_thermal_receipt' => 'boolean',
    ];

    public function users()
    {
        return $this->belongsToMany(User::class, 'company_user_access', 'company_id', 'user_id')
            ->withPivot(['access_role', 'is_default', 'status', 'created_by'])
            ->withTimestamps();
    }

    public function branches()
    {
        return $this->hasMany(Branch::class, 'business_id');
    }

    public function warehouses()
    {
        return $this->hasMany(Warehouse::class, 'business_id');
    }
}
