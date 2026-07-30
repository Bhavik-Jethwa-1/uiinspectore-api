<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Wallets — one per user
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('currency', 3)->default('USD');
            $table->decimal('balance', 12, 4)->default(0);          // available balance
            $table->decimal('reserved_balance', 12, 4)->default(0); // reserved for in-flight AI calls
            $table->string('status', 20)->default('active');        // active | frozen | closed
            $table->decimal('lifetime_purchased', 14, 4)->default(0);
            $table->decimal('lifetime_spent', 14, 4)->default(0);
            $table->decimal('lifetime_refunded', 14, 4)->default(0);
            $table->timestamps();

            $table->unique('user_id');
        });

        // Wallet transactions ledger
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30); // topup | ai_usage | refund | admin_credit | bonus | referral | adjustment
            $table->decimal('amount', 12, 4); // positive = credit, negative = debit
            $table->string('currency', 3)->default('USD');
            $table->string('reference_type', 50)->nullable(); // wallet_topup | ai_usage | manual
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->text('description')->nullable();
            $table->string('status', 20)->default('completed'); // pending | completed | failed | reversed
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['wallet_id', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
        });

        // Wallet top-ups
        Schema::create('wallet_topups', function (Blueprint $Table) {
            $Table->id();
            $Table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $Table->string('payment_provider', 20)->default('stripe'); // stripe | paypal | razorpay | manual
            $Table->string('payment_intent')->nullable(); // provider's transaction ID
            $Table->string('stripe_session_id')->nullable();
            $Table->decimal('amount', 10, 2);
            $Table->string('currency', 3)->default('USD');
            $Table->string('payment_status', 20)->default('pending'); // pending | completed | failed | refunded
            $Table->string('description')->nullable();
            $Table->timestamps();
        });

        // AI usage log
        Schema::create('ai_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 30);  // minimax | openai | openrouter
            $table->string('model', 50);
            $table->string('feature', 30);  // chat | image_generation | vision | code_generation | research | redesign
            $table->integer('input_tokens')->default(0);
            $table->integer('output_tokens')->default(0);
            $table->decimal('cost', 10, 6)->default(0); // USD cost
            $table->string('wallet_transaction_type', 30)->nullable(); // ai_usage | refund
            $table->unsignedBigInteger('wallet_transaction_id')->nullable();
            $table->unsignedBigInteger('wallet_transaction_ref_id')->nullable();
            $table->string('status', 20)->default('success'); // success | failed | refunded | reserved
            $table->string('request_id')->nullable();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['provider', 'model']);
            $table->index(['user_id', 'feature']);
        });

        // AI pricing configuration
        Schema::create('ai_pricing', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 30);
            $table->string('model', 50);
            $table->string('feature', 30); // chat | vision | image_generation | code_generation | research | redesign
            $table->decimal('price_per_1k_input', 10, 6)->default(0);
            $table->decimal('price_per_1k_output', 10, 6)->default(0);
            $table->decimal('flat_call_fee', 10, 6)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['provider', 'model', 'feature']);
        });

        // Auto-recharge settings
        Schema::create('auto_recharge_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->decimal('threshold', 10, 2)->default(5.00); // trigger when balance falls below
            $table->decimal('recharge_amount', 10, 2)->default(20.00);
            $table->string('payment_method_id')->nullable();
            $table->timestamps();

            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_recharge_settings');
        Schema::dropIfExists('ai_pricing');
        Schema::dropIfExists('ai_usage');
        Schema::dropIfExists('wallet_topups');
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('wallets');
    }
};
