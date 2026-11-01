<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_admin_tenant_memberships', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('api_admin_tenants')->cascadeOnDelete();
            $table->foreignId('moonshine_user_id')->constrained('moonshine_users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'moonshine_user_id'], 'api_admin_tenant_member_unique');
        });
        Schema::create('api_admin_tenant_secrets', static function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id', 64);
            $table->string('service', 190);
            $table->string('name', 190);
            $table->text('value');
            $table->timestamps();
            $table->unique(['tenant_id', 'service', 'name'], 'api_admin_tenant_secret_unique');
        });
        Schema::table('moonshine_user_upstream_identities', static function (Blueprint $table): void {
            // Legacy identities are local only: never infer tenant ownership from an email.
            $table->string('tenant_id', 64)->default('local');
            $table->dropUnique('moonshine_user_upstream_provider_subject_unique');
            $table->unique(['tenant_id', 'provider_service', 'subject_id'], 'moonshine_upstream_tenant_subject_unique');
        });
    }

    public function down(): void
    {
        // Removing hosted identities prevents an unsafe cross-tenant merge on rollback.
        DB::table('moonshine_user_upstream_identities')->where('tenant_id', '!=', 'local')->delete();
        Schema::table('moonshine_user_upstream_identities', static function (Blueprint $table): void {
            $table->dropUnique('moonshine_upstream_tenant_subject_unique');
            $table->dropColumn('tenant_id');
            $table->unique(['provider_service', 'subject_id'], 'moonshine_user_upstream_provider_subject_unique');
        });
        Schema::dropIfExists('api_admin_tenant_secrets');
        Schema::dropIfExists('api_admin_tenant_memberships');
    }
};
