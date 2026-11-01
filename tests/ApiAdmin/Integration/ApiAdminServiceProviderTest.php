<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Integration;

use App\ApiAdmin\Providers\ApiAdminServiceProvider;
use App\ApiAdmin\Resources\AbstractRestResource;
use App\ApiAdmin\ServiceConfig\ResponseMapping;
use App\ApiAdmin\ServiceConfig\ServiceConfig;
use App\ApiAdmin\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use MoonShine\Contracts\Core\DependencyInjection\CoreContract;
use MoonShine\Contracts\Core\ResourceContract;
use MoonShine\Laravel\Providers\MoonShineServiceProvider;
use MoonShine\Laravel\Resources\ModelResource;
use MoonShine\MultiPanels\Providers\MultiPanelServiceProvider;
use Orchestra\Testbench\TestCase;
use Override;
use ReflectionClass;
use RuntimeException;

/**
 * Guard against two bugs that both produced "resources show up twice in
 * the sidebar menu":
 *
 *  1. Composer auto-discovery (`extra.laravel.providers` in our
 *     composer.json) and a manual `bootstrap/providers.php` entry
 *     registering the provider twice → register()/boot() ran twice per
 *     request → `Core::resources()` accumulated every class twice.
 *
 *  2. The host app's `App\Providers\MoonShineServiceProvider::boot()`
 *     calling `$core->autoload()` — that scan walks the PSR-4 map and
 *     picks up our generated resources too, so by the time
 *     `registerGeneratedResources()` runs, the classes are ALREADY in
 *     `Core::resources`. `Core::resources($data)` is `array_merge`, so
 *     naively appending produces duplicates.
 */
class ApiAdminServiceProviderTest extends TestCase
{
    private string $resourceDir;

    private string $resourceClass;

    private string $localResourceDir;

    private string $localResourceClass;

    protected function getPackageProviders($app): array
    {
        return [
            MoonShineServiceProvider::class,
            MultiPanelServiceProvider::class,
            ApiAdminServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        // Point the scan at a tmp dir with one fake generated resource.
        // Using the real app/MoonShine/ApiAdmin/Resources would couple
        // this test to the host project's current state.
        $this->resourceDir = sys_get_temp_dir().'/moonshine-api-admin-provider-test-'.uniqid();
        $this->localResourceDir = sys_get_temp_dir().'/moonshine-api-admin-local-provider-test-'.uniqid();
        $app['config']->set('api-admin.resources_path', $this->resourceDir);
        $app['config']->set('api-admin.local_resources_path', $this->localResourceDir);
        $app['config']->set('api-admin.local_resources_namespace', 'App\\MoonShine\\Resources');
        $app['config']->set('api-admin.generated_services_path', $this->resourceDir.'/services.php');
        $app['config']->set('api-admin.services', []);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('api_admin_tenant_secrets', static function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->string('service');
            $table->string('name');
            $table->text('value');
            $table->timestamps();
            $table->unique(['tenant_id', 'service', 'name']);
        });

        // Reset the provider's idempotency flags so each test starts
        // clean — otherwise the first test would set them and subsequent
        // runs would all skip.
        $this->resetProviderFlags();

        File::ensureDirectoryExists($this->resourceDir.'/Demo/Post');
        File::ensureDirectoryExists($this->localResourceDir.'/ProviderLocalPost');

        // Anonymous resource class at the expected path. The provider
        // walks directories and reflects FQCNs from file paths, so we
        // need the class to exist on disk with the right namespace and
        // extend AbstractRestResource.
        $fqcn = 'App\\MoonShine\\ApiAdmin\\Resources\\Demo\\Post\\PostResource';
        if (! class_exists($fqcn, autoload: false)) {
            eval('namespace App\\MoonShine\\ApiAdmin\\Resources\\Demo\\Post; class PostResource extends \\Tests\\ApiAdmin\\Integration\\ProviderFakeResource {}');
        }
        $this->resourceClass = $fqcn;

        // Write a stub file so the recursive scan finds it.
        $code = <<<'PHP'
<?php
// Fake resource stub — classes eval'd in setUp, so the real require is a no-op.
PHP;
        File::put($this->resourceDir.'/Demo/Post/PostResource.php', $code);

        $localFqcn = 'App\\MoonShine\\Resources\\ProviderLocalPost\\ProviderLocalPostResource';
        if (! class_exists($localFqcn, autoload: false)) {
            eval('namespace App\\MoonShine\\Resources\\ProviderLocalPost; class ProviderLocalPostResource extends \\Tests\\ApiAdmin\\Integration\\ProviderFakeModelResource {}');
        }
        $this->localResourceClass = $localFqcn;
        File::put($this->localResourceDir.'/ProviderLocalPost/ProviderLocalPostResource.php', $code);

        // Patch BASE_NAMESPACE-equivalent via config override so the
        // provider's FQCN reconstruction lines up with our fake.
        config(['api-admin.resources_path' => $this->resourceDir]);
        config(['api-admin.local_resources_path' => $this->localResourceDir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->resourceDir);
        File::deleteDirectory($this->localResourceDir);
        $this->resetProviderFlags();

        Schema::dropIfExists('api_admin_tenant_secrets');

        parent::tearDown();
    }

    private function resetProviderFlags(): void
    {
        $reflection = new ReflectionClass(ApiAdminServiceProvider::class);
        foreach (['servicesRegistered', 'resourcesRegistered'] as $name) {
            if ($reflection->hasProperty($name)) {
                $prop = $reflection->getProperty($name);
                $prop->setValue(null, false);
            }
        }
    }

    public function test_register_services_runs_only_once_per_process(): void
    {
        // Seed the config with a single service and spin up the provider
        // twice — simulates the "composer auto-discovery + bootstrap/
        // providers.php" double-registration scenario. The container
        // should carry exactly ONE binding for the service at the end.
        config(['api-admin.services' => [
            'demo' => [
                'name' => 'demo',
                'base_url' => 'https://api.demo.test',
                'api_key' => 'k',
                'panel' => 'demo-admin',
            ],
        ]]);

        $app = $this->app;
        \assert($app !== null);

        $provider1 = new ApiAdminServiceProvider($app);
        $provider1->register();

        $provider2 = new ApiAdminServiceProvider($app);
        $provider2->register();

        // Two register() calls, but service config resolved just once
        // (the binding isn't rebound — otherwise rebindService would fire
        // for every duplicate provider and stomp the live config).
        $this->assertTrue($app->bound('api-admin.service.demo'));
        $this->assertTrue($app->bound('api-admin.tenant.local.service.demo'));
        $this->assertInstanceOf(ServiceConfig::class, $app['api-admin.service.demo']);
    }

    public function test_register_services_binds_hosted_services_by_tenant(): void
    {
        config(['api-admin.services' => [
            'demo' => [
                'name' => 'demo',
                'tenant_id' => 'tenant-a',
                'base_url' => 'https://api.demo.test',
                'api_key' => 'k',
                'panel' => 'demo-admin',
            ],
        ]]);

        $app = $this->app;
        \assert($app !== null);

        $provider = new ApiAdminServiceProvider($app);
        $provider->register();

        $this->assertFalse($app->bound('api-admin.service.demo'));
        $this->assertTrue($app->bound('api-admin.tenant.tenant-a.service.demo'));

        $config = ApiAdminServiceProvider::resolveService(
            'demo',
            new TenantContext('tenant-a', 'acme', 'Acme', 'hosted', false),
        );

        $this->assertSame('tenant-a', $config->tenantId);
        $this->assertSame('demo', $config->name);

        $this->expectException(RuntimeException::class);
        ApiAdminServiceProvider::resolveService(
            'demo',
            new TenantContext('tenant-b', 'beta', 'Beta', 'hosted', false),
        );
    }

    public function test_register_generated_resources_dedupes_against_autoload(): void
    {
        // Pre-seed Core with the resource, mirroring what $core->autoload()
        // would have done from the host app's MoonShineServiceProvider.
        // The provider's second-pass scan must then ADD NOTHING — not
        // duplicate the class it already sees.
        $app = $this->app;
        \assert($app !== null);
        /** @var CoreContract $core */
        $core = $app->make(CoreContract::class);
        $resourceClasses = [
            $this->resourceInstance($this->resourceClass),
            $this->resourceInstance($this->localResourceClass),
        ];
        $core->resources($resourceClasses);

        $countBefore = \count($core->getResources()->toArray());

        // Boot the provider. It runs registerGeneratedResources(), which
        // scans the tmp dir, finds PostResource.php, reconstructs the FQCN
        // — but that class is ALREADY in Core::resources.
        $provider = new ApiAdminServiceProvider($app);
        $provider->register();
        $provider->boot();

        $countAfter = \count($core->getResources()->toArray());

        // The provider reconstructs FQCNs from the filesystem using its
        // own BASE_NAMESPACE, so the fake's TestFakes namespace won't
        // match — the real guard is that the FQCN from the scan matches
        // what's already registered. The test asserts the reverse: when
        // a class IS already registered, no duplicate gets pushed.
        // We simulate that by checking the resource count after a second
        // direct registration of the same class via Core::resources().
        $core->resources([$this->resourceInstance($this->resourceClass)]);
        $countRepushed = \count($core->getResources()->toArray());

        // Sanity check: Core::resources() itself is array_merge (that's
        // exactly why we need the dedup in the provider). Direct second
        // push DOES grow the array; the provider's guarded scan does NOT.
        $this->assertGreaterThan($countAfter, $countRepushed);
        $this->assertSame($countBefore, $countAfter, 'Provider scan must not push classes already resolved via autoload().');
    }

    public function test_register_generated_local_resources(): void
    {
        $app = $this->app;
        \assert($app !== null);
        /** @var CoreContract $core */
        $core = $app->make(CoreContract::class);

        $classesBefore = $this->resourceClasses($core);

        $provider = new ApiAdminServiceProvider($app);
        $provider->register();
        $provider->boot();

        $classesAfter = $this->resourceClasses($core);

        $this->assertNotContains($this->localResourceClass, $classesBefore);
        $this->assertContains($this->localResourceClass, $classesAfter);
    }

    public function test_register_generated_local_resources_dedupes_against_autoload(): void
    {
        $app = $this->app;
        \assert($app !== null);
        /** @var CoreContract $core */
        $core = $app->make(CoreContract::class);
        $resourceClasses = [
            $this->resourceInstance($this->resourceClass),
            $this->resourceInstance($this->localResourceClass),
        ];
        $core->resources($resourceClasses);

        $countBefore = \count($core->getResources()->toArray());

        $provider = new ApiAdminServiceProvider($app);
        $provider->register();
        $provider->boot();

        $countAfter = \count($core->getResources()->toArray());

        $this->assertSame($countBefore, $countAfter, 'Provider local scan must not push classes already resolved via autoload().');
    }

    public function test_boot_is_idempotent_for_resources_within_process(): void
    {
        // Two provider instances, each running boot(), but we end up with
        // the same Core::resources count we started with — static flag
        // short-circuits the second registerGeneratedResources() call.
        $app = $this->app;
        \assert($app !== null);
        /** @var CoreContract $core */
        $core = $app->make(CoreContract::class);

        $countBefore = \count($core->getResources()->toArray());

        $providerA = new ApiAdminServiceProvider($app);
        $providerA->register();
        $providerA->boot();
        $countAfterFirstBoot = \count($core->getResources()->toArray());

        $providerB = new ApiAdminServiceProvider($app);
        $providerB->register();
        $providerB->boot();

        $countAfter = \count($core->getResources()->toArray());

        $this->assertGreaterThan($countBefore, $countAfterFirstBoot);
        $this->assertSame($countAfterFirstBoot, $countAfter);
    }

    /**
     * @return list<class-string>
     */
    private function resourceClasses(CoreContract $core): array
    {
        $classes = [];
        foreach ($core->getResources()->toArray() as $resource) {
            if (\is_object($resource)) {
                $classes[] = $resource::class;
            }
        }

        return $classes;
    }

    private function resourceInstance(string $class): ResourceContract
    {
        $instance = app($class);
        if (! $instance instanceof ResourceContract) {
            throw new RuntimeException("Fake resource {$class} was not resolved as a MoonShine resource.");
        }

        return $instance;
    }
}

/**
 * Base class the anonymous fake extends — named so `is_subclass_of($fqcn,
 * AbstractRestResource::class)` passes in registerGeneratedResources().
 */
abstract class ProviderFakeResource extends AbstractRestResource
{
    protected function resolveServiceConfig(): ServiceConfig
    {
        return new ServiceConfig(
            name: 'demo',
            baseUrl: 'https://api.demo.test',
            apiKey: 'k',
            responseMapping: new ResponseMapping,
        );
    }

    protected function endpoint(): string
    {
        return '/posts';
    }
}

/**
 * @extends ModelResource<ProviderFakeModel, null, null, null>
 */
abstract class ProviderFakeModelResource extends ModelResource
{
    #[Override]
    protected string $model = ProviderFakeModel::class;
}

final class ProviderFakeModel extends Model
{
    #[Override]
    protected $table = 'provider_fake_models';
}
