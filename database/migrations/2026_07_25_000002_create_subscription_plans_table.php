<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Korbytes\Payments\Enums\BillingInterval;

/**
 * Recurring billing plans.
 *
 * Only Wompi and MercadoPago have real subscription support in this
 * package — see PaymentDriverInterface::createPlan() and USAGE.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->string('provider');
            // Null for providers with no server-side plan object (e.g. Wompi).
            $table->string('provider_plan_id')->nullable();

            $table->string('name');
            $table->unsignedInteger('amount');
            $table->string('currency', 3)->default('COP');
            $table->string('interval')->default(BillingInterval::Month->value);
            $table->unsignedInteger('interval_count')->default(1);
            $table->unsignedInteger('trial_days')->nullable();

            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('provider');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_plans');
    }
};
