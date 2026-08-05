<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2, T002 — capability columns on wizlight_bulbs.
 *
 * Additive-only migration: every column is nullable so existing rows adopt
 * the schema without a backfill. A NULL capability_class means "not yet
 * probed"; consumers fall back to config defaults until discovery populates
 * the row.
 *
 * Constitution §V: authored here, never run by an agent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wizlight_bulbs', function (Blueprint $table) {
            $table->string('firmware_version')->nullable()->after('model');
            $table->string('capability_class')->nullable()->after('firmware_version');
            $table->integer('warmth_min_kelvin')->nullable()->after('capability_class');
            $table->integer('warmth_max_kelvin')->nullable()->after('warmth_min_kelvin');
            $table->integer('min_brightness_pct')->nullable()->after('warmth_max_kelvin');
            $table->integer('wiz_room_id')->nullable()->after('min_brightness_pct');
            $table->integer('wiz_group_id')->nullable()->after('wiz_room_id');
        });
    }

    public function down(): void
    {
        Schema::table('wizlight_bulbs', function (Blueprint $table) {
            $table->dropColumn(['firmware_version', 'capability_class', 'warmth_min_kelvin', 'warmth_max_kelvin', 'min_brightness_pct', 'wiz_room_id', 'wiz_group_id']);
        });
    }
};
