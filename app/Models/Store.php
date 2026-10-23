<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Store extends Model
{
    use SoftDeletes;

    protected $table   = 'stores';
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'last_product_code'  => 'integer',
            'always_with_client' => 'boolean',
            'send_sms'           => 'boolean',
        ];
    }

    public function stocks()
    {
        return $this->belongsToMany(Stock::class, 'store_stock');
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'store_user', 'store_id', 'user_id')->withPivot('role')->withTimestamps();
    }

    public function debtors()
    {
        return $this->hasMany(Debtor::class);
    }

    public function discounts(): HasMany
    {
        return $this->hasMany(Discount::class);
    }
}
