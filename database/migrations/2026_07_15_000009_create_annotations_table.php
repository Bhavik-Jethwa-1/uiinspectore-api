<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('annotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('screenshot_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('type'); // highlight, arrow, rectangle, freehand
            $table->string('severity')->nullable(); // critical, medium, good
            $table->integer('x');
            $table->integer('y');
            $table->integer('width')->nullable();
            $table->integer('height')->nullable();
            $table->json('points')->nullable(); // for freehand/arrows
            $table->string('color')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('annotations');
    }
};
