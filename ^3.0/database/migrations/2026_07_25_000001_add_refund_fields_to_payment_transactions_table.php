<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds refund tracking fields to payment transactions.
 *
 * Refund support varies by provider — see RefundResult and each driver's
 * refund() implementation for what is actually automated vs manual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->unsignedInteger('refunded_amount')->nullable()->after('amount');
            $table->string('provider_refund_id')->nullable()->after('provider_transaction_id');
            $table->timestamp('refunded_at')->nullable()->after('completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropColumn(['refunded_amount', 'provider_refund_id', 'refunded_at']);
        });
    }
};
