<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'price_cents',
        'stock',
        'reserved_quantity',
        'sold_quantity',
    ];

    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'stock' => 'integer',
            'reserved_quantity' => 'integer',
            'sold_quantity' => 'integer',
        ];
    }

    public function holds(): HasMany
    {
        return $this->hasMany(Hold::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}

