<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('address')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('timezone', 64)->default('Asia/Manila'); // Upgrade 16
            $table->string('isp', 32)->nullable();                  // PLDT, Globe, Converge, Starlink...
            $table->boolean('has_ups')->default(false);             // Upgrade 1: enables power-loss detection
            $table->timestamps();
            $table->softDeletes();
        });

        // Upgrade 14: OTA releases for the edge agent
        Schema::create('agent_releases', function (Blueprint $table) {
            $table->id();
            $table->string('version', 32)->unique();
            $table->string('channel', 16)->default('stable');  // canary, beta, stable
            $table->string('artifact_url');
            $table->string('sha256', 64);
            $table->text('signature');                         // ed25519 over sha256
            $table->unsignedTinyInteger('rollout_percent')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::create('edge_agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->uuid('agent_uid')->unique();
            $table->string('name')->nullable();
            // One-time 8-char code the installer types on the agent (sha256, cleared after use)
            $table->string('enrollment_code_hash', 64)->nullable()->unique();
            $table->timestamp('enrollment_expires_at')->nullable();
            $table->timestamp('enrolled_at')->nullable();
            $table->string('hostname')->nullable();
            $table->string('arch', 16)->nullable();
            // Upgrade 4: mTLS identity; revoke by clearing / adding to CRL
            $table->string('cert_fingerprint', 64)->nullable()->unique();
            $table->timestamp('cert_expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            // Upgrade 14
            $table->string('version', 32)->nullable();
            $table->foreignId('target_release_id')->nullable()->constrained('agent_releases')->nullOnDelete();
            // Upgrade 12: which data-plane node owns this tunnel
            $table->string('assigned_node', 64)->nullable()->index();
            // Upgrade 2: uplink state
            $table->string('uplink_primary', 16)->default('wired');  // wired, wifi, lte
            $table->boolean('lte_failover_enabled')->default(false);
            $table->string('uplink_active', 16)->nullable();
            // Upgrade 1
            $table->string('status', 24)->default('pending')->index(); // pending, online, offline
            $table->timestamp('last_heartbeat_at')->nullable()->index();
            $table->unsignedBigInteger('uptime_seconds')->nullable();
            $table->unsignedInteger('buffered_events')->default(0);
            $table->string('public_ip', 45)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edge_agents');
        Schema::dropIfExists('agent_releases');
        Schema::dropIfExists('sites');
    }
};
