<?php

namespace App\Http\Controllers\Api\Step4MakePayment;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class PaymentController extends Controller
{
    public function webhook(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required|integer',
            'idempotency_key' => 'required|string|max:255',
            'success' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $orderId = $request->input('order_id');
        $idempotencyKey = $request->input('idempotency_key');
        $success = $request->input('success');

        try {
            $result = DB::transaction(function () use ($orderId, $idempotencyKey, $success) {
                $existingPayment = Payment::where('idempotency_key', $idempotencyKey)->first();

                if ($existingPayment) {
                    if ($existingPayment->order_id != $orderId) {
                        Log::warning('Idempotency key mismatch detected', [
                            'idempotency_key' => $idempotencyKey,
                            'requested_order_id' => $orderId,
                            'existing_order_id' => $existingPayment->order_id,
                            'metric_type' => 'idempotency_key_mismatch',
                        ]);

                        return [
                            'error' => 'Idempotency key already used for a different order',
                            'status_code' => 409,
                        ];
                    }

                    $order = $existingPayment->order;

                    Log::info('Duplicate webhook detected (idempotency)', [
                        'idempotency_key' => $idempotencyKey,
                        'order_id' => $orderId,
                        'existing_order_id' => $existingPayment->order_id,
                        'existing_success' => $existingPayment->success,
                        'metric_type' => 'webhook_deduplication',
                    ]);

                    return [
                        'message' => 'Webhook already processed',
                        'order_id' => $order->id,
                        'status' => $order->fresh()->status,
                        'status_code' => 200,
                    ];
                }

                $order = Order::lockForUpdate()->find($orderId);

                if (!$order) {
                    Log::warning('Webhook received for non-existent order', [
                        'order_id' => $orderId,
                        'idempotency_key' => $idempotencyKey,
                        'success' => $success,
                    ]);

                    return [
                        'error' => 'Order not found (may be created later)',
                        'status_code' => 404,
                    ];
                }

                if ($order->status === 'paid') {
                    Log::warning('Payment webhook received for already paid order', [
                        'order_id' => $orderId,
                        'idempotency_key' => $idempotencyKey,
                        'success' => $success,
                        'current_status' => $order->status,
                    ]);

                    return [
                        'error' => 'Order is already paid and cannot be paid again',
                        'order_id' => $order->id,
                        'status' => $order->status,
                        'status_code' => 409,
                    ];
                }

                $existingSuccessfulPayment = Payment::where('order_id', $order->id)->where('success', true)->first();

                if ($existingSuccessfulPayment) {
                    Log::warning('Payment webhook received for order with existing successful payment', [
                        'order_id' => $orderId,
                        'idempotency_key' => $idempotencyKey,
                        'success' => $success,
                        'existing_payment_id' => $existingSuccessfulPayment->id,
                        'existing_idempotency_key' => $existingSuccessfulPayment->idempotency_key,
                        'order_status' => $order->status,
                    ]);

                    return [
                        'error' => 'Order already has a successful payment and cannot be paid again',
                        'order_id' => $order->id,
                        'status' => $order->status,
                        'status_code' => 409,
                    ];
                }

                Payment::create([
                    'order_id' => $order->id,
                    'idempotency_key' => $idempotencyKey,
                    'success' => $success,
                ]);

                if ($success) {
                    if ($order->status === 'pending') {
                        $order->update(['status' => 'paid']);

                        $order->product->decrement('reserved_quantity', $order->quantity);
                        $order->product->increment('sold_quantity', $order->quantity);

                        $order->refresh();

                        if ($order->hold_id) {
                            Cache::put("order:hold:{$order->hold_id}", [
                                'order_id' => $order->id,
                                'product_id' => $order->product_id,
                                'quantity' => $order->quantity,
                                'total_price_cents' => $order->total_price_cents,
                                'status' => $order->status,
                            ], 3600);
                        }

                        Log::info('Order marked as paid', [
                            'order_id' => $order->id,
                            'idempotency_key' => $idempotencyKey,
                        ]);
                    } elseif ($order->status === 'cancelled') {
                        $order->update(['status' => 'paid']);

                        $order->product->decrement('stock', $order->quantity);
                        $order->product->increment('sold_quantity', $order->quantity);

                        $hold = $order->hold;
                        if ($hold && $hold->status === 'cancelled') {
                            $hold->update(['status' => 'converted']);
                        }

                        $order->refresh();

                        if ($order->hold_id) {
                            Cache::put("order:hold:{$order->hold_id}", [
                                'order_id' => $order->id,
                                'product_id' => $order->product_id,
                                'quantity' => $order->quantity,
                                'total_price_cents' => $order->total_price_cents,
                                'status' => $order->status,
                            ], 3600);
                        }

                        Log::info('Order marked as paid after previous cancellation', [
                            'order_id' => $order->id,
                            'idempotency_key' => $idempotencyKey,
                        ]);
                    } else {
                        Log::info('Payment webhook for already processed order', [
                            'order_id' => $order->id,
                            'current_status' => $order->status,
                            'idempotency_key' => $idempotencyKey,
                        ]);
                    }
                } else {
                    if ($order->status === 'pending') {
                        $order->update(['status' => 'cancelled']);

                        $hold = $order->hold;
                        if ($hold && $hold->status === 'converted') {
                            $hold->update(['status' => 'cancelled']);
                        }

                        $order->product->increment('stock', $order->quantity);
                        $order->product->decrement('reserved_quantity', $order->quantity);

                        $order->refresh();

                        if ($order->hold_id) {
                            Cache::put("order:hold:{$order->hold_id}", [
                                'order_id' => $order->id,
                                'product_id' => $order->product_id,
                                'quantity' => $order->quantity,
                                'total_price_cents' => $order->total_price_cents,
                                'status' => $order->status,
                            ], 3600);
                        }

                        Log::info('Order cancelled due to payment failure', [
                            'order_id' => $order->id,
                            'idempotency_key' => $idempotencyKey,
                        ]);
                    }
                }

                return [
                    'message' => 'Webhook processed',
                    'order_id' => $order->id,
                    'status' => $order->fresh()->status,
                    'status_code' => 200,
                ];
            }, 5);

            if (isset($result['error'])) {
                return response()->json(['error' => $result['error']], $result['status_code']);
            }

            return response()->json([
                'message' => $result['message'],
                'order_id' => $result['order_id'],
                'status' => $result['status'],
            ], $result['status_code']);

        } catch (\Exception $e) {
            $isDeadlock = str_contains($e->getMessage(), 'Deadlock') || str_contains($e->getMessage(), 'Lock wait timeout');

            if ($isDeadlock) {
                Log::warning('Payment webhook processing contention detected (deadlock/retry)', [
                    'order_id' => $orderId,
                    'idempotency_key' => $idempotencyKey,
                    'error' => $e->getMessage(),
                    'error_type' => 'deadlock_contention',
                ]);
            }

            Log::error('Payment webhook processing failed', [
                'order_id' => $orderId,
                'idempotency_key' => $idempotencyKey,
                'error' => $e->getMessage(),
                'error_type' => $isDeadlock ? 'deadlock_contention' : 'general_error',
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(['error' => 'Failed to process webhook'], 500);
        }
    }
}

