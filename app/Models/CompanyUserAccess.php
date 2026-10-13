<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyUserAccess extends Model
{
    protected $table = 'company_user_access';
    protected $guarded = [];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
