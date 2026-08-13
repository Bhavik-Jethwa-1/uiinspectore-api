<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The key column was globally unique — only ONE user could have 'theme'.
        // Fix: make it unique per user (composite unique index).
        Schema::table('user_settings', function (Blueprint $table) {
            $table->dropUnique('user_settings_key_unique');
            $table->unique(['user_id', 'key'], 'user_settings_user_id_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->dropUnique('user_settings_user_id_key_unique');
            $table->string('key')->unique()->change();
        });
    }
};
