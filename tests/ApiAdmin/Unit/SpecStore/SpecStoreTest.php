<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Unit\SpecStore;

use App\ApiAdmin\MCP\SpecStore\SpecStore;
use App\ApiAdmin\Providers\ApiAdminServiceProvider;
use App\ApiAdmin\Tenancy\TenantContext;
use App\ApiAdmin\Tenancy\TenantResolver;
use Illuminate\Support\Facades\File;
use MoonShine\Laravel\Providers\MoonShineServiceProvider;
use MoonShine\MultiPanels\Providers\MultiPanelServiceProvider;
use Orchestra\Testbench\TestCase;

final class SpecStoreTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            MoonShineServiceProvider::class,
            MultiPanelServiceProvider::class,
            ApiAdminServiceProvider::class,
        ];
    }

    protected function tearDown(): void
    {
        $dir = base_path('.ai-factory/specs/spec_store_test');
        if (File::isDirectory($dir)) {
            File::deleteDirectory($dir);
        }
        $tenantDir = base_path('.ai-factory/specs/Tenants/Acme');
        if (File::isDirectory($tenantDir)) {
            File::deleteDirectory($tenantDir);
        }

        parent::tearDown();
    }

    public function test_path_resolves_under_specs_root_with_sanitised_segments(): void
    {
        $path = SpecStore::path('blog', 'post');

        $this->assertStringEndsWith('.ai-factory/specs/blog/post.json', $path);
    }

    public function test_path_replaces_unsafe_characters_in_segments(): void
    {
        $path = SpecStore::path('my service!', 'po..st');

        $this->assertStringEndsWith('/my_service_/po__st.json', $path);
    }

    public function test_hosted_mode_scopes_specs_by_current_tenant(): void
    {
        config(['api-admin.hosted.mode' => 'hosted']);
        app()->instance(TenantResolver::class, new readonly class implements TenantResolver
        {
            public function resolve(): TenantContext
            {
                return new TenantContext('tenant-a', 'acme', 'Acme', 'hosted', false);
            }
        });

        $path = SpecStore::path('blog', 'post');

        $this->assertStringEndsWith('.ai-factory/specs/Tenants/Acme/blog/post.json', $path);
    }

    public function test_exists_reflects_filesystem_state(): void
    {
        $this->assertFalse(SpecStore::exists('spec_store_test', 'absent'));

        SpecStore::write('spec_store_test', 'present', ['endpoint' => '/x']);

        $this->assertTrue(SpecStore::exists('spec_store_test', 'present'));
    }

    public function test_write_strips_identity_keys_and_round_trips(): void
    {
        $params = [
            'service' => 'spec_store_test',
            'name' => 'post',
            'endpoint' => '/posts',
            'fields' => [
                ['column' => 'id', 'type' => 'id'],
                ['column' => 'title', 'type' => 'text'],
            ],
        ];

        $path = SpecStore::write('spec_store_test', 'post', $params);

        $this->assertFileExists($path);

        $decoded = SpecStore::read('spec_store_test', 'post');

        $this->assertIsArray($decoded);
        $this->assertArrayNotHasKey('service', $decoded);
        $this->assertArrayNotHasKey('name', $decoded);
        $this->assertSame('/posts', $decoded['endpoint']);
        /** @var array<int, array<string, mixed>> $fields */
        $fields = $decoded['fields'];
        $this->assertCount(2, $fields);
        $this->assertSame('title', $fields[1]['column']);
    }

    public function test_write_uses_pretty_print_with_unicode_and_unescaped_slashes(): void
    {
        SpecStore::write('spec_store_test', 'i18n', [
            'title' => 'Café menu',
            'endpoint' => '/api/posts',
        ]);

        $raw = File::get(SpecStore::path('spec_store_test', 'i18n'));

        $this->assertStringContainsString('Café menu', $raw);
        $this->assertStringNotContainsString('\\u00e9', $raw);
        $this->assertStringContainsString('/api/posts', $raw);
        $this->assertStringNotContainsString('\\/', $raw);
        $this->assertStringContainsString("\n    \"", $raw, 'pretty print should produce indented lines');
    }

    public function test_read_returns_null_when_sidecar_missing(): void
    {
        $this->assertNull(SpecStore::read('spec_store_test', 'never_written'));
    }

    public function test_read_returns_null_for_corrupt_json(): void
    {
        $path = SpecStore::path('spec_store_test', 'corrupt');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, '{not valid json');

        $this->assertNull(SpecStore::read('spec_store_test', 'corrupt'));
    }

    public function test_field_by_column_returns_matching_entry(): void
    {
        $spec = [
            'fields' => [
                ['column' => 'id', 'type' => 'id'],
                ['column' => 'title', 'type' => 'text', 'methods' => ['sortable']],
            ],
        ];

        $found = SpecStore::fieldByColumn($spec, 'title');
        $this->assertIsArray($found);
        $this->assertSame('text', $found['type'] ?? null);
        $this->assertNull(SpecStore::fieldByColumn($spec, 'missing'));
    }

    public function test_field_index_by_column_returns_zero_based_position(): void
    {
        $spec = [
            'fields' => [
                ['column' => 'id'],
                ['column' => 'title'],
                ['column' => 'body'],
            ],
        ];

        $this->assertSame(0, SpecStore::fieldIndexByColumn($spec, 'id'));
        $this->assertSame(2, SpecStore::fieldIndexByColumn($spec, 'body'));
        $this->assertNull(SpecStore::fieldIndexByColumn($spec, 'missing'));
    }

    public function test_field_helpers_handle_specs_with_no_fields_array(): void
    {
        $spec = ['endpoint' => '/x'];

        $this->assertNull(SpecStore::fieldByColumn($spec, 'title'));
        $this->assertNull(SpecStore::fieldIndexByColumn($spec, 'title'));
    }
}
