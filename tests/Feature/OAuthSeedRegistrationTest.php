<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\ApiAdmin\Auth\OAuth2TokenStore;
use App\ApiAdmin\MCP\Tools\RegisterServiceTool;
use App\ApiAdmin\Providers\ApiAdminServiceProvider;
use App\ApiAdmin\Tenancy\TenantContext;
use App\ApiAdmin\Tenancy\TenantKeyBuilder;
use App\ApiAdmin\Tenancy\TenantResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class OAuthSeedRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    private string $originalBasePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalBasePath = base_path();
        $this->directory = sys_get_temp_dir().'/moonshine-seed-registration-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->directory.'/config');
        app()->setBasePath($this->directory);
        File::put(base_path('.env'), '');
        File::put(config_path('api-admin.php'), '<?php return ["services" => []];');
        File::put(config_path('moonshine.php'), '<?php return ["panels" => []];');
        config([
            'api-admin.services' => [],
            'api-admin.configs_path' => $this->directory.'/Configs',
            'api-admin.layouts_path' => $this->directory.'/Layouts',
            'api-admin.generated_services_path' => $this->directory.'/services.php',
            'api-admin.generated_panels_path' => $this->directory.'/panels.php',
        ]);
    }

    protected function tearDown(): void
    {
        foreach (['API_URL', 'API_KEY', 'HEALTH_PATH', 'OAUTH_PASSWORD', 'OAUTH_REFRESH_TOKEN', 'SESSION_PASSWORD', 'WEBHOOK_SECRET'] as $suffix) {
            $key = 'SEED_AUDIT_'.$suffix;
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }
        app()->setBasePath($this->originalBasePath);
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    #[DataProvider('registrationModes')]
    public function test_omitted_and_unchanged_seed_preserve_legacy_rotation(string $mode): void
    {
        $tenant = $this->tenant($mode);
        $this->register(['oauth_refresh_token' => 'old-seed']);
        $key = $this->legacyKey($tenant);
        Cache::put($key, 'legacy-rotation', 3600);

        $this->register();
        $this->assertSame('old-seed', ApiAdminServiceProvider::resolveService('seed-audit')->oauthRefreshToken);
        $this->assertSame('legacy-rotation', Cache::get($key));
        $this->register(['oauth_refresh_token' => 'old-seed']);
        $this->assertSame('legacy-rotation', Cache::get($key));
        Http::fake(['https://idp.test/token' => Http::response(['access_token' => 'access'])]);
        ApiAdminServiceProvider::resolveService('seed-audit')->oauthTokenStore()->getAccessToken(OAuth2TokenStore::GRANT_REFRESH_TOKEN);
        Http::assertSent(static fn (Request $request): bool => $request['refresh_token'] === 'legacy-rotation');
    }

    #[DataProvider('registrationModes')]
    public function test_changed_seed_replaces_legacy_rotation_without_clearing_other_services(string $mode): void
    {
        $tenant = $this->tenant($mode);
        $this->register(['oauth_refresh_token' => 'old-seed']);
        $key = $this->legacyKey($tenant);
        $otherKey = $this->legacyKey($tenant, 'another-service');
        Cache::put($key, 'old-rotation', 3600);
        Cache::put($otherKey, 'unrelated-rotation', 3600);

        $this->register(['oauth_refresh_token' => 'new-seed']);

        $this->assertNull(Cache::get($key));
        $this->assertSame('unrelated-rotation', Cache::get($otherKey));
        Http::fake(['https://idp.test/token' => Http::response(['access_token' => 'access'])]);
        ApiAdminServiceProvider::resolveService('seed-audit')->oauthTokenStore()->getAccessToken(OAuth2TokenStore::GRANT_REFRESH_TOKEN);
        Http::assertSent(static fn (Request $request): bool => $request['refresh_token'] === 'new-seed');
    }

    /** @return array<string, array{string}> */
    public static function registrationModes(): array
    {
        return ['local' => ['local'], 'hosted' => ['hosted']];
    }

    private function tenant(string $mode): string
    {
        $context = $mode === 'local' ? TenantContext::local() : new TenantContext('tenant-a', 'acme', 'Acme', 'hosted', false);
        config(['api-admin.hosted.mode' => $mode]);
        app()->instance(TenantResolver::class, new readonly class($context) implements TenantResolver
        {
            public function __construct(private TenantContext $context) {}

            public function resolve(): TenantContext
            {
                return $this->context;
            }
        });

        return $context->id;
    }

    /** @param array{oauth_refresh_token?: string} $overrides */
    private function register(array $overrides = []): void
    {
        $result = new RegisterServiceTool()->execute([
            'name' => 'seed-audit', 'base_url' => 'https://api.test', 'auth_type' => 'oauth2_refresh_token',
            'token_url' => 'https://idp.test/token', 'oauth_client_id' => 'client', 'oauth_client_secret' => 'secret',
            ...$overrides,
        ]);
        $this->assertTrue($result['success'], json_encode($result, JSON_THROW_ON_ERROR));
    }

    private function legacyKey(string $tenant, string $service = 'seed-audit'): string
    {
        return TenantKeyBuilder::cacheKey($tenant, 'oauth2', $service, 'refresh', md5('client|https://idp.test/token'));
    }
}
