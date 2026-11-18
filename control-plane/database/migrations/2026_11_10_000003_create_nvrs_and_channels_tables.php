<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Upgrade 4: known-vulnerable firmware, matched against nvrs.firmware
        Schema::create('firmware_advisories', function (Blueprint $table) {
            $table->id();
            $table->string('brand', 16)->index();
            $table->string('cve', 32)->nullable()->unique(); // nulls allowed for vendor-only advisories
            $table->string('severity', 16);                    // critical, high, medium
            $table->string('model_pattern')->nullable();       // SQL LIKE / regex on model
            $table->string('fixed_in_build')->nullable();      // firmware build that fixes it
            $table->string('summary');
            $table->string('reference_url')->nullable();
            $table->timestamps();
        });

        Schema::create('nvrs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('edge_agent_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('brand', 16)->index();              // App\Enums\DeviceBrand
            $table->string('connection_mode', 32)->default('edge_agent'); // App\Enums\ConnectionMode
            // Reached through the agent; these are LAN values
            $table->string('lan_host', 255)->nullable();
            $table->unsignedSmallInteger('http_port')->default(80);
            $table->unsignedSmallInteger('rtsp_port')->default(554);
            $table->unsignedSmallInteger('onvif_port')->default(80);
            // Upgrade 4: password is NOT stored here. Key into the data-plane secret store.
            $table->string('credential_ref', 64)->nullable();
            // Filled by the capability probe
            $table->string('model')->nullable();
            $table->string('serial', 64)->nullable()->index();
            $table->string('firmware')->nullable();
            $table->string('firmware_region', 8)->default('unknown'); // intl, cn, unknown
            $table->json('capabilities')->nullable();          // full probe result
            $table->timestamp('probed_at')->nullable();
            $table->json('probe_warnings')->nullable();
            $table->integer('clock_drift_seconds')->nullable();  // Upgrade 16
            // pending -> onboarding -> active | failed ; active <-> offline
            $table->string('status', 24)->default('pending')->index();
            $table->string('onboarding_error')->nullable();      // shown to the installer
            $table->string('onboarding_error_code', 32)->nullable(); // lan_unreachable, bad_credentials, ...
            $table->json('tunnel_ports')->nullable();             // {"80": 40000, "554": 40001}
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['brand', 'serial']);
        });

        Schema::create('nvr_firmware_advisory', function (Blueprint $table) {
            $table->foreignId('nvr_id')->constrained()->cascadeOnDelete();
            $table->foreignId('firmware_advisory_id')->constrained()->cascadeOnDelete();
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->primary(['nvr_id', 'firmware_advisory_id']);
        });

        Schema::create('channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nvr_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('number');            // 1-based, as the NVR numbers it
            $table->string('name')->nullable();
            $table->boolean('enabled')->default(true);
            $table->boolean('online')->default(false);
            // Codec drives the WebRTC vs HLS decision (H.265 often isn't browser-playable over WebRTC)
            $table->string('main_codec', 8)->default('unknown');
            $table->string('sub_codec', 8)->default('unknown');
            $table->string('main_resolution', 16)->nullable();
            $table->string('sub_resolution', 16)->nullable();
            $table->boolean('ptz')->default(false);
            $table->json('analytics')->nullable();             // ["line_crossing","intrusion",...] as probed
            $table->string('onvif_profile_token')->nullable();
            // Upgrade 7: grid default for low-bandwidth sites
            $table->string('default_view_mode', 16)->default('sub'); // snapshot, sub, main
            $table->unsignedSmallInteger('snapshot_interval_seconds')->default(5);
            // Upgrade 3
            $table->boolean('recording')->nullable();
            $table->timestamps();

            $table->unique(['nvr_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channels');
        Schema::dropIfExists('nvr_firmware_advisory');
        Schema::dropIfExists('nvrs');
        Schema::dropIfExists('firmware_advisories');
    }
};
