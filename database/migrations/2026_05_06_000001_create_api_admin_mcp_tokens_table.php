<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_admin_mcp_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('api_admin_tenant_id')->constrained('api_admin_tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('token_hash', 64)->unique();
            $table->json('scopes');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['api_admin_tenant_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_admin_mcp_tokens');
    }
};
