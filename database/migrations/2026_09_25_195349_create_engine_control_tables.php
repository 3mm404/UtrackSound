<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('engines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('server_url');
            $table->string('token_hash', 64)->nullable()->unique();
            $table->boolean('enabled')->default(true);
            $table->uuid('session_id')->nullable();
            $table->string('engine_version')->nullable();
            $table->json('capabilities')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->unsignedBigInteger('config_revision')->default(0);
            $table->string('config_hash', 64)->nullable();
            $table->unsignedBigInteger('command_sequence')->default(0);
            $table->unsignedBigInteger('report_sequence')->default(0);
            $table->json('observed_state')->nullable();
            $table->timestamps();
        });
        Schema::table('zones', function (Blueprint $table) {
            $table->foreignId('engine_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel_mode')->default('stereo');
        });
        Schema::create('engine_configurations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('engine_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('revision');
            $table->json('snapshot');
            $table->unique(['engine_id', 'revision']);
        });
        Schema::create('engine_commands', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('engine_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->string('zone_id');
            $table->unsignedBigInteger('config_revision');
            $table->string('action');
            $table->timestamp('expires_at');
            $table->json('result')->nullable();
            $table->timestamps();
            $table->unique(['engine_id', 'sequence']);
        });
        Schema::create('engine_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('engine_id')->constrained()->cascadeOnDelete();
            $table->uuid('session_id');
            $table->unsignedBigInteger('sequence');
            $table->string('payload_hash', 64);
            $table->unique(['engine_id', 'session_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('engine_reports');
        Schema::dropIfExists('engine_commands');
        Schema::dropIfExists('engine_configurations');
        Schema::table('zones', function (Blueprint $table) {
            $table->dropConstrainedForeignId('engine_id');
            $table->dropColumn('channel_mode');
        });
        Schema::dropIfExists('engines');
    }
};
