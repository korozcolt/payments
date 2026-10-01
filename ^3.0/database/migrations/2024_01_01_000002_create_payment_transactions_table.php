<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Korbytes\Payments\Enums\PaymentStatus;

/**
 * Payment transactions table migration.
 *
 * Stores all payment transactions with provider details,
 * status tracking, and webhook data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            // Polymorphic relationship to any payable model (Order, etc.)
            $table->nullableMorphs('payable');

            // Reference ID from the calling application
            $table->string('reference_id')->index();

            // Provider info
            $table->string('provider'); // wompi, mercadopago, epayco
            $table->string('provider_transaction_id')->nullable()->index();
            $table->string('provider_reference')->nullable();

            // Amounts (in cents)
            $table->unsignedInteger('amount');
            $table->string('currency', 3)->default('COP');

            // Status
            $table->string('status')->default(PaymentStatus::Pending->value);

            // Idempotency
            $table->string('idempotency_key')->unique();
            $table->timestamp('webhook_received_at')->nullable();
            $table->unsignedInteger('webhook_attempts')->default(0);

            // Provider response data
            $table->json('provider_request')->nullable();
            $table->json('provider_response')->nullable();
            $table->json('webhook_payload')->nullable();

            // Error tracking
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();

            // Metadata from the calling application
            $table->json('metadata')->nullable();

            // Timestamps
            $table->timestamp('initiated_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            // Indexes
            $table->index('provider');
            $table->index('status');
            $table->index(['reference_id', 'status']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
    }
};
