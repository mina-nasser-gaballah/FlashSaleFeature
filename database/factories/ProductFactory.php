<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'name' => 'Flash Sale Product',
            'price_cents' => 9999,
            'stock' => 100,
            'reserved_quantity' => 0,
            'sold_quantity' => 0,
        ];
    }

}

