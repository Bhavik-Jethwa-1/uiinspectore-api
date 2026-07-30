<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_providers', function (Blueprint $table) {
            $table->id();
            $table->string('name');            // e.g. "OpenAI", "Replicate"
            $table->string('slug')->unique();  // e.g. "openai", "replicate"
            $table->string('type');            // "chat" | "image" | "both"
            $table->text('api_key')->nullable();
            $table->string('base_url')->nullable();
            $table->json('models')->nullable();   // cached list of available models
            $table->json('config')->nullable();   // extra config (org_id, etc.)
            $table->boolean('enabled')->default(true);
            $table->integer('priority')->default(100); // lower = preferred
            $table->string('health_status')->default('unknown'); // "healthy" | "unhealthy" | "unknown"
            $table->timestamp('health_checked_at')->nullable();
            $table->timestamps();

            $table->index('type');
            $table->index('enabled');
        });

        Schema::create('ai_provider_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('chat_provider')->default('openai');
            $table->string('chat_model')->nullable();
            $table->string('image_provider')->default('openai');
            $table->string('image_model')->nullable();
            $table->json('image_options')->nullable(); // size, quality, steps, etc.
            $table->timestamps();

            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_provider_settings');
        Schema::dropIfExists('ai_providers');
    }
};
