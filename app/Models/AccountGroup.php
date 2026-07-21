<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountGroup extends Model
{
    protected $table = 'account_groups';
    protected $guarded = [];

    protected $casts = ['is_system' => 'boolean', 'is_control_group' => 'boolean'];

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function accounts()
    {
        return $this->hasMany(Account::class, 'account_group_id');
    }
}
