<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Unit\Generator;

use App\ApiAdmin\MCP\Generator\ResourceGenerator;
use Illuminate\Support\Facades\File;
use Orchestra\Testbench\TestCase;

/**
 * Per-page `<page>_fields` arrays REPLACE the canonical `fields[]` for
 * that page. When a key (`index_fields` / `form_fields` /
 * `detail_fields`) is present and non-empty, the generator emits its
 * contents on that page; `hide_on` becomes irrelevant. Without the
 * override, the legacy hide_on-filtered behavior is preserved.
 */
final class PerPageFieldsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $path = sys_get_temp_dir().'/moonshine-per-page-fields-'.bin2hex(random_bytes(8));
        config(['api-admin.resources_path' => $path, 'api-admin.specs_path' => $path.'/.specs']);
    }

    protected function tearDown(): void
    {
        $path = config('api-admin.resources_path');
        if (is_string($path) && File::isDirectory($path)) {
            File::deleteDirectory($path);
        }

        parent::tearDown();
    }

    public function test_form_fields_override_replaces_fields_on_form_page(): void
    {
        $result = (new ResourceGenerator)->generate([
            'service' => 'pp',
            'name' => 'post',
            'endpoint' => '/posts',
            'fields' => [
                ['column' => 'id', 'type' => 'id'],
                ['column' => 'title', 'type' => 'text'],
                ['column' => 'body', 'type' => 'textarea'],
            ],
            'form_fields' => [
                ['column' => 'title', 'type' => 'text'],
                ['column' => 'subtitle', 'type' => 'text'],
            ],
        ]);

        $formContent = File::get($result['pages']['form']);
        $indexContent = File::get($result['pages']['index']);
        $detailContent = File::get($result['pages']['detail']);

        // Form page emits the override list — subtitle present, body absent.
        $this->assertStringContainsString("'subtitle'", $formContent);
        $this->assertStringNotContainsString("'body'", $formContent);

        // Index/detail still come from canonical fields[] — body present.
        $this->assertStringContainsString("'body'", $indexContent);
        $this->assertStringContainsString("'body'", $detailContent);
    }

    public function test_index_fields_override_used_when_present(): void
    {
        $result = (new ResourceGenerator)->generate([
            'service' => 'pp',
            'name' => 'post',
            'endpoint' => '/posts',
            'fields' => [
                ['column' => 'id', 'type' => 'id'],
                ['column' => 'title', 'type' => 'text'],
                ['column' => 'body', 'type' => 'textarea'],
            ],
            'index_fields' => [
                ['column' => 'id', 'type' => 'id'],
                ['column' => 'title', 'type' => 'text'],
            ],
        ]);

        $indexContent = File::get($result['pages']['index']);
        $formContent = File::get($result['pages']['form']);

        $this->assertStringNotContainsString("'body'", $indexContent);
        $this->assertStringContainsString("'body'", $formContent);
    }

    public function test_falls_back_to_fields_with_hide_on_when_no_override(): void
    {
        $result = (new ResourceGenerator)->generate([
            'service' => 'pp',
            'name' => 'post',
            'endpoint' => '/posts',
            'fields' => [
                ['column' => 'id', 'type' => 'id', 'hide_on' => ['form']],
                ['column' => 'title', 'type' => 'text'],
                ['column' => 'body', 'type' => 'textarea'],
            ],
        ]);

        $indexContent = File::get($result['pages']['index']);
        $formContent = File::get($result['pages']['form']);

        // hide_on still works without override
        $this->assertStringContainsString('ID::make', $indexContent);
        $this->assertStringNotContainsString('ID::make', $formContent);
    }

    public function test_empty_override_is_treated_as_absent(): void
    {
        // An empty list should not REPLACE the canonical fields with nothing
        // — that would render a blank page. Treat empty as "no override".
        $result = (new ResourceGenerator)->generate([
            'service' => 'pp',
            'name' => 'post',
            'endpoint' => '/posts',
            'fields' => [
                ['column' => 'id', 'type' => 'id'],
                ['column' => 'title', 'type' => 'text'],
            ],
            'detail_fields' => [],
        ]);

        $detailContent = File::get($result['pages']['detail']);
        $this->assertStringContainsString("'title'", $detailContent);
    }
}
