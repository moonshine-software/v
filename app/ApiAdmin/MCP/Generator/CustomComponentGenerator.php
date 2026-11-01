<?php

declare(strict_types=1);

namespace App\ApiAdmin\MCP\Generator;

use App\ApiAdmin\MCP\AtomicFileWriter;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use MoonShine\UI\Components\Layout\Box;
use MoonShine\UI\Components\MoonShineComponent;
use RuntimeException;

/**
 * Emits a caller-generated custom MoonShine component under
 * `app/ApiAdmin/Components/Custom/<Name>.php` + matching blade view at
 * `resources/views/api-admin/components/custom/<name>.blade.php`, and
 * registers the component in `api-admin.custom_components` so
 * `list_components` surfaces it.
 *
 * Mirror of CustomFieldGenerator — same transactional file-plus-config
 * write through ConfigArrayBlock.
 */
final class CustomComponentGenerator
{
    /**
     * @param array{
     *     name: string,
     *     extends?: string,
     *     view_content?: string,
     *     properties?: list<array{name: string, type: string, default?: string}>,
     * } $params
     *
     * @return array{file: string, view: string, class: string}
     */
    public function generate(array $params): array
    {
        $name = Str::studly($params['name']);
        if ($name === '') {
            throw new RuntimeException('Custom component name must be non-empty');
        }

        $extends = $params['extends'] ?? 'MoonShineComponent';
        $parentFqcn = $this->resolveParent($extends);
        $parentBasename = $this->classBaseName($parentFqcn);
        $useStatements = [];
        if (! str_starts_with($parentFqcn, MoonShineComponent::class)) {
            $useStatements[] = 'use '.$parentFqcn.';';
        }

        $viewName = Str::snake($name, '-');
        $viewPath = 'api-admin-custom::'.$viewName;

        $properties = $this->renderProperties($params['properties'] ?? []);

        $classStub = File::get(base_path('stubs/api-admin/custom-component.stub'));
        $rendered = strtr($classStub, [
            '{{className}}' => $name,
            '{{parentClass}}' => $parentBasename,
            '{{viewPath}}' => $viewPath,
            '{{viewName}}' => $viewName,
            '{{properties}}' => $properties,
        ]);

        if ($useStatements !== []) {
            $rendered = preg_replace(
                '/(namespace App\\\\ApiAdmin\\\\Components\\\\Custom;\\n\\nuse[^\\n]+;)/',
                "namespace App\\\\ApiAdmin\\\\Components\\\\Custom;\n\n".implode("\n", $useStatements),
                $rendered,
                1,
            ) ?? $rendered;
        }

        $classDir = base_path('app/ApiAdmin/Components/Custom');
        AtomicFileWriter::ensureDirectoryExists($classDir);
        $classFile = $classDir.'/'.$name.'.php';
        AtomicFileWriter::put($classFile, $rendered);

        $viewDir = base_path('resources/views/api-admin/components/custom');
        AtomicFileWriter::ensureDirectoryExists($viewDir);
        $viewFile = $viewDir.'/'.$viewName.'.blade.php';

        $viewStub = File::get(base_path('stubs/api-admin/custom-component.blade.stub'));
        $viewContent = isset($params['view_content']) && $params['view_content'] !== ''
            ? $params['view_content']
            : "    {{-- {$name} --}}";
        $renderedView = strtr($viewStub, ['{{viewContent}}' => $viewContent]);
        AtomicFileWriter::put($viewFile, $renderedView);

        $fqcn = 'App\\ApiAdmin\\Components\\Custom\\'.$name;
        $this->registerInConfig($name, $fqcn);

        Log::info('CustomComponentGenerator::generate', [
            'name' => $name,
            'class_file' => $classFile,
            'view_file' => $viewFile,
        ]);

        return [
            'file' => $classFile,
            'view' => $viewFile,
            'class' => $fqcn,
        ];
    }

    public function delete(string $name): bool
    {
        $studly = Str::studly($name);
        $viewName = Str::snake($name, '-');

        $classFile = base_path('app/ApiAdmin/Components/Custom/'.$studly.'.php');
        $viewFile = base_path('resources/views/api-admin/components/custom/'.$viewName.'.blade.php');

        $existed = false;
        if (File::exists($classFile)) {
            AtomicFileWriter::delete($classFile);
            $existed = true;
        }
        if (File::exists($viewFile)) {
            AtomicFileWriter::delete($viewFile);
            $existed = true;
        }

        if ($this->unregisterFromConfig($studly)) {
            return true;
        }

        return $existed;
    }

    private function resolveParent(string $extends): string
    {
        $map = [
            'MoonShineComponent' => MoonShineComponent::class,
            'Box' => Box::class,
        ];

        if (isset($map[$extends])) {
            return $map[$extends];
        }

        if (class_exists($extends)) {
            return $extends;
        }

        throw new RuntimeException("Unknown parent component: {$extends}");
    }

    /**
     * @param list<array{name: string, type: string, default?: string}> $properties
     */
    private function renderProperties(array $properties): string
    {
        if ($properties === []) {
            return '';
        }
        $lines = [];
        foreach ($properties as $prop) {
            $default = isset($prop['default']) && $prop['default'] !== '' ? ' = '.$prop['default'] : '';
            $lines[] = '    protected '.$prop['type'].' $'.$prop['name'].$default.';';
        }

        return implode("\n", $lines)."\n";
    }

    private function registerInConfig(string $name, string $fqcn): void
    {
        $path = config_path('api-admin.php');
        $source = File::get($path);
        $entry = var_export($name, true)." => \\{$fqcn}::class";
        AtomicFileWriter::put($path, ConfigArrayBlock::rewrite($source, 'custom_components', $name, $entry));

        config(['api-admin.custom_components' => array_merge(
            (array) config('api-admin.custom_components', []),
            [$name => $fqcn],
        )]);
    }

    private function unregisterFromConfig(string $name): bool
    {
        $path = config_path('api-admin.php');
        $source = File::get($path);
        $updated = ConfigArrayBlock::rewrite($source, 'custom_components', $name, null);

        if ($updated === $source) {
            return false;
        }

        AtomicFileWriter::put($path, $updated);
        $current = (array) config('api-admin.custom_components', []);
        unset($current[$name]);
        config(['api-admin.custom_components' => $current]);

        return true;
    }

    private function classBaseName(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }
}
