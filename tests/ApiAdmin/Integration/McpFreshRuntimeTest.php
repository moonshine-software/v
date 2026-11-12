<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Integration;

use App\ApiAdmin\MCP\Generator\ResourceGenerator;
use App\ApiAdmin\Providers\ApiAdminServiceProvider;
use Illuminate\Support\Facades\File;
use MoonShine\Laravel\Providers\MoonShineServiceProvider;
use MoonShine\MultiPanels\Providers\MultiPanelServiceProvider;
use Orchestra\Testbench\TestCase;
use Symfony\Component\Process\Process;
use Tests\ApiAdmin\Concerns\UsesIsolatedProjectRoot;

final class McpFreshRuntimeTest extends TestCase
{
    use UsesIsolatedProjectRoot;

    protected function getPackageProviders($app): array
    {
        return [MoonShineServiceProvider::class, MultiPanelServiceProvider::class, ApiAdminServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->useIsolatedProjectRoot($app);
        $app['config']->set('api-admin.specs_path', base_path('.ai-factory/specs'));
    }

    protected function tearDown(): void
    {
        $this->removeIsolatedProjectRoot();
        parent::tearDown();
    }

    public function test_stdio_session_uses_new_mutation_body_after_update_resource(): void
    {
        $project = dirname(__DIR__, 3);
        File::copyDirectory($project.'/config', base_path('config'));
        File::ensureDirectoryExists(base_path('bootstrap/cache'));
        File::copy($project.'/tests/Fixtures/mcp-runtime-artisan.php', base_path('artisan'));

        $service = 'runtime'.bin2hex(random_bytes(6));
        File::put(base_path('.env'), strtoupper($service).'_API_URL=https://old.mcp-runtime.test'."\nMOONSHINE_TEST_OS_MARKER=dotenv\n");
        $mapping = [
            'base_url' => 'https://mcp-runtime.test',
            'panel' => $service.'-admin',
            'auth_type' => 'none',
            'enabled' => true,
        ];
        config(["api-admin.services.{$service}" => $mapping]);
        File::ensureDirectoryExists(base_path('storage/api-admin/generated'));
        File::put(base_path('storage/api-admin/generated/services.php'), '<?php return '.var_export(['services' => [$service => $mapping]], true).';');

        $spec = [
            'service' => $service,
            'name' => 'tag',
            'endpoint' => '/tags',
            'panel' => $service.'-admin',
            'fields' => [['column' => 'name', 'type' => 'text']],
            'mutations' => ['create' => ['method' => 'POST', 'path' => '/tags', 'body' => ['for_entity' => 'project']]],
        ];
        (void) (new ResourceGenerator)->generate($spec);

        $frames = [
            ['name' => 'create_resource_item', 'arguments' => ['service' => $service, 'resource' => 'tag', 'payload' => ['name' => 'Before']]],
            ['name' => 'update_resource', 'arguments' => [...$spec, 'mutations' => ['create' => ['method' => 'POST', 'path' => '/tags', 'body' => ['for_entity' => 'project', 'name' => '{name}']]]]],
            ['name' => 'create_resource_item', 'arguments' => ['service' => $service, 'resource' => 'tag', 'payload' => ['name' => 'After']]],
            ['name' => 'register_service', 'arguments' => ['name' => $service, 'base_url' => 'https://fresh.mcp-runtime.test', 'auth_type' => 'none', 'panel' => $service.'-admin']],
            ['name' => 'create_resource_item', 'arguments' => ['service' => $service, 'resource' => 'tag', 'payload' => ['name' => 'Fresh environment']]],
            ['name' => 'create_resource', 'arguments' => [
                'service' => $service, 'name' => 'setting', 'endpoint' => '/settings',
                'fields' => [['column' => 'name', 'type' => 'text'], ['column' => 'value', 'type' => 'text']],
                'response_mapping' => ['primary_key' => 'name', 'pagination_type' => 'none', 'detail_strategy' => 'list'],
            ]],
            ['name' => 'update_resource', 'arguments' => [
                'service' => $service, 'name' => 'setting',
                'fields' => [['column' => 'name', 'type' => 'text'], ['column' => 'value', 'type' => 'text']],
            ]],
            ['name' => 'get_resource', 'arguments' => ['service' => $service, 'name' => 'setting']],
            ['name' => 'fetch_resource_item', 'arguments' => ['service' => $service, 'resource' => 'setting', 'id' => 'site_title']],
        ];
        $input = '';
        foreach ($frames as $index => $params) {
            $input .= json_encode(['jsonrpc' => '2.0', 'id' => $index + 1, 'method' => 'tools/call', 'params' => $params], JSON_THROW_ON_ERROR)."\n";
        }

        $process = new Process([PHP_BINARY, base_path('artisan'), 'api-admin:mcp-server'], base_path(), [
            'MOONSHINE_TEST_PROJECT' => $project,
            'MOONSHINE_TEST_OS_MARKER' => 'from-os',
            'API_ADMIN_SPECS_PATH' => base_path('.ai-factory/specs'),
            'APP_ENV' => 'testing',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'LOG_CHANNEL' => 'single',
        ], $input, 60);
        $process->mustRun();

        $lines = array_values(array_filter(explode("\n", trim($process->getOutput()))));
        $this->assertCount(9, $lines, $process->getErrorOutput());
        $results = [];
        foreach ($lines as $line) {
            $frame = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            $this->assertIsArray($frame);
            $result = data_get($frame, 'result.content.0.text');
            $this->assertIsString($result, $line);
            $decoded = json_decode($result, true, flags: JSON_THROW_ON_ERROR);
            $this->assertIsArray($decoded);
            $this->assertTrue($decoded['success'] ?? false, $result);
            $results[] = $decoded;
        }
        $this->assertNull(data_get($results[0], 'item.name'));
        $this->assertSame('https://old.mcp-runtime.test/tags', data_get($results[0], 'item.origin'));
        $this->assertSame('After', data_get($results[2], 'item.name'));
        $this->assertSame('https://fresh.mcp-runtime.test/tags', data_get($results[4], 'item.origin'));
        $this->assertSame('from-os', data_get($results[0], 'item.process_marker'));
        $this->assertSame('from-os', data_get($results[4], 'item.process_marker'));
        $this->assertSame(['primary_key' => 'name', 'pagination_type' => 'none', 'detail_strategy' => 'list'], data_get($results[7], 'spec.response_mapping'));
        $this->assertSame('Mapping survived', data_get($results[8], 'item.value'));
    }
}
