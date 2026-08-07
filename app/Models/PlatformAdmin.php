<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class PlatformAdmin extends Authenticatable
{
    use Notifiable;

    protected $fillable = ['name', 'email', 'password', 'is_active'];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = ['is_active' => 'boolean'];
}
