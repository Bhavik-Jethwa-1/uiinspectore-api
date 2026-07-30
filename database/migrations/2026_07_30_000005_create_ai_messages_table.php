<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('ai_conversations')->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('role', 20); // "user" | "assistant" | "system"
            $table->longText('content');
            $table->json('attachments')->nullable(); // [{type, url, name, size}]
            $table->json('metadata')->nullable(); // {provider, model, latency_ms, input_tokens, output_tokens, total_tokens, cost, error, stopped}
            $table->timestamp('created_at')->nullable();

            $table->index('conversation_id');
            $table->index(['conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_messages');
    }
};
