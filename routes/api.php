<?php

use App\Http\Controllers\Api\Step1ShowProduct\ProductController;
use App\Http\Controllers\Api\Step2MakeHold\HoldController;
use App\Http\Controllers\Api\Step3MakeOrder\OrderController;
use App\Http\Controllers\Api\Step4MakePayment\PaymentController;
use Illuminate\Support\Facades\Route;



Route::GET('/products/{id}', [ProductController::class, 'show']);
Route::POST('/holds', [HoldController::class, 'store']);
Route::POST('/orders', [OrderController::class, 'store']);
Route::POST('/payments/webhook', [PaymentController::class, 'webhook']);
