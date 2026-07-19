<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'name',
        'email',
        'phone',
        'password_hash',
        'status',
        'is_active',
        'role_id',
    ];

    protected $hidden = [
        'password',
        'password_hash',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'locked_until' => 'datetime',
            'is_active' => 'boolean',
            'role_id' => 'integer',
        ];
    }

    public function getAuthPassword()
    {
        return $this->password_hash;
    }

    public function isSuperAdmin(): bool
    {
        return (int) $this->role_id === 1;
    }

    public function isAdmin(): bool
    {
        return (int) $this->role_id === 2;
    }

    public function isStaffUser(): bool
    {
        return (int) $this->role_id === 3;
    }
}
