<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'hold_id',
        'quantity',
        'total_price_cents',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'total_price_cents' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function hold(): BelongsTo
    {
        return $this->belongsTo(Hold::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}

