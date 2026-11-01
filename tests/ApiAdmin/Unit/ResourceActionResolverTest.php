<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Unit;

use App\ApiAdmin\MCP\Generator\ResourceGenerator;
use App\ApiAdmin\MCP\ResourceActionResolver;
use App\ApiAdmin\Providers\ApiAdminServiceProvider;
use Illuminate\Support\Facades\File;
use MoonShine\Laravel\Providers\MoonShineServiceProvider;
use MoonShine\MultiPanels\Providers\MultiPanelServiceProvider;
use Orchestra\Testbench\TestCase;

final class ResourceActionResolverTest extends TestCase
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
        $dir = base_path('.ai-factory/specs/resolver_test');
        if (File::isDirectory($dir)) {
            File::deleteDirectory($dir);
        }

        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $spec
     */
    private function writeSidecar(string $resource, array $spec): void
    {
        $path = ResourceGenerator::specSidecarPath('resolver_test', $resource);
        File::ensureDirectoryExists(dirname($path));
        $encoded = json_encode($spec);
        File::put($path, $encoded === false ? '{}' : $encoded);
    }

    public function test_sidecar_with_explicit_actions_is_respected(): void
    {
        $this->writeSidecar('readonly', ['actions' => ['view']]);

        $this->assertSame(['view'], ResourceActionResolver::allowed('resolver_test', 'readonly'));
    }

    public function test_sidecar_with_absent_actions_key_returns_defaults(): void
    {
        // No `actions` key at all → use full default set. This is the
        // historical shape — resources the agent never constrained on
        // creation still work on the MCP CUD surface.
        $this->writeSidecar('default_actions', ['endpoint' => '/foo']);

        $this->assertSame(
            ['view', 'create', 'update', 'delete'],
            ResourceActionResolver::allowed('resolver_test', 'default_actions'),
        );
    }

    public function test_missing_sidecar_and_missing_class_defaults_to_all(): void
    {
        $this->assertSame(
            ['view', 'create', 'update', 'delete'],
            ResourceActionResolver::allowed('resolver_test', 'nonexistent'),
        );
    }

    public function test_actions_are_normalized_and_deduplicated(): void
    {
        $this->writeSidecar('dup', [
            'actions' => ['view', 'VIEW', 'read', 'edit', 'update', 'remove', 'xxx'],
        ]);

        $result = ResourceActionResolver::allowed('resolver_test', 'dup');

        // `view`/`VIEW`/`read` collapse to view; edit+update to update;
        // remove to delete; `xxx` is dropped entirely.
        $this->assertSame(['view', 'update', 'delete'], $result);
    }

    public function test_empty_actions_array_means_nothing_allowed(): void
    {
        // Explicit `actions: []` is "lock down" — the agent shouldn't be
        // able to invoke CUD on a resource whose spec says no actions.
        $this->writeSidecar('locked', ['actions' => []]);

        $this->assertSame([], ResourceActionResolver::allowed('resolver_test', 'locked'));
    }

    public function test_malformed_sidecar_json_falls_back_to_defaults(): void
    {
        $path = ResourceGenerator::specSidecarPath('resolver_test', 'corrupt');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, '{not json');

        $this->assertSame(
            ['view', 'create', 'update', 'delete'],
            ResourceActionResolver::allowed('resolver_test', 'corrupt'),
        );
    }
}
