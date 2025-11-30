<?php

namespace Tests\Feature;

use App\Models\Hold;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ParallelHoldTest extends TestCase
{
    use RefreshDatabase;

    private array $testProductData = [
        'name' => 'Flash Sale Product',
        'price_cents' => 9999,
        'stock' => 10,
        'reserved_quantity' => 0,
        'sold_quantity' => 0,
    ];

   
    public function test_parallel_hold_attempts_at_stock_boundary_no_oversell(): void
    {
        $product = Product::create($this->testProductData);

        $successCount = 0;
        $failureCount = 0;

        for ($i = 0; $i < 15; $i++) {
            $response = $this->postJson('/api/holds', [
                'product_id' => $product->id,
                'qty' => 1,
            ]);

            if ($response->status() === 201) {
                $successCount++;
            } elseif ($response->status() === 409) {
                $failureCount++;
            }
        }

        $this->assertEquals(10, $successCount);
        $this->assertEquals(5, $failureCount);

        $product->refresh();
        $this->assertEquals(0, $product->stock);
        $this->assertEquals(10, $product->reserved_quantity);

        $holdsCount = Hold::where('product_id', $product->id)->where('status', 'active')->count();
        $this->assertEquals(10, $holdsCount);
    }
}
