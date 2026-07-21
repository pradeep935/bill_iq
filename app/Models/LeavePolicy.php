<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeavePolicy extends Model
{
    protected $table = 'leave_policies';
    protected $guarded = [];
    protected $casts = ['effective_from' => 'date', 'effective_to' => 'date'];

    public function rules() { return $this->hasMany(LeavePolicyRule::class); }
}
