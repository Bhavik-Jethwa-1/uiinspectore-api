<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ui_suggestions', function (Blueprint $table) {
            // Visual enhancement fields
            $table->string('severity', 20)->nullable()->after('priority');
            $table->unsignedTinyInteger('confidence_score')->nullable()->after('severity');
            $table->string('estimated_time', 50)->nullable()->after('confidence_score');
            $table->decimal('impact_score', 3, 1)->nullable()->after('estimated_time');
            
            // Crop coordinates (from annotation if linked)
            $table->decimal('crop_x', 5, 2)->nullable()->after('impact_score');
            $table->decimal('crop_y', 5, 2)->nullable()->after('crop_x');
            $table->decimal('crop_width', 5, 2)->nullable()->after('crop_y');
            $table->decimal('crop_height', 5, 2)->nullable()->after('crop_width');
            $table->string('highlight_type', 30)->default('outline')->after('crop_height');
            
            // AI generated preview
            $table->string('improved_preview')->nullable()->after('highlight_type');
            $table->string('comparison_before')->nullable()->after('improved_preview');
            $table->string('comparison_after')->nullable()->after('comparison_before');
            
            // Code examples
            $table->longText('react_code')->nullable()->after('comparison_after');
            $table->longText('tailwind_code')->nullable()->after('react_code');
            $table->longText('css_code')->nullable()->after('tailwind_code');
            
            // Developer notes
            $table->longText('developer_notes')->nullable()->after('css_code');
            
            // Link to annotation if available
            $table->unsignedBigInteger('ui_annotation_id')->nullable()->after('developer_notes');
        });
    }

    public function down(): void
    {
        Schema::table('ui_suggestions', function (Blueprint $table) {
            $table->dropColumn([
                'severity',
                'confidence_score', 
                'estimated_time',
                'impact_score',
                'crop_x',
                'crop_y',
                'crop_width',
                'crop_height',
                'highlight_type',
                'improved_preview',
                'comparison_before',
                'comparison_after',
                'react_code',
                'tailwind_code',
                'css_code',
                'developer_notes',
                'ui_annotation_id',
            ]);
        });
    }
};
