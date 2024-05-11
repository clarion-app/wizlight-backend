<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('wizlight_bulb_last_seens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->timestamps();
            $table->dateTime('last_seen_at');
            $table->uuid('bulb_id')->references('id')->on('wizlight_bulbs');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wizlight_bulb_last_seens');
    }
};
