<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Integration;

use App\ApiAdmin\MCP\ServiceRegistry;
use App\ApiAdmin\Tenancy\TenantContext;
use App\ApiAdmin\Tenancy\TenantResolver;
use Illuminate\Support\Facades\File;
use Orchestra\Testbench\TestCase;
use RuntimeException;

class TenantServiceRegistryTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir().'/moonshine-tenant-registry-test-'.uniqid('', true);

        config([
            'api-admin.resources_path' => $this->tmpRoot.'/Resources',
            'api-admin.configs_path' => $this->tmpRoot.'/Configs',
            'api-admin.layouts_path' => $this->tmpRoot.'/Layouts',
            'api-admin.hosted.mode' => 'hosted',
            'api-admin.hosted.tenant_path_segment' => 'Tenants',
            'api-admin.services' => [
                'blog' => [
                    'name' => 'blog',
                    'tenant_id' => 'tenant-a',
                    'base_url' => 'https://api.blog.test',
                    'panel' => 'blog-admin',
                ],
                'shop' => [
                    'name' => 'shop',
                    'tenant_id' => 'tenant-b',
                    'base_url' => 'https://api.shop.test',
                    'panel' => 'shop-admin',
                ],
            ],
        ]);

        app()->instance(TenantResolver::class, new FixedTenantResolver(
            new TenantContext('tenant-a', 'acme', 'Acme', 'hosted', false),
        ));

        File::ensureDirectoryExists($this->tmpRoot.'/Resources/Tenants/Acme/Blog/Post');
        File::put($this->tmpRoot.'/Resources/Tenants/Acme/Blog/Post/PostResource.php', <<<'PHP'
<?php

final class PostResource extends MultiPanelRestResource
{
    public function endpoint(): string
    {
        return '/posts';
    }
}
PHP);
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->tmpRoot)) {
            File::deleteDirectory($this->tmpRoot);
        }

        parent::tearDown();
    }

    public function test_hosted_mode_lists_only_current_tenant_services(): void
    {
        $services = (new ServiceRegistry)->listServices();

        $this->assertCount(1, $services);
        $this->assertSame('blog', $services[0]['name']);
        $this->assertSame('tenant-a', $services[0]['tenant_id']);
        $this->assertSame(1, $services[0]['resources_count']);
        $this->assertSame($this->tmpRoot.'/Resources/Tenants/Acme/Blog', $services[0]['service_dir']);
        $this->assertSame($this->tmpRoot.'/Configs/Tenants/Acme/BlogConfig.php', $services[0]['config_file']);
        $this->assertSame($this->tmpRoot.'/Layouts/Tenants/Acme/BlogLayout.php', $services[0]['layout_file']);
    }

    public function test_hosted_mode_hides_other_tenant_resources_and_mutations(): void
    {
        $registry = new ServiceRegistry;

        $this->assertSame([], $registry->listResources('shop'));

        $this->expectException(RuntimeException::class);
        $registry->removeResource('shop', 'products');
    }
}

final readonly class FixedTenantResolver implements TenantResolver
{
    public function __construct(
        private ?TenantContext $tenant,
    ) {}

    public function resolve(): ?TenantContext
    {
        return $this->tenant;
    }
}
