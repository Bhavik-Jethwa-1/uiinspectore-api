<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_image_generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32)->default('openai');
            $table->string('model', 64)->nullable();
            $table->string('original_image_path')->nullable();    // stored in storage/app/public/
            $table->string('generated_image_path')->nullable();
            $table->text('prompt')->nullable();
            $table->string('style', 64)->default('natural');
            $table->string('status', 16)->default('pending'); // pending|completed|failed
            $table->string('error_message', 512)->nullable();
            $table->integer('generation_time_ms')->nullable();
            $table->decimal('cost_usd', 8, 4)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_image_generations');
    }
};
