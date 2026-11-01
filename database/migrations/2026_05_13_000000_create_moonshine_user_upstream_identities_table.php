<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('moonshine_user_upstream_identities', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('moonshine_user_id')
                ->constrained('moonshine_users')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            $table->string('provider_service', 190);
            $table->string('subject_id', 190);
            $table->text('refresh_token')->nullable();
            $table->timestamp('access_token_expires_at')->nullable();
            $table->timestamps();

            $table->unique(['provider_service', 'subject_id'], 'moonshine_user_upstream_provider_subject_unique');
            $table->index(['moonshine_user_id', 'provider_service'], 'moonshine_user_upstream_user_provider_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('moonshine_user_upstream_identities');
    }
};
