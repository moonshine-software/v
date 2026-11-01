<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use MoonShine\Laravel\Models\MoonshineUser;
use Override;

/**
 * @property int $id
 * @property int $moonshine_user_id
 * @property string $tenant_id
 * @property string $provider_service
 * @property string $subject_id
 * @property string|null $refresh_token
 * @property Carbon|null $access_token_expires_at
 */
final class MoonshineUserUpstreamIdentity extends Model
{
    #[Override]
    protected $table = 'moonshine_user_upstream_identities';

    #[Override]
    protected $fillable = [
        'moonshine_user_id',
        'tenant_id',
        'provider_service',
        'subject_id',
        'refresh_token',
        'access_token_expires_at',
    ];

    #[Override]
    protected $casts = [
        'refresh_token' => 'encrypted',
        'access_token_expires_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<MoonshineUser, $this>
     */
    public function moonshineUser(): BelongsTo
    {
        return $this->belongsTo(MoonshineUser::class, 'moonshine_user_id');
    }
}
