<?php

namespace App\Http\Controllers\Api\Step1ShowProduct;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class ProductController extends Controller
{
    public function show(string $id): JsonResponse
    {
        $productDetails = Cache::remember("product:details:{$id}", 3600, function () use ($id) {
            $product = Product::select('id', 'name', 'price_cents')->find($id);
            return $product ? [
                'id' => $product->id,
                'name' => $product->name,
                'price_cents' => $product->price_cents,
            ] : null;
        });

        if (!$productDetails) {
            return response()->json(['error' => 'Product not found'], 404);
        }

        $stock = Product::where('id', $id)->value('stock');

        return response()->json([
            'id' => $productDetails['id'],
            'name' => $productDetails['name'],
            'price_cents' => $productDetails['price_cents'],
            'available_stock' => $stock,
        ]);
    }
}

