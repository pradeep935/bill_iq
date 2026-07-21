<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalesTeam extends Model
{
    use SoftDeletes;
    protected $table = 'sales_teams';
    protected $guarded = [];
    protected $casts = ['target_amount' => 'decimal:2'];
    public function branch() { return $this->belongsTo(Branch::class); }
    public function manager() { return $this->belongsTo(User::class, 'manager_id'); }
    public function members() { return $this->hasMany(SalesTeamMember::class); }
}
