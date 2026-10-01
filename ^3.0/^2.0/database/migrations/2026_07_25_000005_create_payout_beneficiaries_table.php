<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Beneficiaries (payees) for third-party payouts.
 *
 * Only Wompi and ePayco have payout support in this package (MercadoPago
 * has no payouts API). See PayoutDriverInterface and USAGE.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payout_beneficiaries', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->string('provider');
            // Null for providers with no server-side beneficiary object
            // (e.g. Wompi, whose payout API takes bank details inline per
            // transaction instead of pre-registering a payee).
            $table->string('provider_beneficiary_id')->nullable();

            $table->string('name');
            $table->string('legal_id_type', 10);
            $table->string('legal_id');
            $table->string('person_type', 10); // NATURAL, JURIDICA
            $table->string('bank_code');
            $table->string('account_type', 30); // AHORROS, CORRIENTE, DEPOSITO_ELECTRONICO
            $table->string('account_number');
            $table->string('category')->default('providers'); // providers, payroll
            $table->string('email')->nullable();
            $table->string('phone')->nullable();

            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('provider');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_beneficiaries');
    }
};
