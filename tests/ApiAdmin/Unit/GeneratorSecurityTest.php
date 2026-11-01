<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Unit;

use App\ApiAdmin\MCP\Generator\ResourceGenerator;
use App\ApiAdmin\MCP\Tools\ConfigureLayoutTool;
use App\ApiAdmin\MCP\Tools\ListFieldMethodsTool;
use App\ApiAdmin\Tenancy\TenantContext;
use App\ApiAdmin\Tenancy\TenantResolver;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Orchestra\Testbench\TestCase;
use ReflectionMethod;

final class GeneratorSecurityTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/generator-security-'.bin2hex(random_bytes(6));
        config(['api-admin.resources_path' => $this->directory, 'api-admin.specs_path' => $this->directory.'/specs']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_php_payloads_remain_literals_in_all_generated_pages(): void
    {
        $payload = "'.security_probe().'";
        $params = [
            'service' => 'safe', 'name' => 'item', 'panel' => $payload,
            'title' => $payload.'*/', 'endpoint' => '/items/'.$payload,
            'search' => [$payload],
            'fields' => [
                ['column' => $payload, 'label' => $payload, 'type' => 'text', 'methods' => [['name' => 'placeholder', 'args' => [$payload]]]],
                ['column' => 'status_id', 'label' => $payload, 'type' => 'belongs_to', 'endpoint' => '/statuses/'.$payload, 'value_key' => $payload, 'label_key' => $payload],
                ['column' => 'tags', 'type' => 'belongs_to_many', 'attach_endpoint' => '/tags/'.$payload],
                ['column' => 'children', 'type' => 'has_many', 'fields' => [['column' => $payload, 'label' => $payload, 'type' => 'text']]],
                ['column' => 'state', 'type' => 'select', 'options' => [$payload => $payload]],
            ],
            'filters' => [['column' => $payload, 'label' => $payload, 'type' => 'select', 'options' => [$payload => $payload]]],
        ];
        $result = new ResourceGenerator()->generate($params);
        $this->assertFileExists($result['file']);
        $files = File::allFiles($this->directory);
        $this->assertNotEmpty($files);
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            // PHP's lexer/parser checks syntax without executing generated files.
            $tokens = token_get_all(File::get($file->getPathname()), TOKEN_PARSE);
            foreach ($tokens as $token) {
                if (is_array($token) && $token[0] === T_STRING) {
                    $this->assertNotSame('security_probe', $token[1], $file->getPathname());
                }
            }
        }
    }

    public function test_resource_identifiers_cannot_escape_the_generated_directory(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $result = new ResourceGenerator()->generate(['service' => 'safe', 'name' => '../../Escape', 'fields' => []]);
        $this->fail('Unsafe resource generated: '.$result['file']);
    }

    public function test_hosted_generation_rejects_callable_and_storage_methods(): void
    {
        $this->enableHosted();
        foreach (['when', 'tap', 'customView', 'deleteStorageFile', 'disk', 'setAttribute'] as $method) {
            try {
                $result = new ResourceGenerator()->generate([
                    'service' => 'safe', 'name' => 'item', 'endpoint' => '/items',
                    'fields' => [['column' => 'file', 'type' => 'text', 'methods' => [['name' => $method, 'args' => ['security_probe']]]]],
                ]);
                $this->fail('Hosted method was accepted: '.$method.' in '.$result['file']);
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('not available for hosted generation', $exception->getMessage());
            }
        }
    }

    public function test_hosted_method_catalog_matches_generation_restrictions(): void
    {
        config(['api-admin.hosted.mode' => 'hosted']);
        $result = new ListFieldMethodsTool()->execute(['type' => 'text']);
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('methods', $result);
        $names = array_column($result['methods'], 'name');
        $this->assertContains('required', $names);
        $this->assertNotContains('customView', $names);
        $this->assertNotContains('setAttribute', $names);
        $this->assertNotContains('when', $names);
    }

    public function test_hosted_rules_reject_database_custom_and_network_validation(): void
    {
        $this->enableHosted();
        foreach (['exists:moonshine_users,email', 'unique:api_admin_tenant_secrets,value', 'current_password', 'active_url', 'email:dns', 'regex:/x+/', 'CustomValidationRule'] as $rule) {
            try {
                $result = new ResourceGenerator()->generate([
                    'service' => 'safe', 'name' => 'item', 'fields' => [['column' => 'value', 'type' => 'text']],
                    'rules' => ['value' => ['create' => ['required', $rule], 'update' => 'nullable']],
                ]);
                $this->fail('Hosted validation was accepted in '.$result['file']);
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('hosted', strtolower($exception->getMessage()));
            }
        }
        $result = new ResourceGenerator()->generate([
            'service' => 'safe', 'name' => 'valid', 'fields' => [['column' => 'email', 'type' => 'email']],
            'rules' => ['create' => ['email' => 'required|email:rfc|max:255'], 'update' => ['email' => ['nullable', 'email:filter']]],
        ]);
        $this->assertStringContainsString('required|email:rfc|max:255', File::get($result['pages']['form']));
    }

    public function test_hosted_upload_and_custom_field_types_are_not_available(): void
    {
        $this->enableHosted();
        $catalog = ResourceGenerator::fieldTypeCatalog();
        $this->assertArrayNotHasKey('file', $catalog);
        $this->assertArrayNotHasKey('image', $catalog);
        foreach (['file', 'image', 'custom_widget'] as $type) {
            try {
                $result = new ResourceGenerator()->generate(['service' => 'safe', 'name' => 'item', 'fields' => [['column' => 'media', 'type' => $type]]]);
                $this->fail('Unsafe hosted field generated in '.$result['file']);
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('external URL', $exception->getMessage());
            }
        }
    }

    private function enableHosted(): void
    {
        config(['api-admin.hosted.mode' => 'hosted']);
        app()->instance(TenantResolver::class, new readonly class implements TenantResolver
        {
            public function resolve(): TenantContext
            {
                return new TenantContext('1', 'safe', 'Safe', 'hosted', false);
            }
        });
    }

    public function test_layout_logos_remain_literals_and_can_be_replaced_again(): void
    {
        $payload = "'.security_probe().'";
        foreach (['updateLogo' => 'getLogo', 'updateLogoSmall' => 'getLogoSmall'] as $update => $getter) {
            $source = "<?php class Example { public function {$getter}(): string { return 'logo'; } }";
            $method = new ReflectionMethod(ConfigureLayoutTool::class, $update);
            $updated = $method->invoke(new ConfigureLayoutTool, $source, $payload);
            $this->assertIsString($updated);
            $this->assertStringContainsString(var_export($payload, true), $updated);
            foreach (token_get_all($updated, TOKEN_PARSE) as $token) {
                if (is_array($token) && $token[0] === T_STRING) {
                    $this->assertNotSame('security_probe', $token[1]);
                }
            }
            $updatedAgain = $method->invoke(new ConfigureLayoutTool, $updated, 'next-logo');
            $this->assertIsString($updatedAgain);
            $this->assertStringContainsString("return 'next-logo';", $updatedAgain);
        }
    }
}
