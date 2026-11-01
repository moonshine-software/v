<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\ApiAdmin\MCP\GenerationTransaction;
use App\ApiAdmin\MCP\McpServer;
use App\ApiAdmin\Providers\ApiAdminServiceProvider;
use Illuminate\Support\Facades\File;
use MoonShine\Contracts\Core\DependencyInjection\ConfiguratorContract;
use MoonShine\Laravel\Providers\MoonShineServiceProvider;
use MoonShine\MultiPanels\Providers\MultiPanelServiceProvider;
use Orchestra\Testbench\TestCase;

final class ConfigureLayoutTest extends TestCase
{
    private string $directory;

    protected function getPackageProviders($app): array
    {
        return [MoonShineServiceProvider::class, MultiPanelServiceProvider::class, ApiAdminServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/moonshine-layout-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->directory.'/config');
        app()->useConfigPath($this->directory.'/config');
        File::put(config_path('api-admin.php'), '<?php return ["services" => []];');
        File::put(config_path('moonshine.php'), '<?php return ["panels" => []];');
        config([
            'api-admin.hosted.mode' => 'local',
            'api-admin.services' => [],
            'api-admin.configs_path' => $this->directory.'/Configs',
            'api-admin.layouts_path' => $this->directory.'/Layouts',
            'api-admin.generated_services_path' => $this->directory.'/services.php',
            'api-admin.generated_panels_path' => $this->directory.'/panels.php',
        ]);
        $this->register('layout-audit', 'layout-panel');
        $this->register('other-audit', 'other-panel');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_configures_current_generated_logos_and_persists_title_without_changing_other_panel_settings(): void
    {
        $panels = require $this->directory.'/panels.php';
        $this->assertIsArray($panels);
        $this->assertIsArray($panels['layout-panel']);
        $panels['layout-panel']['auth'] = ['guard' => 'panel-guard'];
        File::put($this->directory.'/panels.php', '<?php return '.var_export($panels, true).';');
        // Another worker may have changed the panel name since this process booted.
        config(['api-admin.services.layout-audit.panel' => 'stale-panel']);

        $result = new McpServer()->executeTool('configure_layout', [
            'service' => 'layout-audit', 'logo' => '/large.svg', 'logo_small' => '/small.svg', 'title' => 'Updated title',
        ]);

        $this->assertTrue($result['success'], json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertSame(['logo', 'logo_small', 'title'], $result['changes']);
        $this->assertStringContainsString("return \$small ? '/small.svg' : '/large.svg';", File::get($this->layoutPath()));
        $updated = require $this->directory.'/panels.php';
        $this->assertIsArray($updated);
        $this->assertIsArray($updated['layout-panel']);
        $this->assertSame('Updated title', $updated['layout-panel']['title']);
        unset($panels['layout-panel']['title'], $updated['layout-panel']['title']);
        $this->assertSame($panels, $updated);
        $this->assertSame('Updated title', config('moonshine.panels.layout-panel.title'));
    }

    public function test_repeated_literal_logo_updates_preserve_the_omitted_logo_and_title(): void
    {
        $server = new McpServer;
        $literal = "x'.phpinfo().'{{ logo }}\\tail";
        $this->assertTrue($server->executeTool('configure_layout', [
            'service' => 'layout-audit', 'logo' => '/large.svg', 'logo_small' => $literal, 'title' => $literal,
        ])['success']);

        $this->assertTrue($server->executeTool('configure_layout', [
            'service' => 'layout-audit', 'logo' => '/replacement.svg',
        ])['success']);

        $content = File::get($this->layoutPath());
        $this->assertStringContainsString('return $small ? '.var_export($literal, true)." : '/replacement.svg';", $content);
        foreach (token_get_all($content, TOKEN_PARSE) as $token) {
            if (is_array($token) && $token[0] === T_STRING) {
                $this->assertNotSame('phpinfo', $token[1]);
            }
        }
        $panels = require $this->directory.'/panels.php';
        $this->assertIsArray($panels);
        $this->assertIsArray($panels['layout-panel']);
        $this->assertSame($literal, $panels['layout-panel']['title']);
    }

    public function test_unsupported_layout_does_not_report_success_or_change_files(): void
    {
        $layout = '<?php class CustomLayout { protected function getLogo(bool $small = false): string { return getenv("CUSTOM_LOGO"); } }';
        File::put($this->layoutPath(), $layout);
        $panels = File::get($this->directory.'/panels.php');

        $result = new McpServer()->executeTool('configure_layout', [
            'service' => 'layout-audit', 'logo' => '/new.svg', 'title' => 'Must not persist',
        ]);

        $this->assertFalse($result['success']);
        $this->assertIsString($result['error']);
        $this->assertStringContainsString('Unsupported layout logo method', $result['error']);
        $this->assertSame($layout, File::get($this->layoutPath()));
        $this->assertSame($panels, File::get($this->directory.'/panels.php'));
    }

    public function test_outer_failure_restores_logos_title_and_runtime_configuration(): void
    {
        $layout = File::get($this->layoutPath());
        $panels = File::get($this->directory.'/panels.php');
        $configuration = config('moonshine.panels');
        $configurator = moonshineConfig();
        $configurator->set('panels', $configuration);
        app()->instance(ConfiguratorContract::class, $configurator);

        $result = GenerationTransaction::run(function (): array {
            $updated = new McpServer()->executeTool('configure_layout', [
                'service' => 'layout-audit', 'logo' => '/rolled-back.svg', 'title' => 'Rolled back',
            ]);
            $this->assertTrue($updated['success']);

            return ['success' => false];
        });

        $this->assertFalse($result['success']);
        $this->assertSame($layout, File::get($this->layoutPath()));
        $this->assertSame($panels, File::get($this->directory.'/panels.php'));
        $this->assertSame($configuration, config('moonshine.panels'));
        $this->assertSame($configuration, moonshineConfig()->get('panels'));
    }

    private function register(string $service, string $panel): void
    {
        $result = new McpServer()->executeTool('register_service', [
            'name' => $service, 'panel' => $panel, 'base_url' => 'https://example.test', 'auth_type' => 'none',
        ]);
        $this->assertTrue($result['success'], json_encode($result, JSON_THROW_ON_ERROR));
    }

    private function layoutPath(): string
    {
        return $this->directory.'/Layouts/LayoutAuditLayout.php';
    }
}
