<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Written in batches from Redis stream vms:events (contracts/schemas/event.v1.json).
        // On Postgres, partition by month on occurred_at once volume grows.
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_uid')->unique();               // idempotency: agent may resend
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete(); // denormalized for tenant queries
            $table->foreignId('nvr_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 24)->index();               // App\Enums\EventType
            $table->string('state', 8);                        // start, stop, pulse
            $table->string('raw_type', 64)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('received_at');
            $table->boolean('buffered')->default(false);       // Upgrade 2
            // Upgrade 6
            $table->string('ai_verdict', 16)->nullable();      // confirmed, rejected, skipped
            $table->string('ai_label', 32)->nullable();
            $table->decimal('ai_confidence', 4, 3)->nullable();
            // Upgrade 8
            $table->string('snapshot_key')->nullable();
            $table->string('clip_key')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'occurred_at']);
            $table->index(['nvr_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
