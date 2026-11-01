<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Override;

/**
 * @property int $id
 * @property int $api_admin_tenant_id
 * @property string $name
 * @property string $token_hash
 * @property array<int, string> $scopes
 * @property Carbon|null $last_used_at
 * @property Carbon|null $revoked_at
 * @property array<string, mixed>|null $metadata
 */
#[Fillable(['api_admin_tenant_id', 'name', 'token_hash', 'scopes', 'last_used_at', 'revoked_at', 'metadata'])]
class ApiAdminMcpToken extends Model
{
    #[Override]
    protected $table = 'api_admin_mcp_tokens';

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function findValidToken(string $token): ?self
    {
        $record = self::query()
            ->where('token_hash', self::hashToken($token))
            ->whereNull('revoked_at')
            ->first();

        return $record instanceof self ? $record : null;
    }

    public function markUsed(CarbonInterface $when): void
    {
        $this->forceFill(['last_used_at' => $when])->save();
    }

    public function tokenIdFingerprint(): string
    {
        return substr(hash('sha256', (string) $this->id), 0, 12);
    }

    /**
     * @return BelongsTo<ApiAdminTenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(ApiAdminTenant::class, 'api_admin_tenant_id');
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
