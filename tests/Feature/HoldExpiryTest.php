<?php

namespace Tests\Feature;

use App\Jobs\ProcessExpiredHolds;
use App\Models\Hold;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HoldExpiryTest extends TestCase
{
    use RefreshDatabase;

    private array $testProductData = [
        'name' => 'Flash Sale Product',
        'price_cents' => 9999,
        'stock' => 100,
        'reserved_quantity' => 0,
        'sold_quantity' => 0,
    ];


    public function test_hold_expiry_returns_availability(): void
    {
        $product = Product::create($this->testProductData);
        $initialStock = $product->stock;

        $holdResponse = $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'qty' => 20,
        ]);

        $holdId = $holdResponse->json('hold_id');
        $this->assertNotNull($holdId);

        $product->refresh();
        $this->assertEquals($initialStock - 20, $product->stock);
        $this->assertEquals(20, $product->reserved_quantity);

        $hold = Hold::find($holdId);
        $hold->update([
            'expires_at' => Carbon::now()->subMinute(),
        ]);

        $hold->refresh();
        $this->assertEquals('active', $hold->status);

        $orderResponse = $this->postJson('/api/orders', [
            'hold_id' => $holdId,
        ]);

        $orderResponse->assertStatus(409);
        $orderResponse->assertJson(['error' => 'Hold is expired or invalid']);

        $job = new ProcessExpiredHolds();
        $job->handle();

        $product->refresh();
        $this->assertEquals($initialStock, $product->stock);
        $this->assertEquals(0, $product->reserved_quantity);

        $hold->refresh();
        $this->assertEquals('expired', $hold->status);

        $newHoldResponse = $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'qty' => 20,
        ]);

        $newHoldResponse->assertStatus(201);
    }
}
