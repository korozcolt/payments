<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Korbytes\Payments\Enums\SubscriptionStatus;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->foreignId('subscription_plan_id')->constrained()->cascadeOnDelete();

            // Reference ID from the calling application.
            $table->string('reference_id')->index();

            $table->string('provider');
            // Null for providers with no server-side subscription object (e.g. Wompi).
            $table->string('provider_subscription_id')->nullable()->index();
            // The stored, tokenized payment method used to charge each cycle
            // (Wompi payment_source id; unused by MercadoPago, which stores
            // its own card token on the preapproval object).
            $table->string('provider_payment_source_id')->nullable();

            $table->string('customer_email')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();

            $table->string('status')->default(SubscriptionStatus::Active->value);

            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('next_billing_date')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('last_charged_at')->nullable();
            $table->unsignedInteger('failed_charge_attempts')->default(0);

            $table->json('provider_response')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('provider');
            $table->index('status');
            $table->index('next_billing_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
