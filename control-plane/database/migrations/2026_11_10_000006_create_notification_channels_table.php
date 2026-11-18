<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Upgrade 9
        Schema::create('notification_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16);                        // App\Enums\NotificationChannelType
            $table->text('address');                           // encrypted cast: phone, chat id, FCM token
            $table->json('event_types')->nullable();           // null = all
            $table->json('site_ids')->nullable();              // null = all
            $table->boolean('only_ai_confirmed')->default(true);
            $table->time('quiet_from')->nullable();
            $table->time('quiet_until')->nullable();
            $table->boolean('verified')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_channels');
    }
};
