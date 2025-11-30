<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private array $testProductData = [
        'name' => 'Flash Sale Product',
        'price_cents' => 9999,
        'stock' => 100,
        'reserved_quantity' => 0,
        'sold_quantity' => 0,
    ];

    public function test_webhook_idempotency_same_key_repeated(): void
    {
        $product = Product::create($this->testProductData);
        
        $holdResponse = $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'qty' => 2,
        ]);

        $holdId = $holdResponse->json('hold_id');

        $orderResponse = $this->postJson('/api/orders', [
            'hold_id' => $holdId,
        ]);

        $orderId = $orderResponse->json('order_id');
        $idempotencyKey = 'test-key-' . uniqid();

        $response1 = $this->postJson('/api/payments/webhook', [
            'order_id' => $orderId,
            'idempotency_key' => $idempotencyKey,
            'success' => true,
        ]);

        $response1->assertStatus(200);
        $response1->assertJson(['status' => 'paid']);

        $response2 = $this->postJson('/api/payments/webhook', [
            'order_id' => $orderId,
            'idempotency_key' => $idempotencyKey,
            'success' => true,
        ]);

        $response2->assertStatus(200);
        $response2->assertJson(['message' => 'Webhook already processed']);

        $payments = Payment::where('idempotency_key', $idempotencyKey)->get();
        $this->assertCount(1, $payments);

        $order = Order::find($orderId);
        $this->assertEquals('paid', $order->status);
    }
}

