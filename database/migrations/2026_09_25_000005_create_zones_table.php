<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignId('playlist_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('output_channel')->nullable();
            $table->unsignedTinyInteger('volume')->default(100);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zones');
    }
};
