<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Plans ──────────────────────────────────────────────────────────
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');                    // e.g. "Free", "Pro", "Team"
            $table->string('slug')->unique();          // free, pro, team
            $table->decimal('price_monthly', 8, 2)->default(0);
            $table->decimal('price_yearly', 8, 2)->default(0);
            $table->text('description')->nullable();
            $table->json('limits');                     // {ai_generations: -1, projects: 2, ...}
            $table->json('features');                  // {ai_autodesigner: false, api_access: false, ...}
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        // ── Subscriptions ─────────────────────────────────────────────────
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->string('status')->default('active'); // active, cancelled, past_due, trialing
            $table->string('billing_cycle')->default('monthly'); // monthly, yearly
            $table->decimal('amount', 8, 2)->default(0);
            $table->string('currency')->default('USD');
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('cancelled_at')->nullable();
            $table->string('provider')->nullable();    // stripe, razorpay, paypal
            $table->string('provider_subscription_id')->nullable();
            $table->timestamps();
        });

        // ── Subscription History ──────────────────────────────────────────
        Schema::create('subscription_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->string('action');                  // created, upgraded, downgraded, cancelled, renewed, resumed
            $table->string('from_plan')->nullable();   // plan slug
            $table->string('to_plan')->nullable();
            $table->decimal('amount', 8, 2)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        // ── Payments ──────────────────────────────────────────────────────
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->string('type')->default('subscription'); // subscription, one_time, refund
            $table->decimal('amount', 8, 2);
            $table->string('currency')->default('USD');
            $table->string('status')->default('pending'); // pending, succeeded, failed, refunded, cancelled
            $table->string('provider')->nullable();       // stripe, razorpay, paypal
            $table->string('provider_payment_id')->nullable();
            $table->string('provider_refund_id')->nullable();
            $table->text('failure_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        // ── Payment Methods ───────────────────────────────────────────────
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type');                   // card, bank_account, wallet
            $table->string('provider');               // stripe, razorpay, paypal
            $table->string('provider_payment_method_id');
            $table->string('last4')->nullable();
            $table->string('brand')->nullable();       // visa, mastercard
            $table->string('expiry_month')->nullable();
            $table->string('expiry_year')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        // ── Transactions ──────────────────────────────────────────────────
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->string('type');                    // charge, refund, credit, debit
            $table->decimal('amount', 8, 2);
            $table->string('currency')->default('USD');
            $table->string('status')->default('completed');
            $table->string('description')->nullable();
            $table->timestamps();
        });

        // ── Feature Permissions ─────────────────────────────────────────────
        Schema::create('feature_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->string('feature');               // ai_autodesigner, api_access, team_members, etc.
            $table->boolean('enabled')->default(false);
            $table->integer('limit')->nullable();     // -1 = unlimited, null = not applicable
            $table->string('period')->default('monthly'); // monthly, yearly, one_time
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // ── Feature Usage ──────────────────────────────────────────────────
        Schema::create('feature_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('feature');
            $table->integer('used')->default(0);
            $table->integer('limit')->nullable();     // -1 = unlimited
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->timestamps();
            $table->unique(['user_id', 'feature', 'period_start']);
        });

        // ── Usage Logs ─────────────────────────────────────────────────────
        Schema::create('usage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('feature');
            $table->integer('delta')->default(1);
            $table->string('action');                // increment, decrement, reset
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'feature', 'created_at']);
        });

        // ── Organizations ──────────────────────────────────────────────────
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('slug')->unique()->nullable();
            $table->string('logo_url')->nullable();
            $table->string('domain')->nullable();
            $table->string('provider')->nullable();
            $table->string('provider_org_id')->nullable();
            $table->timestamps();
        });

        // ── Team Members ────────────────────────────────────────────────────
        Schema::create('team_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default('viewer'); // owner, admin, editor, viewer
            $table->string('status')->default('active'); // active, invited, removed
            $table->string('invite_token')->nullable();
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'user_id']);
        });

        // ── Billing Addresses ──────────────────────────────────────────────
        Schema::create('billing_addresses', function (Blueprint $table) {
            $table->id();
            $table->nullableMorphs('billable');      // user or organization
            $table->string('name');
            $table->string('line1');
            $table->string('line2')->nullable();
            $table->string('city');
            $table->string('state')->nullable();
            $table->string('postal_code');
            $table->string('country', 2);
            $table->string('vat_id')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        // ── Invoices ───────────────────────────────────────────────────────
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->string('invoice_number')->unique();
            $table->string('status')->default('draft'); // draft, pending, paid, void, refunded
            $table->decimal('subtotal', 8, 2);
            $table->decimal('tax', 8, 2)->default(0);
            $table->decimal('total', 8, 2);
            $table->string('currency')->default('USD');
            $table->string('pdf_url')->nullable();
            $table->string('provider_invoice_id')->nullable();
            $table->timestamp('due_date')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        // ── Coupons ────────────────────────────────────────────────────────
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('type');                  // percent, fixed_amount
            $table->decimal('value', 8, 2);
            $table->string('currency')->nullable();
            $table->integer('max_uses')->nullable();
            $table->integer('used_count')->default(0);
            $table->integer('min_plan_months')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // ── Coupon Redemptions ─────────────────────────────────────────────
        Schema::create('coupon_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('discount_amount', 8, 2)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_redemptions');
        Schema::dropIfExists('coupons');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('billing_addresses');
        Schema::dropIfExists('team_members');
        Schema::dropIfExists('organizations');
        Schema::dropIfExists('usage_logs');
        Schema::dropIfExists('feature_usage');
        Schema::dropIfExists('feature_permissions');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('subscription_history');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');
    }
};
