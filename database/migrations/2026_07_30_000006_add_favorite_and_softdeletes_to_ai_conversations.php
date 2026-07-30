<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_conversations', function (Blueprint $table) {
            // Add is_favorite column if missing
            if (!Schema::hasColumn('ai_conversations', 'is_favorite')) {
                $table->boolean('is_favorite')->default(false)->after('is_archived');
                $table->index(['user_id', 'is_favorite']);
            }

            // Add soft deletes column for restore-from-trash
            if (!Schema::hasColumn('ai_conversations', 'deleted_at')) {
                $table->softDeletes()->after('updated_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ai_conversations', function (Blueprint $table) {
            if (Schema::hasColumn('ai_conversations', 'is_favorite')) {
                $table->dropIndex(['user_id', 'is_favorite']);
                $table->dropColumn('is_favorite');
            }
            if (Schema::hasColumn('ai_conversations', 'deleted_at')) {
                $table->dropColumn('deleted_at');
            }
        });
    }
};