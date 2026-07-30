<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('title', 255)->default('New Chat');
            $table->string('provider', 50)->default('openai');
            $table->string('model', 100)->default('');
            $table->text('system_prompt')->nullable();
            $table->float('temperature')->default(0.7);
            $table->integer('max_tokens')->default(4096);
            $table->string('folder', 100)->nullable()->default(null);
            $table->boolean('is_pinned')->default(false);
            $table->boolean('is_archived')->default(false);
            $table->json('metadata')->nullable(); // workspace, tags, etc.
            $table->timestamps();

            $table->index('user_id');
            $table->index(['user_id', 'is_archived']);
            $table->index(['user_id', 'is_pinned']);
            $table->index(['user_id', 'folder']);
            $table->index('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_conversations');
    }
};
