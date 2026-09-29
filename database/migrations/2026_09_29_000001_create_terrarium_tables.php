<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('mobile', 15)->unique();
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->timestamps();
        });

        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id();
            $table->string('mobile', 15)->index();
            $table->string('code_hash', 255);
            $table->timestamp('expires_at');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->boolean('is_used')->default(false);
            $table->timestamps();
        });

        Schema::create('glass_sizes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 150);
            $table->string('code', 50)->unique();
            $table->unsignedInteger('total_volume_ml');
            $table->unsignedInteger('usable_volume_ml');
            $table->unsignedSmallInteger('max_plant_capacity');
            $table->boolean('is_closed_ecosystem')->default(false);
            $table->unsignedBigInteger('price_cents');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('plants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 150);
            $table->string('scientific_name', 150)->nullable();
            $table->unsignedInteger('volume_occupancy_ml');
            $table->string('light_level', 20);
            $table->string('moisture_level', 20);
            $table->boolean('tolerates_closed_glass')->default(true);
            $table->unsignedBigInteger('price_cents');
            $table->integer('stock_quantity')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('stones', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 150);
            $table->string('type', 50);
            $table->unsignedInteger('volume_per_unit_ml');
            $table->unsignedBigInteger('price_cents');
            $table->integer('stock_quantity')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('figures', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 150);
            $table->unsignedInteger('volume_occupancy_ml');
            $table->unsignedBigInteger('price_cents');
            $table->integer('stock_quantity')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('compatibility_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('rule_type', 50);
            $table->string('source_type', 50);
            $table->uuid('source_id')->nullable();
            $table->string('target_type', 50);
            $table->uuid('target_id')->nullable();
            $table->boolean('is_compatible')->default(true);
            $table->text('reason_message');
            $table->integer('priority')->default(100);
            $table->timestamps();

            $table->index(['source_type', 'source_id', 'target_type', 'target_id'], 'idx_compat_lookup');
        });

        Schema::create('configurations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->nullable()->index();
            $table->uuid('glass_size_id');
            $table->unsignedBigInteger('calculated_price_cents');
            $table->boolean('is_valid')->default(false);
            $table->json('validation_metadata')->nullable();
            $table->timestamps();

            $table->foreign('glass_size_id')->references('id')->on('glass_sizes')->onDelete('restrict');
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->index();
            $table->string('order_number', 50)->unique();
            $table->string('status', 30)->index();
            $table->unsignedBigInteger('total_price_cents');
            $table->unsignedBigInteger('discount_cents')->default(0);
            $table->unsignedBigInteger('shipping_cents')->default(0);
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('restrict');
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('order_id');
            $table->uuid('configuration_id')->nullable();
            $table->unsignedBigInteger('unit_price_cents');
            $table->unsignedInteger('quantity')->default(1);
            $table->json('snapshot_data');
            $table->timestamps();

            $table->foreign('order_id')->references('id')->on('orders')->onDelete('cascade');
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('order_id');
            $table->string('gateway', 50);
            $table->unsignedBigInteger('amount_cents');
            $table->string('currency', 10)->default('IRR');
            $table->string('status', 30)->index();
            $table->string('transaction_reference', 150)->nullable()->index();
            $table->string('idempotency_key', 255)->unique();
            $table->json('gateway_response')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->foreign('order_id')->references('id')->on('orders')->onDelete('restrict');
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
