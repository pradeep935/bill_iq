<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesTeamMember extends Model
{
    protected $table = 'sales_team_members';
    protected $guarded = [];
    protected $casts = ['target_amount' => 'decimal:2', 'active_from' => 'date', 'active_to' => 'date'];
    public function team() { return $this->belongsTo(SalesTeam::class, 'sales_team_id'); }
    public function user() { return $this->belongsTo(User::class); }
}
