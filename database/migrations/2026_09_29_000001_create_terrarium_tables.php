<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint \) {
            \->uuid('id')->primary();
            \->string('mobile', 15)->unique();
            \->string('first_name', 100)->nullable();
            \->string('last_name', 100)->nullable();
            \->timestamps();
        });

        Schema::create('otp_codes', function (Blueprint \) {
            \->id();
            \->string('mobile', 15)->index();
            \->string('code_hash', 255);
            \->timestamp('expires_at');
            \->unsignedSmallInteger('attempts')->default(0);
            \->boolean('is_used')->default(false);
            \->timestamps();
        });

        Schema::create('glass_sizes', function (Blueprint \) {
            \->uuid('id')->primary();
            \->string('name', 150);
            \->string('code', 50)->unique();
            \->unsignedInteger('total_volume_ml');
            \->unsignedInteger('usable_volume_ml');
            \->unsignedSmallInteger('max_plant_capacity');
            \->boolean('is_closed_ecosystem')->default(false);
            \->unsignedBigInteger('price_cents');
            \->boolean('is_active')->default(true);
            \->timestamps();
        });

        Schema::create('plants', function (Blueprint \) {
            \->uuid('id')->primary();
            \->string('name', 150);
            \->string('scientific_name', 150)->nullable();
            \->unsignedInteger('volume_occupancy_ml');
            \->string('light_level', 20);
            \->string('moisture_level', 20);
            \->boolean('tolerates_closed_glass')->default(true);
            \->unsignedBigInteger('price_cents');
            \->integer('stock_quantity')->default(0);
            \->boolean('is_active')->default(true);
            \->timestamps();
        });

        Schema::create('stones', function (Blueprint \) {
            \->uuid('id')->primary();
            \->string('name', 150);
            \->string('type', 50);
            \->unsignedInteger('volume_per_unit_ml');
            \->unsignedBigInteger('price_cents');
            \->integer('stock_quantity')->default(0);
            \->boolean('is_active')->default(true);
            \->timestamps();
        });

        Schema::create('figures', function (Blueprint \) {
            \->uuid('id')->primary();
            \->string('name', 150);
            \->unsignedInteger('volume_occupancy_ml');
            \->unsignedBigInteger('price_cents');
            \->integer('stock_quantity')->default(0);
            \->boolean('is_active')->default(true);
            \->timestamps();
        });

        Schema::create('compatibility_rules', function (Blueprint \) {
            \->uuid('id')->primary();
            \->string('rule_type', 50);
            \->string('source_type', 50);
            \->uuid('source_id')->nullable();
            \->string('target_type', 50);
            \->uuid('target_id')->nullable();
            \->boolean('is_compatible')->default(true);
            \->text('reason_message');
            \->integer('priority')->default(100);
            \->timestamps();

            \->index(['source_type', 'source_id', 'target_type', 'target_id'], 'idx_compat_lookup');
        });

        Schema::create('configurations', function (Blueprint \) {
            \->uuid('id')->primary();
            \->uuid('user_id')->nullable()->index();
            \->uuid('glass_size_id');
            \->unsignedBigInteger('calculated_price_cents');
            \->boolean('is_valid')->default(false);
            \->json('validation_metadata')->nullable();
            \->timestamps();

            \->foreign('glass_size_id')->references('id')->on('glass_sizes')->onDelete('restrict');
        });

        Schema::create('orders', function (Blueprint \) {
            \->uuid('id')->primary();
            \->uuid('user_id')->index();
            \->string('order_number', 50)->unique();
            \->string('status', 30)->index();
            \->unsignedBigInteger('total_price_cents');
            \->unsignedBigInteger('discount_cents')->default(0);
            \->unsignedBigInteger('shipping_cents')->default(0);
            \->timestamps();

            \->foreign('user_id')->references('id')->on('users')->onDelete('restrict');
        });

        Schema::create('order_items', function (Blueprint \) {
            \->uuid('id')->primary();
            \->uuid('order_id');
            \->uuid('configuration_id')->nullable();
            \->unsignedBigInteger('unit_price_cents');
            \->unsignedInteger('quantity')->default(1);
            \->json('snapshot_data');
            \->timestamps();

            \->foreign('order_id')->references('id')->on('orders')->onDelete('cascade');
        });

        Schema::create('payments', function (Blueprint \) {
            \->uuid('id')->primary();
            \->uuid('order_id');
            \->string('gateway', 50);
            \->unsignedBigInteger('amount_cents');
            \->string('currency', 10)->default('IRR');
            \->string('status', 30)->index();
            \->string('transaction_reference', 150)->nullable()->index();
            \->string('idempotency_key', 255)->unique();
            \->json('gateway_response')->nullable();
            \->timestamp('verified_at')->nullable();
            \->timestamps();

            \->foreign('order_id')->references('id')->on('orders')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('configurations');
        Schema::dropIfExists('compatibility_rules');
        Schema::dropIfExists('figures');
        Schema::dropIfExists('stones');
        Schema::dropIfExists('plants');
        Schema::dropIfExists('glass_sizes');
        Schema::dropIfExists('otp_codes');
        Schema::dropIfExists('users');
    }
};
