<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->foreignId('screenshot_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('analysis_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('type'); // ui, ux, accessibility, conversion
            $table->string('severity'); // critical, medium, good
            $table->string('category'); // colors, typography, contrast, navigation, etc.
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('problem')->nullable();
            $table->text('reason')->nullable();
            $table->text('business_impact')->nullable();
            $table->text('recommendation')->nullable();
            $table->text('expected_result')->nullable();
            $table->string('status')->default('open'); // open, in_progress, resolved, ignored
            $table->integer('x')->nullable();
            $table->integer('y')->nullable();
            $table->integer('width')->nullable();
            $table->integer('height')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issues');
    }
};
