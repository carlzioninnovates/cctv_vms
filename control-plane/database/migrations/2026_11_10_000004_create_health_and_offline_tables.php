<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Upgrade 3: "is it actually recording?"
        Schema::create('device_health_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nvr_id')->constrained()->cascadeOnDelete();
            $table->json('disks');                             // [{id,status,capacity_mb,free_mb}]
            $table->boolean('all_disks_ok');
            $table->json('recording_channels');                // [1,2,4]
            $table->json('not_recording_channels');            // [3]
            $table->timestamp('checked_at')->index();
            $table->timestamps();
        });

        // Upgrade 1: why a site/NVR went offline
        Schema::create('offline_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('nvr_id')->nullable()->constrained()->cascadeOnDelete(); // null = whole site
            $table->string('cause', 32)->index();              // App\Enums\OfflineCause
            $table->string('cause_detail')->nullable();
            $table->timestamp('started_at')->index();
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('reclassified_at')->nullable();  // e.g. site_unreachable -> power_loss on reconnect
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offline_incidents');
        Schema::dropIfExists('device_health_snapshots');
    }
};
