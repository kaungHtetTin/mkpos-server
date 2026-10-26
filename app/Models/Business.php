<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Business extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::created(function (Business $business) {
            if (\Illuminate\Support\Facades\Schema::hasTable('sales_ai_wallets')) {
                app(\App\Services\SalesAi\Wallet::class)->ensure((int) $business->id, newBusiness: true);
            }
        });
    }

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
