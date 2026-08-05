<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds scene and mode columns to the rooms table.
     * Additive-only, every column nullable, no default, no backfill.
     */
    public function up(): void
    {
        Schema::table('wizlight_rooms', function (Blueprint $table) {
            $table->string('active_mode')->nullable()->after('blue');
            $table->integer('scene_id')->nullable()->after('active_mode');
            $table->integer('scene_speed')->nullable()->after('scene_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wizlight_rooms', function (Blueprint $table) {
            $table->dropColumn([
                'active_mode',
                'scene_id',
                'scene_speed',
            ]);
        });
    }
};
