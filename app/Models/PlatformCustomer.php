<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformCustomer extends Model
{
    protected $fillable = ['name', 'email', 'password', 'phone', 'address', 'is_active'];

    protected $hidden = ['password', 'avatar_path'];

    protected $casts = ['is_active' => 'boolean'];
}
