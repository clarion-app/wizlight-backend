<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds scene, mode, and dual-head columns to the bulbs table.
     * Additive-only, every column nullable, no default, no backfill.
     */
    public function up(): void
    {
        Schema::table('wizlight_bulbs', function (Blueprint $table) {
            $table->string('active_mode')->nullable()->after('signal');
            $table->integer('scene_id')->nullable()->after('active_mode');
            $table->integer('scene_speed')->nullable()->after('scene_id');
            $table->integer('white_warm')->nullable()->after('scene_speed');
            $table->integer('white_cool')->nullable()->after('white_warm');
            $table->integer('head_ratio')->nullable()->after('white_cool');
            $table->boolean('dual_head')->nullable()->after('head_ratio');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wizlight_bulbs', function (Blueprint $table) {
            $table->dropColumn([
                'active_mode',
                'scene_id',
                'scene_speed',
                'white_warm',
                'white_cool',
                'head_ratio',
                'dual_head',
            ]);
        });
    }
};
