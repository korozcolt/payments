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
        // SQLite on Laravel 10 can neither drop foreign keys nor (without doctrine/dbal)
        // drop columns. SQLite is only used for local/test databases, and rolling back
        // the earlier migration drops the whole table anyway, so skip it there.
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropColumn(['refunded_amount', 'provider_refund_id', 'refunded_at']);
        });
    }
};
