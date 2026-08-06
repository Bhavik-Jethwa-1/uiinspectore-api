<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ui_generated_codes', function (Blueprint $table) {
            // Main React component code (larger field for full component)
            $table->longText('generated_code')->nullable()->after('code');

            // Additional/child component code
            $table->longText('supporting_code')->nullable()->after('generated_code');

            // Human-readable summary of what was generated
            $table->string('summary', 500)->nullable()->after('supporting_code');

            // Time taken to generate in ms
            $table->integer('generation_time_ms')->nullable()->after('summary');

            // AI model used for code generation
            $table->string('model')->nullable()->after('generation_time_ms');

            // Provider used (openai, groq)
            $table->string('provider')->nullable()->after('model');
        });
    }

    public function down(): void
    {
        Schema::table('ui_generated_codes', function (Blueprint $table) {
            $table->dropColumn([
                'generated_code',
                'supporting_code',
                'summary',
                'generation_time_ms',
                'model',
                'provider',
            ]);
        });
    }
};
