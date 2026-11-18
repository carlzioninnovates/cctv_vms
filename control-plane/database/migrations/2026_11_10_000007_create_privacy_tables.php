<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Upgrade 5: Data Privacy Act of 2012 (RA 10173)
        Schema::create('retention_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->cascadeOnDelete(); // null = org default
            $table->unsignedSmallInteger('event_days')->default(90);
            $table->unsignedSmallInteger('snapshot_days')->default(30);
            $table->unsignedSmallInteger('clip_days')->default(30);
            $table->unsignedSmallInteger('audit_log_days')->default(365);
            $table->timestamps();
            $table->unique(['organization_id', 'site_id']);
        });

        Schema::create('privacy_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('dpo_name')->nullable();
            $table->string('dpo_contact')->nullable();
            $table->text('purpose');                           // why the footage is collected
            $table->boolean('signage_posted')->default(false);
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Append-only. Who viewed/exported/changed what.
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 48)->index();             // live.view, playback.view, clip.export, nvr.update...
            $table->string('subject_type', 64)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('context')->nullable();               // channel, time range, etc.
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('privacy_notices');
        Schema::dropIfExists('retention_policies');
    }
};
