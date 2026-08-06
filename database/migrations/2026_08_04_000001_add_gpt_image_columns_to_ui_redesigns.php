<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ui_redesigns', function (Blueprint $table) {
            // Store original screenshot path for side-by-side comparison
            $table->string('original_image_path')->nullable()->after('ui_screenshot_id');

            // GPT Vision's analysis of the screenshot (layout, components, design issues)
            $table->json('vision_analysis')->nullable()->after('original_image_path');

            // Which provider/model generated this
            $table->string('provider')->nullable()->after('vision_analysis');
            $table->string('model')->nullable()->after('provider');
        });
    }

    public function down(): void
    {
        Schema::table('ui_redesigns', function (Blueprint $table) {
            $table->dropColumn(['original_image_path', 'vision_analysis', 'provider', 'model']);
        });
    }
};
