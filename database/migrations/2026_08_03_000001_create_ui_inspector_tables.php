<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Projects table
        Schema::create('ui_projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('product_type')->nullable(); // web, mobile, desktop
            $table->string('platform')->nullable(); // saas, ecommerce, blog, dashboard
            $table->string('status')->default('draft'); // draft, reviewing, reviewed
            $table->timestamps();
        });

        // Screenshots table
        Schema::create('ui_screenshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ui_project_id')->constrained()->onDelete('cascade');
            $table->string('file_path');
            $table->string('file_name');
            $table->unsignedInteger('file_size')->nullable();
            $table->string('variant')->default('original'); // original, current, redesign
            $table->string('page_goal')->nullable();
            $table->string('persona')->default('general'); // general, first_time, non_technical, junior_dev, devops, designer
            $table->timestamps();
        });

        // Reviews table
        Schema::create('ui_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ui_project_id')->constrained()->onDelete('cascade');
            $table->foreignId('ui_screenshot_id')->nullable()->constrained()->onDelete('set null');
            $table->string('status')->default('pending'); // pending, analyzing, completed, failed
            $table->json('scores')->nullable(); // { overall, hierarchy, clarity, accessibility, consistency }
            $table->json('summary')->nullable(); // { overall, ui_issues, ux_issues, accessibility_issues, improvements }
            $table->text('review_data')->nullable(); // Full AI review JSON
            $table->text('error_message')->nullable();
            $table->timestamps();
        });

        // Annotations table
        Schema::create('ui_annotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ui_review_id')->constrained()->onDelete('cascade');
            $table->unsignedInteger('number'); // 1, 2, 3...
            $table->string('type'); // issue, suggestion, praise
            $table->string('severity'); // critical, major, minor, info
            $table->float('x'); // percentage position 0-100
            $table->float('y');
            $table->float('width')->nullable();
            $table->float('height')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('suggested_fix')->nullable();
            $table->text('expected_improvement')->nullable();
            $table->string('difficulty')->nullable(); // easy, medium, hard
            $table->string('component_type')->nullable(); // navbar, card, button, form, etc.
            $table->timestamps();
        });

        // Suggestions table
        Schema::create('ui_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ui_review_id')->constrained()->onDelete('cascade');
            $table->string('category'); // typography, color, spacing, content, accessibility, navigation
            $table->string('title');
            $table->text('description');
            $table->text('suggested_fix');
            $table->text('expected_improvement')->nullable();
            $table->string('difficulty')->nullable();
            $table->string('priority'); // critical, high, medium, low
            $table->boolean('implemented')->default(false);
            $table->timestamps();
        });

        // Redesigns table
        Schema::create('ui_redesigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ui_project_id')->constrained()->onDelete('cascade');
            $table->foreignId('ui_screenshot_id')->nullable()->constrained()->onDelete('set null');
            $table->string('design_style'); // modern_saas, minimal, glassmorphism, enterprise, material, apple, dark, light
            $table->string('status')->default('pending'); // pending, generating, completed, failed
            $table->string('image_path')->nullable();
            $table->text('improved_items')->nullable(); // JSON array
            $table->text('regressed_items')->nullable(); // JSON array
            $table->text('unchanged_items')->nullable(); // JSON array
            $table->json('score_comparison')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });

        // Generated codes table
        Schema::create('ui_generated_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ui_redesign_id')->constrained()->onDelete('cascade');
            $table->string('framework'); // react, vue, html, nextjs
            $table->text('code')->nullable();
            $table->string('status')->default('pending');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ui_generated_codes');
        Schema::dropIfExists('ui_redesigns');
        Schema::dropIfExists('ui_suggestions');
        Schema::dropIfExists('ui_annotations');
        Schema::dropIfExists('ui_reviews');
        Schema::dropIfExists('ui_screenshots');
        Schema::dropIfExists('ui_projects');
    }
};
