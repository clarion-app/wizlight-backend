<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FR-011b — make the phantom foreign key real.
 *
 * The 2024_05_11 migration declares
 * `$table->uuid('bulb_id')->references('id')->on('wizlight_bulbs')`.
 * `references()`/`on()` are modifiers of `foreign()`, and there is no
 * `foreign()` in that chain, so the modifiers are inert: verified against both
 * engines, the generated DDL contains no constraint and no `ALTER TABLE ... ADD
 * CONSTRAINT` follows it. The schema has been claiming a relationship it never
 * enforced — see `specs/065-async-light-control/audit.md`.
 *
 * Orphans are purged first: on installs that ran the original migration, a
 * deleted bulb left its last-seen row behind (D8), and a real constraint over
 * unresolvable rows fails at migration time. Only rows whose `bulb_id` matches
 * no bulb at all — including soft-deleted ones — are removed.
 *
 * This is defence in depth beneath the application-level cascade, not a
 * replacement for it: a soft delete is an `UPDATE`, which no database cascade
 * can see. The `deleting` hook in WizlightBackendServiceProvider remains the
 * primary mechanism (FR-011).
 *
 * Constitution §V: authored here, never run by an agent.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('wizlight_bulb_last_seens')
            ->whereNotIn('bulb_id', function ($query) {
                $query->select('id')->from('wizlight_bulbs');
            })
            ->delete();

        Schema::table('wizlight_bulb_last_seens', function (Blueprint $table) {
            $table->foreign('bulb_id')
                ->references('id')
                ->on('wizlight_bulbs')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('wizlight_bulb_last_seens', function (Blueprint $table) {
            $table->dropForeign(['bulb_id']);
        });
    }
};
