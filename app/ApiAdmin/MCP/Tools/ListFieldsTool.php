<?php

declare(strict_types=1);

namespace App\ApiAdmin\MCP\Tools;

use App\ApiAdmin\MCP\Generator\ResourceGenerator;
use Illuminate\Support\Facades\Log;
use stdClass;

/**
 * MCP Tool: list_fields
 *
 * Advertises every `type` string the generator will accept in the
 * `fields[].type` slot of `create_resource` / `update_resource`. Covers
 * built-in MoonShine fields plus anything registered in the
 * `api-admin.custom_fields` config (populated by `create_field`).
 *
 * Source of truth: {@see ResourceGenerator::fieldTypeCatalog()} — there
 * is no hand-curated catalog to drift out of sync.
 */
final class ListFieldsTool implements ToolContract
{
    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'name' => 'list_fields',
            'description' => 'List every usable `fields[].type` value — built-ins and custom fields registered via create_field in local mode. Hosted mode lists only scalar and REST relation fields, excluding file/image uploads and custom executable classes. Read-only. Call before create_resource to confirm no box primitive fits before reaching for create_field.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => new stdClass,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{
     *     success: bool,
     *     count: int,
     *     fields: list<array{type: string, class: class-string, alias: string, group: 'scalar'|'relation'|'media', description: string, is_custom: bool}>
     * }
     */
    public function execute(array $params): array
    {
        $catalog = ResourceGenerator::fieldTypeCatalog();

        Log::debug('list_fields: catalog built', ['count' => count($catalog)]);

        $fields = [];
        foreach ($catalog as $type => $entry) {
            $fields[] = [
                'type' => $type,
                'class' => $entry['class'],
                'alias' => $entry['alias'],
                'group' => $entry['group'],
                'description' => $entry['description'],
                'is_custom' => $entry['is_custom'],
            ];
        }

        return [
            'success' => true,
            'count' => count($fields),
            'fields' => $fields,
        ];
    }
}
