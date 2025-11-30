<?php

namespace App\Http\Controllers\Api\Step2MakeHold;

use App\Http\Controllers\Controller;
use App\Models\Hold;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class HoldController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'required|exists:products,id',
            'qty' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $productId = $request->input('product_id');
        $quantity = $request->input('qty');

        try {
            $result = DB::transaction(function () use ($productId, $quantity) {
                $product = Product::lockForUpdate()->find($productId);

                if (!$product) {
                    return ['error' => 'Product not found', 'status' => 404];
                }

                $updated = Product::where('id', $productId)->where('stock', '>=', $quantity)->decrement('stock', $quantity);

                if ($updated === 0) {
                    Log::warning('Insufficient stock for hold', [
                        'product_id' => $productId,
                        'requested_quantity' => $quantity,
                        'available_stock' => $product->stock,
                    ]);

                    return ['error' => 'Insufficient stock', 'status' => 409];
                }

                $hold = Hold::create([
                    'product_id' => $productId,
                    'quantity' => $quantity,
                    'status' => 'active',
                    'expires_at' => Carbon::now()->addMinutes(2),
                ]);

                $product->increment('reserved_quantity', $quantity);

                Log::info('Hold created', [
                    'hold_id' => $hold->id,
                    'product_id' => $productId,
                    'quantity' => $quantity,
                    'expires_at' => $hold->expires_at->toIso8601String(),
                ]);

                return [
                    'hold_id' => $hold->id,
                    'expires_at' => $hold->expires_at->toIso8601String(),
                    'status' => 201,
                ];
            }, 5);

            if (isset($result['error'])) {
                return response()->json(['error' => $result['error']], $result['status']);
            }

            return response()->json([
                'hold_id' => $result['hold_id'],
                'expires_at' => $result['expires_at'],
            ], 201);

        } catch (\Exception $e) {
            $isDeadlock = str_contains($e->getMessage(), 'Deadlock') || str_contains($e->getMessage(), 'Lock wait timeout');
            
            if ($isDeadlock) {
                Log::warning('Hold creation contention detected (deadlock/retry)', [
                    'product_id' => $productId,
                    'quantity' => $quantity,
                    'error' => $e->getMessage(),
                    'error_type' => 'deadlock_contention',
                ]);
            }

            Log::error('Hold creation failed', [
                'product_id' => $productId,
                'quantity' => $quantity,
                'error' => $e->getMessage(),
                'error_type' => $isDeadlock ? 'deadlock_contention' : 'general_error',
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(['error' => 'Failed to create hold'], 500);
        }
    }
}

