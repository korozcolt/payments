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
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('subscription_id');
        });
    }
};
