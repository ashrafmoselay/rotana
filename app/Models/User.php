<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasFactory,HasRoles,Notifiable;

    protected $fillable = ['name', 'email', 'password', 'active', 'all_branches'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'password' => 'hashed', 'active' => 'boolean', 'all_branches' => 'boolean'];
    }

    public function branches()
    {
        return $this->belongsToMany(Branch::class);
    }

    public function mayAccessBranch(int $id): bool
    {
        return $this->all_branches || $this->branches()->whereKey($id)->exists();
    }
}
