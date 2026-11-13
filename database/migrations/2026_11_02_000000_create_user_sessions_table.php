<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('session_token')->unique();
            $table->string('device_fingerprint')->nullable();
            $table->string('device_name')->nullable();
            $table->string('ip_address')->nullable();
            // Best-effort IP geolocation, resolved asynchronously (see
            // App\Jobs\CheckLoginLocationAnomaly) — never blocks login, and
            // stays null when the lookup fails or the IP is private/local.
            $table->string('country')->nullable();
            $table->timestamp('last_seen_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_sessions');
    }
};
