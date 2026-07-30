<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_packs', function (Blueprint $table) {
            $table->id();
            $table->string('name');           // e.g. "Starter Pack"
            $table->integer('credits');       // number of credits
            $table->integer('price_cents');   // price in cents
            $table->string('stripe_price_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('user_credits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->integer('credits_remaining')->default(0);
            $table->integer('total_purchased')->default(0);
            $table->timestamps();
        });

        Schema::create('credit_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('credit_pack_id')->nullable();
            $table->string('stripe_session_id')->nullable();
            $table->string('stripe_payment_intent')->nullable();
            $table->integer('credits_purchased');
            $table->integer('amount_cents');
            $table->string('status'); // pending, completed, failed
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_purchases');
        Schema::dropIfExists('user_credits');
        Schema::dropIfExists('credit_packs');
    }
};
