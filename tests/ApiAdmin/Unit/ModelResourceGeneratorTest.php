<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Unit;

use App\ApiAdmin\MCP\Generator\ModelResourceGenerator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Orchestra\Testbench\TestCase;
use Override;
use RuntimeException;

final class ModelResourceGeneratorTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir().'/moonshine-test-local-resources-'.uniqid('', true);
        File::ensureDirectoryExists($this->tmpRoot);

        config([
            'api-admin.local_resources_path' => $this->tmpRoot,
            'api-admin.local_resources_namespace' => 'App\\MoonShine\\Resources',
        ]);
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->tmpRoot)) {
            File::deleteDirectory($this->tmpRoot);
        }

        config([
            'api-admin.local_resources_path' => app_path('MoonShine/Resources'),
            'api-admin.local_resources_namespace' => 'App\\MoonShine\\Resources',
        ]);

        parent::tearDown();
    }

    public function test_generates_local_model_resource_with_pages(): void
    {
        $result = (new ModelResourceGenerator)->generate([
            'service' => 'catalog',
            'panel' => 'catalog-admin',
            'name' => 'post',
            'model' => ModelResourceGeneratorFakePost::class,
            'title' => 'Posts',
            'group' => 'Content',
            'icon' => 'newspaper',
            'fields' => [
                ['column' => 'id', 'type' => 'id'],
                ['column' => 'title', 'type' => 'text', 'label' => 'Title', 'methods' => ['sortable', 'required']],
                ['column' => 'body', 'type' => 'textarea', 'label' => 'Body'],
                ['column' => 'email', 'type' => 'email', 'label' => 'Author Email'],
                ['column' => 'status', 'type' => 'select', 'options' => ['draft' => 'Draft', 'published' => 'Published']],
                ['column' => 'password', 'type' => 'password', 'hide_on' => ['index', 'detail']],
            ],
            'rules' => [
                'title' => 'required|string|max:255',
                'email' => ['nullable', 'email'],
            ],
            'search' => ['id', 'title'],
            'actions' => ['create', 'update', 'delete'],
        ]);

        $this->assertSame('PostResource', $result['className']);
        $this->assertSame('App\\MoonShine\\Resources\\Post\\PostResource', $result['class']);
        $this->assertStringEndsWith('/Post/PostResource.php', $result['file']);
        $this->assertFileExists($result['pages']['index']);
        $this->assertFileExists($result['pages']['form']);
        $detailPage = $result['pages']['detail'] ?? null;
        $this->assertIsString($detailPage);
        $this->assertFileExists($detailPage);

        $resource = File::get($result['file']);
        $this->assertStringContainsString('class PostResource extends MultiPanelResource', $resource);
        $this->assertStringEndsWith("    }\n}\n", $resource);
        $this->assertStringContainsString('protected string $model = ModelResourceGeneratorFakePost::class;', $resource);
        $this->assertStringContainsString("protected string \$panel = 'catalog-admin';", $resource);
        $this->assertStringContainsString("protected string \$title = 'Posts';", $resource);
        $this->assertStringContainsString("protected string \$column = 'title';", $resource);
        $this->assertStringContainsString("#[Icon('newspaper')]", $resource);
        $this->assertStringContainsString("#[Group('Content')]", $resource);
        $this->assertStringContainsString('PostIndexPage::class', $resource);
        $this->assertStringContainsString('PostFormPage::class', $resource);
        $this->assertStringContainsString('PostDetailPage::class', $resource);
        $this->assertStringContainsString('Action::CREATE', $resource);
        $this->assertStringContainsString('Action::UPDATE', $resource);
        $this->assertStringContainsString('Action::DELETE', $resource);
        $this->assertStringContainsString("'title',", $resource);

        $index = File::get($result['pages']['index']);
        $this->assertStringContainsString('final class PostIndexPage extends IndexPage', $index);
        $this->assertStringContainsString("Text::make('Title', 'title')->sortable()->required()", $index);
        $this->assertStringContainsString("Email::make('Author Email', 'email')", $index);
        $this->assertStringContainsString("Select::make('Status', 'status')->options(array (  'draft' => 'Draft',  'published' => 'Published',))", $index);
        $this->assertStringNotContainsString('Password::make', $index);
        $this->assertStringNotContainsString('RestBelongsTo', $index);

        $form = File::get($result['pages']['form']);
        $this->assertStringContainsString('final class PostFormPage extends FormPage', $form);
        $this->assertStringContainsString('Box::make([', $form);
        $this->assertStringContainsString("Textarea::make('Body', 'body')", $form);
        $this->assertStringContainsString("Password::make('Password', 'password')", $form);
        $this->assertStringNotContainsString('->raw()', $form);
        $this->assertStringContainsString("'title' => 'required|string|max:255'", $form);
        $this->assertStringContainsString("'email' => array (  0 => 'nullable',  1 => 'email',)", $form);

        foreach ([$result['file'], ...array_values($result['pages'])] as $file) {
            $this->assertSame(0, $this->lint($file), $file);
        }
    }

    public function test_can_omit_detail_page(): void
    {
        $result = (new ModelResourceGenerator)->generate([
            'service' => 'catalog',
            'panel' => 'catalog-admin',
            'name' => 'category',
            'model' => ModelResourceGeneratorFakePost::class,
            'detail' => false,
            'fields' => [
                ['column' => 'id', 'type' => 'id'],
                ['column' => 'name', 'type' => 'text'],
            ],
        ]);

        $this->assertArrayNotHasKey('detail', $result['pages']);

        $resource = File::get($result['file']);
        $this->assertStringContainsString('@extends MultiPanelResource<ModelResourceGeneratorFakePost, CategoryIndexPage, CategoryFormPage, null>', $resource);
        $this->assertStringNotContainsString('CategoryDetailPage::class', $resource);
        $this->assertFileDoesNotExist($this->tmpRoot.'/Category/Pages/CategoryDetailPage.php');
    }

    public function test_rejects_missing_or_non_eloquent_model(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Model class 'Tests\\ApiAdmin\\Unit\\MissingPost' does not exist");

        $_ = (new ModelResourceGenerator)->generate([
            'service' => 'catalog',
            'name' => 'post',
            'model' => 'Tests\\ApiAdmin\\Unit\\MissingPost',
            'fields' => [
                ['column' => 'id', 'type' => 'id'],
            ],
        ]);
    }

    private function lint(string $path): int
    {
        $output = [];
        $code = 0;
        exec('php -l '.escapeshellarg($path).' 2>&1', $output, $code);

        return $code;
    }
}

final class ModelResourceGeneratorFakePost extends Model
{
    #[Override]
    protected $table = 'posts';
}
