<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Upgrade 10: platform -> reseller (installer) -> client
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('organizations')->restrictOnDelete();
            $table->string('type', 16)->index();            // App\Enums\OrganizationType
            $table->string('name');
            $table->string('slug')->unique();
            // White-label (resellers)
            $table->string('brand_name')->nullable();
            $table->string('brand_logo_path')->nullable();
            $table->string('custom_domain')->nullable()->unique();
            $table->string('support_contact')->nullable();
            // Reseller revenue share, in basis points (1500 = 15%)
            $table->unsignedSmallInteger('revenue_share_bps')->default(0);
            $table->string('status', 16)->default('active'); // active, suspended
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->string('role', 16)->default('viewer')->after('organization_id'); // owner, admin, operator, viewer
            $table->string('phone', 20)->nullable()->after('email');
            $table->string('timezone', 64)->default('Asia/Manila');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('organization_id');
            $table->dropColumn(['role', 'phone', 'timezone']);
        });
        Schema::dropIfExists('organizations');
    }
};
