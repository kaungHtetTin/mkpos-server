<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccessRole extends Model
{
    protected $fillable = ['business_id', 'name', 'permissions'];

    protected $casts = ['permissions' => 'array'];

    public function staff()
    {
        return $this->hasMany(User::class, 'access_role_id');
    }
}
