<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Korbytes\Payments\Enums\PayoutStatus;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payouts', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->foreignId('payout_beneficiary_id')->constrained()->cascadeOnDelete();

            // Reference ID from the calling application.
            $table->string('reference_id')->index();

            $table->string('provider');
            // Wompi: the payout batch id (payoutId). ePayco: the payment id.
            $table->string('provider_payout_id')->nullable()->index();

            $table->unsignedInteger('amount');
            $table->string('currency', 3)->default('COP');
            $table->string('status')->default(PayoutStatus::Pending->value);
            $table->text('description')->nullable();

            $table->json('provider_response')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index('provider');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payouts');
    }
};
