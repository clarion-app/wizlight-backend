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
        Schema::create('wizlight_rooms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('local_node_id');
            $table->string('name');
            $table->integer('dimming')->default(100);
            $table->boolean('state')->default(false);
            $table->integer('temperature')->default(2700);
            $table->integer('red')->default(255);
            $table->integer('green')->default(255);
            $table->integer('blue')->default(255);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wizlight_rooms');
    }
};