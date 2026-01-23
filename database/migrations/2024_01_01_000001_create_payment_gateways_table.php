<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment gateways configuration table.
 *
 * Stores configuration for each payment provider including
 * API keys, status, and display settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_gateways', function (Blueprint $table) {
            $table->id();

            // Provider identification
            $table->string('provider')->unique(); // wompi, mercadopago, epayco
            $table->string('display_name'); // Display name for users

            // Status
            $table->boolean('is_active')->default(false);
            $table->boolean('is_sandbox')->default(true);

            // Configuration (encrypted JSON)
            $table->text('credentials')->nullable(); // API keys, secrets (encrypted)

            // Display
            $table->string('logo_url')->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);

            // Metadata
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index('is_active');
            $table->index('sort_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_gateways');
    }
};
