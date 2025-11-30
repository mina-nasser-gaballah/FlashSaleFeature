<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        Product::create([
            'name' => 'Flash Sale Product',
            'price_cents' => 9999,
            'stock' => 100,
            'reserved_quantity' => 0,
            'sold_quantity' => 0,
        ]);
    }
}

