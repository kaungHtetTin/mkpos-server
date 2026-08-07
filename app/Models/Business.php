<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Business extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'status',
        'timezone',
        'currency',
    ];

    public function users()
    {
        return $this->hasMany(User::class);
    }
}
