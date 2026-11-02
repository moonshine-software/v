<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\ApiAdmin\MCP\GenerationTransaction;
use App\ApiAdmin\MCP\McpServer;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class LocalResourceRemovalTest extends TestCase
{
    private string $directory;

    private string $service;

    private string $otherService;

    private string $panel;

    private string $otherPanel;

    protected function setUp(): void
    {
        parent::setUp();

        $suffix = bin2hex(random_bytes(6));
        $this->directory = sys_get_temp_dir().'/moonshine-local-removal-'.$suffix;
        $this->service = 'local-'.$suffix;
        $this->otherService = 'other-'.$suffix;
        $this->panel = $this->service.'-admin';
        $this->otherPanel = $this->otherService.'-admin';
        File::ensureDirectoryExists($this->directory.'/config');
        app()->useConfigPath($this->directory.'/config');
        File::put(config_path('api-admin.php'), '<?php return ["services" => []];');
        File::put(config_path('moonshine.php'), '<?php return ["panels" => []];');
        config([
            'api-admin.hosted.mode' => 'local',
            'api-admin.services' => [],
            'api-admin.resources_path' => $this->directory.'/RestResources',
            'api-admin.local_resources_path' => $this->directory.'/LocalResources',
            'api-admin.configs_path' => $this->directory.'/Configs',
            'api-admin.layouts_path' => $this->directory.'/Layouts',
            'api-admin.generated_services_path' => $this->directory.'/services.php',
            'api-admin.generated_panels_path' => $this->directory.'/panels.php',
            'moonshine.panels' => [],
        ]);
        $this->initialize($this->service, $this->panel);
        $this->initialize($this->otherService, $this->otherPanel);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_removes_one_local_resource_and_pages_without_touching_siblings_or_other_panels(): void
    {
        $target = $this->resource('Product', $this->panel);
        $sibling = $this->resource('Category', $this->panel);
        $foreign = $this->resource('Order', $this->otherPanel);

        $result = new McpServer()->executeTool('remove_resource', [
            'service' => $this->service, 'name' => 'products', 'confirm' => true,
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame([$target], $result['removed_paths']);
        $this->assertDirectoryDoesNotExist($target);
        $this->assertFileExists($sibling.'/Pages/CategoryIndexPage.php');
        $this->assertFileExists($foreign.'/OrderResource.php');
        $this->assertIsArray(config('api-admin.services.'.$this->service));
    }

    public function test_foreign_and_unassigned_local_resources_cannot_be_removed_through_another_service(): void
    {
        $foreign = $this->resource('Order', $this->otherPanel);
        $unassigned = $this->resource('Unassigned', null);

        foreach (['order', 'unassigned'] as $name) {
            $result = new McpServer()->executeTool('remove_resource', [
                'service' => $this->service, 'name' => $name, 'confirm' => true,
            ]);
            $this->assertTrue($result['success']);
            $this->assertSame([], $result['removed_paths']);
        }

        $this->assertFileExists($foreign.'/OrderResource.php');
        $this->assertFileExists($unassigned.'/UnassignedResource.php');
    }

    public function test_removing_local_service_deletes_only_its_resources_and_registration(): void
    {
        $first = $this->resource('Product', $this->panel);
        $second = $this->resource('Category', $this->panel);
        $foreign = $this->resource('Order', $this->otherPanel);
        $unassigned = $this->resource('Unassigned', null);
        $nativeUser = $this->resource('MoonShineUser', $this->panel);
        $nativeRole = $this->resource('MoonShineUserRole', $this->panel);

        $result = new McpServer()->executeTool('remove_service', ['name' => $this->service, 'confirm' => true]);

        $this->assertTrue($result['success']);
        $this->assertEqualsCanonicalizing([$first, $second], $result['removed_paths']);
        $this->assertDirectoryDoesNotExist($first);
        $this->assertDirectoryDoesNotExist($second);
        foreach ([$foreign, $unassigned, $nativeUser, $nativeRole] as $path) {
            $this->assertDirectoryExists($path);
        }
        $services = require $this->directory.'/services.php';
        $this->assertIsArray($services);
        $this->assertIsArray($services['services']);
        $this->assertArrayNotHasKey($this->service, $services['services']);
        $this->assertArrayHasKey($this->otherService, $services['services']);
        $panels = require $this->directory.'/panels.php';
        $this->assertIsArray($panels);
        $this->assertArrayNotHasKey($this->panel, $panels);
        $this->assertArrayHasKey($this->otherPanel, $panels);
    }

    public function test_service_without_a_panel_cannot_claim_local_resources(): void
    {
        $owned = $this->resource('Product', $this->panel);
        $unassigned = $this->resource('Unassigned', null);
        config(['api-admin.services.'.$this->service.'.panel' => null]);

        $result = new McpServer()->executeTool('remove_service', ['name' => $this->service, 'confirm' => true]);

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['removed_paths']);
        $this->assertDirectoryExists($owned);
        $this->assertDirectoryExists($unassigned);
    }

    #[DataProvider('reservedResourceNames')]
    public function test_platform_resources_cannot_be_removed_even_if_their_panel_matches(string $directory, string $name): void
    {
        $resource = $this->resource($directory, $this->panel);
        $before = File::get($resource.'/'.$directory.'Resource.php');

        $result = new McpServer()->executeTool('remove_resource', [
            'service' => $this->service, 'name' => $name, 'confirm' => true,
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['removed_paths']);
        $this->assertSame($before, File::get($resource.'/'.$directory.'Resource.php'));
    }

    /** @return list<array{string, string}> */
    public static function reservedResourceNames(): array
    {
        return [
            ['MoonShineUser', 'moonshine_user'],
            ['MoonShineUserRole', 'moonshine_user_role'],
            ['Moonshineuser', 'moonshineuser'],
        ];
    }

    #[DataProvider('reservedServiceNames')]
    public function test_system_hub_layout_cannot_be_removed_with_or_without_legacy_registration(string $name, bool $registered): void
    {
        File::ensureDirectoryExists($this->directory.'/Layouts');
        $path = $this->directory.'/Layouts/AdminHubLayout.php';
        File::put($path, '<?php class AdminHubLayout {}');
        if ($registered) {
            config(['api-admin.services.'.$name => ['kind' => 'rest', 'panel' => 'legacy-panel']]);
        }

        $result = new McpServer()->executeTool('remove_service', ['name' => $name, 'confirm' => true]);

        $this->assertFalse($result['success']);
        $this->assertSame('<?php class AdminHubLayout {}', File::get($path));
    }

    /** @return list<array{string, bool}> */
    public static function reservedServiceNames(): array
    {
        return [
            ['admin-hub', false],
            ['AdminHub', true],
            ['adminhub', false],
        ];
    }

    public function test_outer_failure_restores_deleted_local_files_and_registration(): void
    {
        $resource = $this->resource('Product', $this->panel);
        $source = File::get($resource.'/ProductResource.php');
        $services = File::get($this->directory.'/services.php');
        $panels = File::get($this->directory.'/panels.php');

        $result = GenerationTransaction::run(function (): array {
            $removed = new McpServer()->executeTool('remove_service', ['name' => $this->service, 'confirm' => true]);
            $this->assertTrue($removed['success']);
            $this->assertDirectoryDoesNotExist($this->directory.'/LocalResources/Product');

            return ['success' => false];
        });

        $this->assertFalse($result['success']);
        $this->assertSame($source, File::get($resource.'/ProductResource.php'));
        $this->assertFileExists($resource.'/Pages/ProductIndexPage.php');
        $this->assertSame($services, File::get($this->directory.'/services.php'));
        $this->assertSame($panels, File::get($this->directory.'/panels.php'));
        $this->assertIsArray(config('api-admin.services.'.$this->service));
    }

    #[DataProvider('sharedPanelOperations')]
    public function test_legacy_shared_panel_rejects_removal_without_changing_resources_or_registrations(string $operation): void
    {
        $resource = $this->resource('Product', $this->panel);
        $source = File::get($resource.'/ProductResource.php');
        $services = require $this->directory.'/services.php';
        $this->assertIsArray($services);
        $this->assertIsArray($services['services']);
        $this->assertIsArray($services['services'][$this->otherService]);
        $services['services'][$this->otherService]['panel'] = $this->panel;
        File::put($this->directory.'/services.php', '<?php return '.var_export($services, true).';');
        config(['api-admin.services.'.$this->otherService.'.panel' => $this->panel]);
        $registration = File::get($this->directory.'/services.php');
        $panels = File::get($this->directory.'/panels.php');
        $configuration = config('api-admin.services');
        $params = $operation === 'remove_service'
            ? ['name' => $this->service, 'confirm' => true]
            : ['service' => $this->service, 'name' => 'product', 'confirm' => true];

        $result = new McpServer()->executeTool($operation, $params);

        $this->assertFalse($result['success']);
        $this->assertIsString($result['error']);
        $this->assertStringContainsString('shared by multiple services', $result['error']);
        $this->assertSame($source, File::get($resource.'/ProductResource.php'));
        $this->assertFileExists($resource.'/Pages/ProductIndexPage.php');
        $this->assertSame($registration, File::get($this->directory.'/services.php'));
        $this->assertSame($panels, File::get($this->directory.'/panels.php'));
        $this->assertSame($configuration, config('api-admin.services'));
    }

    /** @return list<array{string}> */
    public static function sharedPanelOperations(): array
    {
        return [['remove_service'], ['remove_resource']];
    }

    #[DataProvider('platformPanelRemovalOperations')]
    public function test_legacy_service_cannot_remove_the_platform_panel_or_its_resources(string $operation, string $kind): void
    {
        $resource = $this->resource('Product', 'admin');
        $source = File::get($resource.'/ProductResource.php');
        File::ensureDirectoryExists($this->directory.'/Configs');
        File::ensureDirectoryExists($this->directory.'/Layouts');
        $configFile = $this->directory.'/Configs/'.Str::studly($this->service).'Config.php';
        $layoutFile = $this->directory.'/Layouts/'.Str::studly($this->service).'Layout.php';
        File::put($configFile, '<?php // legacy config');
        File::put($layoutFile, '<?php // legacy layout');
        $legacy = ['kind' => $kind, 'panel' => 'admin', 'tenant_id' => 'local'];
        config(['api-admin.services.'.$this->service => $legacy]);
        $services = ['services' => [$this->service => $legacy]];
        File::put($this->directory.'/services.php', '<?php return '.var_export($services, true).';');
        $panels = ['panels' => ['admin' => ['prefix' => 'admin', 'title' => 'MoonShine V']]];
        File::put(config_path('moonshine.php'), "<?php return ['panels' => ['admin' => ['prefix' => 'admin', 'title' => 'MoonShine V']]];");
        config(['moonshine.panels.admin' => $panels['panels']['admin']]);
        $nativeConfig = File::get(config_path('moonshine.php'));
        $registration = File::get($this->directory.'/services.php');
        $generatedPanels = File::get($this->directory.'/panels.php');
        $configuration = config('api-admin.services');
        $params = $operation === 'remove_service'
            ? ['name' => $this->service, 'confirm' => true]
            : ['service' => $this->service, 'name' => 'product', 'confirm' => true];

        $result = new McpServer()->executeTool($operation, $params);

        $this->assertFalse($result['success']);
        $this->assertSame($nativeConfig, File::get(config_path('moonshine.php')));
        $this->assertSame($registration, File::get($this->directory.'/services.php'));
        $this->assertSame($generatedPanels, File::get($this->directory.'/panels.php'));
        $this->assertSame($configuration, config('api-admin.services'));
        $this->assertSame($source, File::get($resource.'/ProductResource.php'));
        $this->assertFileExists($resource.'/Pages/ProductIndexPage.php');
        $this->assertFileExists($configFile);
        $this->assertFileExists($layoutFile);
    }

    /** @return list<array{string, string}> */
    public static function platformPanelRemovalOperations(): array
    {
        return [['remove_service', 'local'], ['remove_resource', 'local'], ['remove_service', 'rest']];
    }

    #[DataProvider('fakePanelDeclarations')]
    public function test_comments_and_string_values_do_not_claim_local_resource_ownership(string $style, string $operation): void
    {
        $resource = $this->resource('Product', null);
        $declaration = 'protected string $panel = '.var_export($this->panel, true).';';
        $member = $style === 'comment'
            ? '/* '.$declaration.' */'
            : 'private string $example = '.var_export($declaration, true).';';
        $source = '<?php class ProductResource { '.$member.' }';
        File::put($resource.'/ProductResource.php', $source);

        $result = $this->executeResourceOperation($operation);

        $this->assertSame($operation !== 'create_model_resource', $result['success']);
        $this->assertSame($source, File::get($resource.'/ProductResource.php'));
        $this->assertFileExists($resource.'/Pages/ProductIndexPage.php');
        if ($operation !== 'create_model_resource') {
            $this->assertSame([], $result['removed_paths']);
        }
    }

    /** @return list<array{string, string}> */
    public static function fakePanelDeclarations(): array
    {
        return [
            ['comment', 'remove_service'], ['comment', 'remove_resource'], ['comment', 'create_model_resource'],
            ['string', 'remove_service'], ['string', 'remove_resource'], ['string', 'create_model_resource'],
        ];
    }

    #[DataProvider('realPanelDeclarations')]
    public function test_real_property_after_a_stale_comment_controls_local_resource_ownership(string $operation, bool $owned): void
    {
        $resource = $this->resource('Product', null);
        $actualPanel = $owned ? $this->panel : $this->otherPanel;
        $stalePanel = $owned ? $this->otherPanel : $this->panel;
        $source = '<?php class ProductResource { /* protected string $panel = '.var_export($stalePanel, true).'; */'
            .' protected /* actual property */ string $panel = '.var_export($actualPanel, true).'; }';
        File::put($resource.'/ProductResource.php', $source);

        $result = $this->executeResourceOperation($operation);

        if ($operation === 'create_model_resource') {
            $this->assertSame($owned, $result['success']);
            if ($owned) {
                $this->assertNotSame($source, File::get($resource.'/ProductResource.php'));
            } else {
                $this->assertSame($source, File::get($resource.'/ProductResource.php'));
            }
        } else {
            $this->assertTrue($result['success']);
            $this->assertSame($owned ? [$resource] : [], $result['removed_paths']);
            if ($owned) {
                $this->assertDirectoryDoesNotExist($resource);
            } else {
                $this->assertSame($source, File::get($resource.'/ProductResource.php'));
            }
        }
    }

    /** @return list<array{string, bool}> */
    public static function realPanelDeclarations(): array
    {
        return [
            ['remove_service', true], ['remove_resource', true], ['create_model_resource', true],
            ['remove_service', false], ['remove_resource', false], ['create_model_resource', false],
        ];
    }

    /** @return array<string, mixed> */
    private function executeResourceOperation(string $operation): array
    {
        $params = match ($operation) {
            'remove_service' => ['name' => $this->service, 'confirm' => true],
            'remove_resource' => ['service' => $this->service, 'name' => 'product', 'confirm' => true],
            default => [
                'service' => $this->service, 'name' => 'product', 'model' => User::class,
                'fields' => [['column' => 'id', 'type' => 'id']],
            ],
        };

        return new McpServer()->executeTool($operation, $params);
    }

    private function initialize(string $service, string $panel): void
    {
        $result = new McpServer()->executeTool('initialize_local_service', ['name' => $service, 'panel' => $panel]);
        $this->assertTrue($result['success'], json_encode($result, JSON_THROW_ON_ERROR));
    }

    private function resource(string $name, ?string $panel): string
    {
        $path = $this->directory.'/LocalResources/'.$name;
        File::ensureDirectoryExists($path.'/Pages');
        $property = $panel === null ? '' : 'protected string $panel = '.var_export($panel, true).';';
        File::put($path.'/'.$name.'Resource.php', '<?php class '.$name.'Resource { '.$property.' }');
        File::put($path.'/Pages/'.$name.'IndexPage.php', '<?php class '.$name.'IndexPage {}');

        return $path;
    }
}
