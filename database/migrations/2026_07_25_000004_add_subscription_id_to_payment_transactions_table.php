<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tags recurring-cycle charges as PaymentTransaction rows linked to their
 * Subscription, reusing the existing charge/webhook/event infrastructure
 * instead of a separate ledger table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->foreignId('subscription_id')->nullable()->after('payable_id')->constrained()->nullOnDelete();
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
            $table->dropConstrainedForeignId('subscription_id');
        });
    }
};
