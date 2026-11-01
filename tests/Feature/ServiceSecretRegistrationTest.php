<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\ApiAdmin\MCP\McpServer;
use App\ApiAdmin\Providers\ApiAdminServiceProvider;
use App\ApiAdmin\Tenancy\TenantContext;
use App\ApiAdmin\Tenancy\TenantResolver;
use App\ApiAdmin\Tenancy\TenantSecretStore;
use Dotenv\Dotenv;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ServiceSecretRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    private string $originalBasePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalBasePath = base_path();
        $this->directory = sys_get_temp_dir().'/moonshine-secret-registration-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->directory.'/config');
        app()->setBasePath($this->directory);
        File::put(base_path('.env'), "AUDIT_EXPANSION=expanded\n");
        File::put(config_path('api-admin.php'), '<?php return ["services" => []];');
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
            $key = 'SECRET_AUDIT_'.$suffix;
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }
        app()->setBasePath($this->originalBasePath);
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    #[DataProvider('registrationModes')]
    public function test_omitted_secrets_are_preserved_and_explicit_empty_values_clear_them(string $mode): void
    {
        $this->tenant($mode);
        $secrets = [
            'api_key' => 'original-key',
            'oauth_client_secret' => 'original-client-secret',
            'oauth_password' => 'original-password',
            'oauth_refresh_token' => 'original-refresh',
            'session_password' => 'original-session-password',
        ];
        $this->register([...$secrets, 'realtime' => ['webhook_secret' => 'original-webhook']]);
        $this->register();
        $resolved = ApiAdminServiceProvider::resolveService('secret-audit')->toArray();
        foreach ($secrets as $key => $value) {
            $this->assertSame($value, $resolved[$key]);
        }
        $this->assertSame('original-webhook', data_get($resolved, 'realtime.webhook_secret'));

        $store = new TenantSecretStore;
        $store->putMany('other-tenant', 'secret-audit', ['api_key' => 'unrelated-key']);
        $this->register([
            ...array_fill_keys(array_keys($secrets), ''),
            'realtime' => ['enabled' => false, 'webhook_secret' => ''],
        ]);
        $resolved = ApiAdminServiceProvider::resolveService('secret-audit')->toArray();
        foreach (array_keys($secrets) as $key) {
            $this->assertSame('', $resolved[$key]);
            if ($mode === 'hosted') {
                $this->assertSame('', $store->get('tenant-a', 'secret-audit', $key, 'missing'));
            }
        }
        $this->assertSame('', data_get($resolved, 'realtime.webhook_secret'));
        $this->assertSame('unrelated-key', $store->get('other-tenant', 'secret-audit', 'api_key'));
        $this->assertSame(['Authorization' => 'Bearer '], ApiAdminServiceProvider::resolveService('secret-audit')->authHeaders());
    }

    public function test_hosted_oauth_registration_reuses_the_saved_client_secret(): void
    {
        $this->tenant('hosted');
        $oauth = [
            'auth_type' => 'oauth2_cc',
            'token_url' => 'https://idp.test/token',
            'oauth_client_id' => 'client',
        ];
        $this->register([...$oauth, 'oauth_client_secret' => 'saved-client-secret']);
        $this->register($oauth);
        $this->assertSame('saved-client-secret', ApiAdminServiceProvider::resolveService('secret-audit')->oauthClientSecret);

        $before = File::get($this->directory.'/services.php');
        $result = new McpServer()->executeTool('register_service', [
            'name' => 'secret-audit', 'base_url' => 'https://api.test',
            ...$oauth, 'oauth_client_secret' => '',
        ]);
        $this->assertFalse($result['success']);
        $this->assertSame('VALIDATION', $result['code']);
        $this->assertSame($before, File::get($this->directory.'/services.php'));
        $this->assertSame('saved-client-secret', new TenantSecretStore()->get('tenant-a', 'secret-audit', 'oauth_client_secret'));
    }

    #[DataProvider('literalSecrets')]
    public function test_local_secrets_survive_dotenv_reload_and_re_registration(string $literal): void
    {
        $this->tenant('local');
        $this->register(['api_key' => $literal, 'oauth_password' => $literal]);
        foreach ([1, 2] as $attempt) {
            $values = Dotenv::parse(File::get(base_path('.env')));
            $this->assertSame($literal, $values['SECRET_AUDIT_API_KEY'], "attempt {$attempt}");
            $this->assertSame($literal, $values['SECRET_AUDIT_OAUTH_PASSWORD']);
            Dotenv::createMutable($this->directory)->load();
            ApiAdminServiceProvider::rebindService('secret-audit');
            $this->assertSame($literal, ApiAdminServiceProvider::resolveService('secret-audit')->apiKey);
            $this->register();
        }
    }

    #[DataProvider('legacyEnvEntries')]
    public function test_re_registration_replaces_legacy_quoted_env_values_and_respects_empty_values(string $entry): void
    {
        $this->tenant('local');
        $this->register(['api_key' => 'baked-fallback']);
        File::put(base_path('.env'), "UNRELATED=kept\nSECRET_AUDIT_API_KEY={$entry}\nAFTER=kept\n");
        $this->register(['api_key' => 'replacement']);
        $values = Dotenv::parse(File::get(base_path('.env')));
        $this->assertSame('replacement', $values['SECRET_AUDIT_API_KEY']);
        $this->assertSame('kept', $values['UNRELATED']);
        $this->assertSame('kept', $values['AFTER']);

        File::put(base_path('.env'), "SECRET_AUDIT_API_KEY=\"\"\n");
        $this->register();
        $this->assertSame('', ApiAdminServiceProvider::resolveService('secret-audit')->apiKey);
    }

    /** @return array<string, array{string}> */
    public static function registrationModes(): array
    {
        return ['local' => ['local'], 'hosted' => ['hosted']];
    }

    /** @return array<string, array{string}> */
    public static function literalSecrets(): array
    {
        return [
            'variable syntax' => ['token${AUDIT_EXPANSION}'],
            'dollars' => ['dollar$and$$'],
            'quotes and slashes' => ['a"b\'c\\tail\\n'],
            'whitespace and controls' => ["  first\r\nsecond\t\v\f  "],
        ];
    }

    /** @return array<string, array{string}> */
    public static function legacyEnvEntries(): array
    {
        return [
            'double quoted' => ["\"old\nmultiline\" # legacy"],
            'single quoted' => ["'old value' # legacy"],
        ];
    }

    public function test_legacy_static_oauth_secret_is_preserved_without_reviving_explicitly_cleared_values(): void
    {
        $this->tenant('local');
        File::put(config_path('api-admin.php'), '<?php return '.var_export([
            'services' => ['secret-audit' => ['oauth_client_secret' => 'legacy-secret']],
        ], true).';');
        File::put($this->directory.'/services.php', '<?php return ["services" => ["unrelated" => []]];');
        $this->register([
            'auth_type' => 'oauth2_cc', 'token_url' => 'https://idp.test/token', 'oauth_client_id' => 'client',
        ]);
        $this->assertSame('legacy-secret', ApiAdminServiceProvider::resolveService('secret-audit')->oauthClientSecret);
        $this->register(['oauth_client_secret' => '']);
        $this->register();
        $this->assertSame('', ApiAdminServiceProvider::resolveService('secret-audit')->oauthClientSecret);
    }

    public function test_pagination_mapping_survives_generation_and_service_resolution(): void
    {
        $this->tenant('local');
        foreach ([
            ['response_format' => 'django'],
            ['response_format' => 'custom', 'response_mapping' => ['per_page_param_name' => 'size', 'zero_based_pages' => true]],
        ] as $params) {
            $this->register($params);
            $generated = include $this->directory.'/Configs/SecretAuditConfig.php';
            $this->assertIsArray($generated);
            $fromFile = $generated['response_mapping'];
            $this->assertIsArray($fromFile);
            $resolved = ApiAdminServiceProvider::resolveService('secret-audit')->responseMapping;
            $this->assertSame($resolved->perPageParamName, $fromFile['per_page_param_name']);
            $this->assertSame($resolved->zeroBasedPages, $fromFile['zero_based_pages']);
            $this->assertSame($resolved->nextPageUrlKey, $fromFile['next_page_url_key']);
            $this->assertSame($resolved->prevPageUrlKey, $fromFile['prev_page_url_key']);
            if ($params['response_format'] === 'django') {
                $this->assertSame('page_size', $resolved->perPageParamName);
                $this->assertSame('next', $resolved->nextPageUrlKey);
                $this->assertSame('previous', $resolved->prevPageUrlKey);
            } else {
                $this->assertSame('size', $resolved->perPageParamName);
                $this->assertTrue($resolved->zeroBasedPages);
            }
        }
    }

    private function tenant(string $mode): void
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
    }

    /** @param array<string, mixed> $overrides */
    private function register(array $overrides = []): void
    {
        $result = new McpServer()->executeTool('register_service', [
            'name' => 'secret-audit', 'base_url' => 'https://api.test', 'auth_type' => 'bearer',
            ...$overrides,
        ]);
        $this->assertTrue($result['success'], json_encode($result, JSON_THROW_ON_ERROR));
    }
}
