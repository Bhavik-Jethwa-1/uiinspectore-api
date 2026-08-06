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
            $table->foreignId('user_id')->constrained()->onDelete('cascade');

            // Core generation data
            $table->string('prompt', 4000);
            $table->string('negative_prompt', 2000)->nullable();
            $table->string('provider');         // openai, groq, minimax, imagen
            $table->string('model');            // dall-e-3, gpt-image-1, flux-schnell, imagen-3
            $table->string('status', 20)->default('pending'); // pending, generating, completed, failed

            // Image output
            $table->string('image_url')->nullable();   // final image URL
            $table->string('local_path')->nullable();  // stored locally
            $table->string('revised_prompt')->nullable();

            // Generation parameters
            $table->string('size', 20)->default('1024x1024');    // 512x512, 1024x1024, 1792x1024, etc.
            $table->string('aspect_ratio', 10)->default('1:1'); // 1:1, 16:9, 9:16, 4:3, 3:4
            $table->string('quality', 10)->default('standard'); // standard, hd
            $table->string('style', 20)->nullable();             // vivid, natural, or provider-specific
            $table->unsignedTinyInteger('n')->default(1);        // 1-4 images

            // Metadata
            $table->unsignedInteger('generation_time_ms')->nullable();
            $table->string('error_message', 2000)->nullable();
            $table->string('error_code', 50)->nullable();
            $table->json('provider_metadata')->nullable();

            $table->timestamps();

            // Indexes
            $table->index(['user_id', 'created_at']);
            $table->index(['provider', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_image_generations');
    }
};
