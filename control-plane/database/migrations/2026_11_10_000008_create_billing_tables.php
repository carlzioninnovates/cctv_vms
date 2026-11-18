<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Upgrade 11: GCash / Maya / cards via PayMongo or Xendit. Amounts in centavos.
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete(); // null = platform plan; set = reseller's own plan
            $table->string('code', 32);
            $table->string('name');
            $table->unsignedInteger('price_centavos');
            $table->string('interval', 16)->default('month');  // month, year, prepaid_30d
            $table->unsignedSmallInteger('max_channels');
            $table->unsignedSmallInteger('event_retention_days')->default(30);
            $table->unsignedInteger('cloud_clip_storage_mb')->default(0); // Upgrade 8
            $table->boolean('ai_verification')->default(false);           // Upgrade 6
            $table->boolean('main_stream_transcode')->default(false);
            $table->boolean('lte_failover')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'code']);
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->string('status', 16)->index();             // trialing, active, past_due, cancelled
            $table->timestamp('current_period_start');
            $table->timestamp('current_period_end')->index();
            $table->timestamp('grace_until')->nullable();      // keep streaming a few days after expiry
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 16);                    // App\Enums\PaymentProvider
            $table->string('provider_ref', 128);
            $table->string('method', 16)->nullable();          // gcash, maya, card, grab_pay, bank
            $table->unsignedInteger('amount_centavos');
            $table->char('currency', 3)->default('PHP');
            $table->string('status', 16)->index();             // pending, paid, failed, refunded
            $table->timestamp('paid_at')->nullable();
            $table->json('raw_webhook')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'provider_ref']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');
    }
};
