<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookBeforeOrderTest extends TestCase
{
    use RefreshDatabase;

    private array $testProductData = [
        'name' => 'Flash Sale Product',
        'price_cents' => 9999,
        'stock' => 100,
        'reserved_quantity' => 0,
        'sold_quantity' => 0,
    ];

    public function test_webhook_arriving_before_order_creation(): void
    {
        $product = Product::create($this->testProductData);
        
        $holdResponse = $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'qty' => 2,
        ]);

        $holdId = $holdResponse->json('hold_id');
        $idempotencyKey = 'test-key-' . uniqid();
        $nonExistentOrderId = 99999;

        $response1 = $this->postJson('/api/payments/webhook', [
            'order_id' => $nonExistentOrderId,
            'idempotency_key' => $idempotencyKey,
            'success' => true,
        ]);

        $response1->assertStatus(404);
        $response1->assertJson(['error' => 'Order not found (may be created later)']);

        $orderResponse = $this->postJson('/api/orders', [
            'hold_id' => $holdId,
        ]);

        $orderId = $orderResponse->json('order_id');

        $response2 = $this->postJson('/api/payments/webhook', [
            'order_id' => $orderId,
            'idempotency_key' => $idempotencyKey,
            'success' => true,
        ]);

        $response2->assertStatus(200);
        $response2->assertJson(['status' => 'paid']);

        $order = Order::find($orderId);
        $this->assertEquals('paid', $order->status);
    }
}

