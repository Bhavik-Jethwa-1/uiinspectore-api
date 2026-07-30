<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            // Stripe Price IDs — created once per plan+billing_cycle combination
            // Use these in Stripe Checkout Session instead of inline price_data
            $table->string('stripe_monthly_price_id')->nullable()->after('price_yearly');
            $table->string('stripe_yearly_price_id')->nullable()->after('stripe_monthly_price_id');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['stripe_monthly_price_id', 'stripe_yearly_price_id']);
        });
    }
};
