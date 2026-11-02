<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Unit;

use App\ApiAdmin\Instructor\InstructorApplyService;
use Illuminate\Support\Facades\File;
use Orchestra\Testbench\TestCase;
use Tests\ApiAdmin\Concerns\UsesIsolatedProjectRoot;

final class InstructorApplyServiceTest extends TestCase
{
    use UsesIsolatedProjectRoot;

    private string $resourcesRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useIsolatedProjectRoot(app());

        $this->resourcesRoot = base_path('app/MoonShine/ApiAdmin/Resources');

        config([
            'api-admin.configs_path' => base_path('Configs'),
            'api-admin.layouts_path' => base_path('Layouts'),
            'api-admin.resources_path' => $this->resourcesRoot,
            'api-admin.specs_path' => base_path('.ai-factory/specs'),
            'api-admin.generated_services_path' => base_path('generated/services.php'),
            'api-admin.generated_panels_path' => base_path('generated/panels.php'),
            'api-admin.services' => [],
        ]);

        File::put(config_path('api-admin.php'), "<?php\n\ndeclare(strict_types=1);\n\nreturn ".var_export(['services' => []], true).";\n");
        File::put(config_path('moonshine.php'), "<?php\n\nreturn [\n    'middleware' => [\n        ChangeLocale::class,\n    ],\n    'use_routes' => true,\n];\n");
    }

    protected function tearDown(): void
    {
        $this->removeIsolatedProjectRoot();

        parent::tearDown();
    }

    public function test_rejects_invalid_proposal_before_any_generation(): void
    {
        $result = (new InstructorApplyService)->apply([]);

        $this->assertFalse($result['success']);
        $this->assertSame('VALIDATION', $result['code']);
    }

    public function test_rejects_existing_service_to_keep_confirmation_idempotent(): void
    {
        config([
            'api-admin.services' => [
                'blog' => ['name' => 'blog', 'panel' => 'blog-admin'],
            ],
        ]);

        $result = (new InstructorApplyService)->apply([
            'service' => ['name' => 'blog', 'base_url' => 'https://api.example.test', 'auth_type' => 'none'],
            'resources' => [
                ['name' => 'post', 'endpoint' => '/posts', 'fields' => [['column' => 'id', 'type' => 'id']]],
            ],
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('SERVICE_EXISTS', $result['code']);
    }

    public function test_applies_confirmed_proposal_through_generation_tools(): void
    {
        $result = (new InstructorApplyService)->apply([
            'service' => [
                'name' => 'verify_instructor',
                'base_url' => 'https://api.example.test',
                'auth_type' => 'none',
                'panel' => 'verify-instructor-admin',
                'title' => 'Verify Instructor Admin',
                'health_path' => '/health',
                'response_format' => 'laravel',
                'response_mapping' => [
                    'data_key' => 'data',
                    'total_key' => 'total',
                    'per_page_key' => 'per_page',
                    'current_page_key' => 'current_page',
                    'last_page_key' => 'last_page',
                    'primary_key' => 'id',
                ],
            ],
            'resources' => [[
                'name' => 'user',
                'endpoint' => '/users',
                'title' => 'Users',
                'fields' => [
                    ['column' => 'id', 'label' => 'ID', 'type' => 'id', 'hide_on' => ['form']],
                    ['column' => 'email', 'label' => 'Email', 'type' => 'email'],
                    ['column' => 'name', 'label' => 'Name', 'type' => 'text'],
                ],
                'search' => ['email', 'name'],
                'filters' => [],
            ]],
        ]);

        $this->assertTrue($result['success'], json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: 'apply failed');
        $this->assertSame('verify_instructor', $result['service']);
        $this->assertSame('verify-instructor-admin', $result['panel']);
        $this->assertSame('/verify-instructor-admin', $result['next_url']);

        $resourcePath = $this->resourcesRoot.'/VerifyInstructor/User/UserResource.php';

        $this->assertFileExists(base_path().'/Configs/VerifyInstructorConfig.php');
        $this->assertFileExists(base_path().'/Layouts/VerifyInstructorLayout.php');
        $this->assertFileExists($resourcePath);
        $this->assertFileExists($this->resourcesRoot.'/VerifyInstructor/User/Pages/UserIndexPage.php');
        $this->assertFileExists($this->resourcesRoot.'/VerifyInstructor/User/Pages/UserFormPage.php');
        $this->assertFileExists($this->resourcesRoot.'/VerifyInstructor/User/Pages/UserDetailPage.php');

        $files = $result['files'];
        $this->assertIsArray($files);
        $this->assertContains(base_path().'/Configs/VerifyInstructorConfig.php', $files);
        $this->assertContains(base_path().'/Layouts/VerifyInstructorLayout.php', $files);
        $this->assertContains($resourcePath, $files);
        $this->assertDirectoryDoesNotExist(dirname(__DIR__, 3).'/app/MoonShine/ApiAdmin/Resources/VerifyInstructor');
    }
}
