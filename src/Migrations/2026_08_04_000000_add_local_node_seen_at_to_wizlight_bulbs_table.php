<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FR-009 — ownership liveness.
 *
 * `local_node_seen_at` records the last time the *owning* node itself contacted
 * the device. It is the sole input to the lapse rule. `updated_at` cannot serve
 * this purpose: any node's write and every status check refresh it, so it
 * measures activity on the row rather than liveness of its owner.
 *
 * Nullable, so existing rows adopt it without a backfill. A NULL claim is
 * treated as lapsed, letting the first node to run discovery after upgrading
 * establish a real timestamp.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wizlight_bulbs', function (Blueprint $table) {
            $table->timestamp('local_node_seen_at')->nullable()->after('local_node_id');
        });
    }

    public function down(): void
    {
        Schema::table('wizlight_bulbs', function (Blueprint $table) {
            $table->dropColumn('local_node_seen_at');
        });
    }
};
