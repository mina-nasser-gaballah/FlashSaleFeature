<?php

namespace App\Http\Controllers\Api\Step3MakeOrder;

use App\Http\Controllers\Controller;
use App\Models\Hold;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class OrderController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'hold_id' => 'required|exists:holds,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $holdId = $request->input('hold_id');

        try {
            $cachedOrder = Cache::get("order:hold:{$holdId}");
            if ($cachedOrder) {
                Log::info('Order retrieved from cache - hold already used', [
                    'order_id' => $cachedOrder['order_id'],
                    'hold_id' => $holdId,
                ]);

                return response()->json([
                    'error' => 'Hold has already been used',
                ], 409);
            }

            $result = DB::transaction(function () use ($holdId) {
                $hold = Hold::with('product')->lockForUpdate()->find($holdId);

                if (!$hold) {
                    return ['error' => 'Hold not found', 'status' => 404];
                }

                $expiresAt = $hold->expires_at instanceof Carbon ? $hold->expires_at : ($hold->expires_at ? Carbon::parse($hold->expires_at) : null);

                if ($hold->status !== 'active' || !$expiresAt || $expiresAt->isPast()) {
                    return ['error' => 'Hold is expired or invalid', 'status' => 409];
                }

                $existingOrder = $hold->order;
                if ($existingOrder) {
                    Cache::put("order:hold:{$holdId}", [
                        'order_id' => $existingOrder->id,
                        'product_id' => $existingOrder->product_id,
                        'quantity' => $existingOrder->quantity,
                        'total_price_cents' => $existingOrder->total_price_cents,
                        'status' => $existingOrder->status,
                    ], 3600);

                    return ['error' => 'Hold has already been used', 'status' => 409];
                }

                if ($hold->status === 'converted') {
                    return ['error' => 'Hold has already been converted', 'status' => 409];
                }

                $order = Order::create([
                    'product_id' => $hold->product_id,
                    'hold_id' => $hold->id,
                    'quantity' => $hold->quantity,
                    'total_price_cents' => $hold->product->price_cents * $hold->quantity,
                    'status' => 'pending',
                ]);

                $hold->update(['status' => 'converted']);

                Cache::put("order:hold:{$holdId}", [
                    'order_id' => $order->id,
                    'product_id' => $order->product_id,
                    'quantity' => $order->quantity,
                    'total_price_cents' => $order->total_price_cents,
                    'status' => $order->status,
                ], 3600);

                Log::info('Order created from hold', [
                    'order_id' => $order->id,
                    'hold_id' => $hold->id,
                    'product_id' => $order->product_id,
                    'quantity' => $order->quantity,
                    'total_price_cents' => $order->total_price_cents,
                ]);

                return [
                    'order_id' => $order->id,
                    'product_id' => $order->product_id,
                    'quantity' => $order->quantity,
                    'total_price_cents' => $order->total_price_cents,
                    'status' => $order->status,
                    'status_code' => 201,
                ];
            }, 5);

            if (isset($result['error'])) {
                return response()->json(['error' => $result['error']], $result['status']);
            }

            return response()->json([
                'order_id' => $result['order_id'],
                'product_id' => $result['product_id'],
                'quantity' => $result['quantity'],
                'total_price_cents' => $result['total_price_cents'],
                'status' => $result['status'],
            ], 201);

        } catch (\Exception $e) {
            $isDeadlock = str_contains($e->getMessage(), 'Deadlock') || str_contains($e->getMessage(), 'Lock wait timeout');
            
            if ($isDeadlock) {
                Log::warning('Order creation contention detected (deadlock/retry)', [
                    'hold_id' => $holdId,
                    'error' => $e->getMessage(),
                    'error_type' => 'deadlock_contention',
                ]);
            }

            Log::error('Order creation failed', [
                'hold_id' => $holdId,
                'error' => $e->getMessage(),
                'error_type' => $isDeadlock ? 'deadlock_contention' : 'general_error',
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(['error' => 'Failed to create order'], 500);
        }
    }
}

