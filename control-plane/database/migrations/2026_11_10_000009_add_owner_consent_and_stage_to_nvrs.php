<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nvrs', function (Blueprint $table) {
            // RA 10173: we connect with the NVR's local account, bypassing the brand cloud's sharing
            // controls, so the owner's authorization is recorded here instead.
            $table->timestamp('owner_consent_at')->nullable()->after('credential_ref');
            $table->foreignId('owner_consent_by')->nullable()->after('owner_consent_at')->constrained('users')->nullOnDelete();
            // Live progress while onboarding: tunnel -> probe -> save
            $table->string('onboarding_stage', 16)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('nvrs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('owner_consent_by');
            $table->dropColumn(['owner_consent_at', 'onboarding_stage']);
        });
    }
};
