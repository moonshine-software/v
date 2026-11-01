<?php

declare(strict_types=1);

namespace App\ApiAdmin\Auth;

use App\ApiAdmin\Http\OutboundHttp;
use App\ApiAdmin\Tenancy\TenantContext;
use App\ApiAdmin\Tenancy\TenantResolver;
use App\Models\MoonshineUserUpstreamIdentity;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use MoonShine\Laravel\Models\MoonshineUser;
use MoonShine\Laravel\Models\MoonshineUserRole;
use Override;
use SensitiveParameter;
use Throwable;

/**
 * Session user provider for per-panel upstream-backed admin auth.
 *
 * The provider receives primitive service settings from ApiAdminServiceProvider
 * so the Auth module stays independent from ServiceConfig. It validates the
 * current delegated token by calling the upstream identity endpoint, then
 * upserts a local MoonShine user and links it by (service, upstream subject).
 */
final readonly class UpstreamUserProvider implements UserProvider
{
    public function __construct(
        private string $serviceName,
        private string $baseUrl,
        private string $validateEndpoint = '',
        private string $healthPath = '',
        private string $mode = 'delegated_token',
        private string $tenantId = TenantContext::LOCAL_ID,
    ) {}

    public static function guardName(string $panel, ?string $tenantId = null): string
    {
        $tenantId ??= app()->bound(TenantResolver::class)
            ? app()->get(TenantResolver::class)->resolve()?->id
            : TenantContext::LOCAL_ID;
        $suffix = $tenantId !== null && $tenantId !== TenantContext::LOCAL_ID
            ? '-'.hash('sha256', $tenantId)
            : '';

        return 'api-admin-upstream-'.Str::slug($panel).$suffix;
    }

    #[Override]
    public function retrieveById($identifier): ?Authenticatable
    {
        $user = MoonshineUser::query()->find($identifier);

        if (! $user instanceof MoonshineUser || ! $this->ownsIdentity($user)) {
            return null;
        }

        $state = $this->currentTokenState();
        if ($state === null) {
            return null;
        }

        try {
            $updated = $this->syncUserFromToken($state);

            return $updated?->getKey() === $user->getKey() ? $updated : null;
        } catch (Throwable $e) {
            Log::warning('[UpstreamUserProvider.retrieveById] upstream sync failed', [
                'service' => $this->serviceName,
                'mode' => $this->mode,
                'exception' => $e::class,
            ]);

            return null;
        }
    }

    #[Override]
    public function retrieveByToken($identifier, #[SensitiveParameter] $token): ?Authenticatable
    {
        $user = MoonshineUser::query()
            ->whereKey($identifier)
            ->where('remember_token', $token)
            ->first();

        return $user instanceof MoonshineUser && $this->ownsIdentity($user) ? $user : null;
    }

    #[Override]
    public function updateRememberToken(Authenticatable $user, #[SensitiveParameter] $token): void
    {
        if (! $user instanceof Model) {
            return;
        }

        $user->forceFill(['remember_token' => $token])->save();
    }

    /**
     * @param array<string, mixed> $credentials
     */
    #[Override]
    public function retrieveByCredentials(#[SensitiveParameter] array $credentials): ?Authenticatable
    {
        $state = $this->tokenStateFromCredentials($credentials) ?? $this->currentTokenState();

        if ($state === null) {
            Log::debug('[UpstreamUserProvider.retrieveByCredentials] missing delegated token', [
                'service' => $this->serviceName,
                'mode' => $this->mode,
            ]);

            return null;
        }

        try {
            return $this->syncUserFromToken($state);
        } catch (Throwable $e) {
            Log::warning('[UpstreamUserProvider.retrieveByCredentials] upstream validation failed', [
                'service' => $this->serviceName,
                'mode' => $this->mode,
                'exception' => $e::class,
            ]);

            return null;
        }
    }

    /**
     * retrieveByCredentials validates against upstream before returning a user,
     * so Laravel's second validation step can trust that result.
     *
     * @param array<string, mixed> $credentials
     */
    #[Override]
    public function validateCredentials(Authenticatable $user, #[SensitiveParameter] array $credentials): bool
    {
        return $user instanceof MoonshineUser && $this->ownsIdentity($user);
    }

    /**
     * Upstream-backed users do not store local passwords.
     *
     * @param array<string, mixed> $credentials
     */
    #[Override]
    public function rehashPasswordIfRequired(Authenticatable $user, #[SensitiveParameter] array $credentials, bool $force = false): void
    {
        //
    }

    /**
     * @param array{access_token: string, refresh_token: string, expires_at: int} $state
     */
    private function syncUserFromToken(array $state): ?MoonshineUser
    {
        if (config('api-admin.hosted.mode', 'local') === 'hosted'
            && (! app()->bound(TenantResolver::class) || app()->get(TenantResolver::class)->resolve()?->id !== $this->tenantId)) {
            return null;
        }
        $response = $this->fetchIdentity($state['access_token']);

        if (! $response->successful()) {
            Log::warning('[UpstreamUserProvider.syncUserFromToken] identity endpoint rejected token', [
                'service' => $this->serviceName,
                'mode' => $this->mode,
                'endpoint' => $this->identityEndpoint(),
                'status' => $response->status(),
            ]);

            return null;
        }

        $profile = $this->profileFromResponse($response);
        if ($profile === null) {
            Log::warning('[UpstreamUserProvider.syncUserFromToken] identity payload missing subject', [
                'service' => $this->serviceName,
                'mode' => $this->mode,
                'endpoint' => $this->identityEndpoint(),
            ]);

            return null;
        }

        return $this->linkProfile($profile, $state['refresh_token'], $state['expires_at']);
    }

    /**
     * Link a subject the upstream has just authenticated but cannot describe:
     * password and session grants do not require an identity endpoint.
     */
    public function linkAuthenticatedSubject(string $subject): MoonshineUser
    {
        return $this->linkProfile([
            'subject_id' => $subject,
            'email' => Str::slug($this->serviceName).'-'.Str::slug($subject).'@api-admin.local',
            'name' => $subject,
            'avatar' => null,
        ], '', 0);
    }

    /**
     * @param array{subject_id: string, email: string, name: string, avatar: string|null} $profile
     */
    private function linkProfile(array $profile, string $refreshToken, int $expiresAt): MoonshineUser
    {
        $identity = MoonshineUserUpstreamIdentity::query()
            ->where('tenant_id', $this->tenantId)
            ->where('provider_service', $this->serviceName)
            ->where('subject_id', $profile['subject_id'])
            ->first();

        $user = $identity instanceof MoonshineUserUpstreamIdentity
            ? MoonshineUser::query()->find($identity->moonshine_user_id)
            : null;

        $isNewUser = ! $user instanceof MoonshineUser;
        $user ??= new MoonshineUser;
        $attributes = [
            'email' => $this->localEmail($profile['subject_id'], $profile['email'], $user),
            'name' => $profile['name'],
            'avatar' => $profile['avatar'],
            'moonshine_user_role_id' => $user->moonshine_user_role_id ?: MoonshineUserRole::DEFAULT_ROLE_ID,
        ];

        if ($isNewUser) {
            // Upstream-linked users must not have a known local password.
            $attributes['password'] = Hash::make(Str::random(64));
        }

        $user->forceFill($attributes);
        $user->save();

        MoonshineUserUpstreamIdentity::query()->updateOrCreate(
            [
                'tenant_id' => $this->tenantId,
                'provider_service' => $this->serviceName,
                'subject_id' => $profile['subject_id'],
            ],
            [
                'moonshine_user_id' => $user->getKey(),
                'refresh_token' => $refreshToken !== '' ? $refreshToken : null,
                'access_token_expires_at' => $expiresAt > 0
                    ? Carbon::createFromTimestamp($expiresAt)
                    : null,
            ],
        );

        Log::info('[UpstreamUserProvider.linkProfile] upstream identity linked', [
            'service' => $this->serviceName,
            'mode' => $this->mode,
            'user_id' => $user->getKey(),
            'subject_id' => $profile['subject_id'],
        ]);

        return $user;
    }

    private function fetchIdentity(string $token): Response
    {
        $endpoint = $this->identityEndpoint();
        $request = OutboundHttp::client($this->baseUrl)
            ->connectTimeout(5)
            ->timeout(15)
            ->acceptJson()
            ->withToken($token);

        return $request->get($endpoint);
    }

    private function identityEndpoint(): string
    {
        if ($this->validateEndpoint !== '') {
            return $this->validateEndpoint;
        }

        if ($this->healthPath !== '') {
            return $this->healthPath;
        }

        return '/me';
    }

    /**
     * @return array{subject_id: string, email: string, name: string, avatar: string|null}|null
     */
    private function profileFromResponse(Response $response): ?array
    {
        $payload = $response->json();
        if (! is_array($payload)) {
            return null;
        }
        $payload = $this->stringKeyedArray($payload);

        $subject = $this->firstString($payload, ['id', 'sub', 'subject', 'uuid', 'user.id', 'data.id', 'data.sub']);
        if ($subject === '') {
            return null;
        }

        $email = $this->firstString($payload, ['email', 'user.email', 'data.email']);
        if ($email === '') {
            $email = Str::slug($this->serviceName).'-'.$subject.'@api-admin.local';
        }

        $name = $this->firstString($payload, ['name', 'username', 'login', 'user.name', 'data.name', 'data.username', 'data.login']);
        if ($name === '') {
            $name = $email;
        }

        $avatar = $this->firstString($payload, ['avatar', 'avatar_url', 'picture', 'user.avatar', 'data.avatar', 'data.picture']);

        return [
            'subject_id' => $subject,
            'email' => $email,
            'name' => $name,
            'avatar' => $avatar !== '' ? $avatar : null,
        ];
    }

    /**
     * @param array<mixed, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function stringKeyedArray(array $payload): array
    {
        $result = [];
        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $keys
     */
    private function firstString(array $payload, array $keys): string
    {
        foreach ($keys as $key) {
            $value = data_get($payload, $key);

            if (is_int($value)) {
                return (string) $value;
            }

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }

    private function ownsIdentity(MoonshineUser $user): bool
    {
        if (config('api-admin.hosted.mode', 'local') === 'hosted'
            && (! app()->bound(TenantResolver::class) || app()->get(TenantResolver::class)->resolve()?->id !== $this->tenantId)) {
            return false;
        }

        return MoonshineUserUpstreamIdentity::query()
            ->where('tenant_id', $this->tenantId)
            ->where('provider_service', $this->serviceName)
            ->where('moonshine_user_id', $user->getKey())
            ->exists();
    }

    private function localEmail(string $subject, string $email, MoonshineUser $user): string
    {
        // Email is display data from an upstream, never proof of an existing local account.
        if ($this->tenantId !== TenantContext::LOCAL_ID
            || MoonshineUser::query()->where('email', $email)->whereKeyNot($user->getKey() ?? 0)->exists()) {
            return hash('sha256', $this->tenantId.'\0'.$this->serviceName.'\0'.$subject).'@api-admin.local';
        }

        return $email;
    }

    /**
     * @param array<string, mixed> $credentials
     *
     * @return array{access_token: string, refresh_token: string, expires_at: int}|null
     */
    private function tokenStateFromCredentials(array $credentials): ?array
    {
        $accessToken = $credentials['access_token'] ?? $credentials['token'] ?? null;
        if (! is_string($accessToken) || $accessToken === '') {
            return null;
        }

        $refreshToken = $credentials['refresh_token'] ?? '';
        $expiresAt = $credentials['expires_at'] ?? 0;

        return [
            'access_token' => $accessToken,
            'refresh_token' => is_string($refreshToken) ? $refreshToken : '',
            'expires_at' => is_int($expiresAt) ? $expiresAt : (is_numeric($expiresAt) ? (int) $expiresAt : 0),
        ];
    }

    /**
     * @return array{access_token: string, refresh_token: string, expires_at: int}|null
     */
    private function currentTokenState(): ?array
    {
        try {
            /** @var Request|null $request */
            $request = app()->bound('request') ? app('request') : null;
        } catch (Throwable) {
            return null;
        }

        if (! $request instanceof Request || ! $request->hasSession()) {
            return null;
        }

        $state = $this->mode === 'oauth2_authorization_code'
            ? DelegatedAuthSession::oauth($request, $this->serviceName)
            : DelegatedAuthSession::token($request, $this->serviceName);

        if ($state === null) {
            return null;
        }

        return [
            'access_token' => $state['access_token'],
            'refresh_token' => is_string($state['refresh_token'] ?? null) ? $state['refresh_token'] : '',
            'expires_at' => is_int($state['expires_at'] ?? null) ? $state['expires_at'] : 0,
        ];
    }
}
