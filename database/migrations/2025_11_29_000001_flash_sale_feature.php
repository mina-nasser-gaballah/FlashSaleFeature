<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('price_cents');
            $table->unsignedInteger('stock');
            $table->unsignedInteger('reserved_quantity')->default(0);
            $table->unsignedInteger('sold_quantity')->default(0);

            $table->timestamps();
        });

        Schema::create('holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->enum('status', ['active', 'expired', 'converted', 'cancelled'])->default('active');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['product_id', 'status', 'expires_at']);
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hold_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('total_price_cents');
            $table->enum('status', ['pending', 'paid', 'cancelled'])->default('pending');
            $table->timestamps();

            $table->unique('hold_id');
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('idempotency_key')->unique();
            $table->boolean('success');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('holds');
        Schema::dropIfExists('products');
    }
};
