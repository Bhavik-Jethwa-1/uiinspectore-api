<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Users table (extend default)
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('email');
        });

        // Projects
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // Screenshots
        Schema::create('screenshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->foreignId('review_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('original_name');
            $table->string('stored_name');
            $table->string('path');
            $table->string('mime_type');
            $table->integer('size');
            $table->integer('width')->nullable();
            $table->integer('height')->nullable();
            $table->timestamps();
        });

        // Reviews
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->foreignId('screenshot_id')->nullable();
            $table->enum('status', ['pending', 'analyzing', 'completed', 'failed'])->default('pending');
            $table->enum('persona', ['first_time', 'non_technical', 'junior_developer', 'developer', 'devops', 'designer', 'manager', 'custom']);
            $table->string('page_goal');
            $table->text('ai_response')->nullable();
            $table->timestamps();
        });

        // Review Scores
        Schema::create('review_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained()->onDelete('cascade');
            $table->integer('visual_hierarchy')->nullable();
            $table->integer('clarity')->nullable();
            $table->integer('accessibility')->nullable();
            $table->integer('consistency')->nullable();
            $table->integer('layout')->nullable();
            $table->integer('typography')->nullable();
            $table->integer('ux')->nullable();
            $table->integer('overall')->nullable();
            $table->text('summary')->nullable();
            $table->json('strengths')->nullable();
            $table->timestamps();
        });

        // Review Issues
        Schema::create('review_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained()->onDelete('cascade');
            $table->string('title');
            $table->enum('severity', ['critical', 'high', 'medium', 'low']);
            $table->string('category');
            $table->text('description');
            $table->text('why_it_matters');
            $table->text('recommendation');
            $table->timestamps();
        });

        // Review Annotations
        Schema::create('review_annotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained()->onDelete('cascade');
            $table->foreignId('review_issue_id')->constrained()->onDelete('cascade');
            $table->integer('x')->default(0);
            $table->integer('y')->default(0);
            $table->integer('width')->default(100);
            $table->integer('height')->default(100);
            $table->timestamps();
        });

        // Review Suggestions
        Schema::create('review_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained()->onDelete('cascade');
            $table->string('title');
            $table->enum('priority', ['critical', 'high', 'medium', 'low']);
            $table->string('category');
            $table->text('problem');
            $table->text('recommendation');
            $table->text('expected_impact');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_suggestions');
        Schema::dropIfExists('review_annotations');
        Schema::dropIfExists('review_issues');
        Schema::dropIfExists('review_scores');
        Schema::dropIfExists('reviews');
        Schema::dropIfExists('screenshots');
        Schema::dropIfExists('projects');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_admin');
        });
    }
};
