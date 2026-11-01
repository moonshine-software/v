<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Unit\SpecStore;

use App\ApiAdmin\MCP\SpecStore\SpecReferenceWalker;
use PHPUnit\Framework\TestCase;

final class SpecReferenceWalkerTest extends TestCase
{
    public function test_finds_references_in_search_rules_filters(): void
    {
        $spec = [
            'fields' => [['column' => 'title', 'type' => 'text']],
            'search' => ['title', 'body'],
            'rules' => ['title' => 'required', 'body' => 'string'],
            'filters' => [['column' => 'title', 'type' => 'text']],
        ];

        $hits = SpecReferenceWalker::findReferences($spec, 'title');

        $locations = array_column($hits, 'location');
        $this->assertContains('search', $locations);
        $this->assertContains('rules', $locations);
        $this->assertContains('filters[0]', $locations);
    }

    public function test_finds_per_mode_rules_reference(): void
    {
        $spec = [
            'rules' => [
                'create' => ['password' => 'required'],
                'update' => ['title' => 'required'],
            ],
        ];

        $this->assertNotEmpty(SpecReferenceWalker::findReferences($spec, 'password'));
        $this->assertNotEmpty(SpecReferenceWalker::findReferences($spec, 'title'));
        $this->assertEmpty(SpecReferenceWalker::findReferences($spec, 'unmatched'));
    }

    public function test_finds_mutation_body_from_when_present_and_path_placeholder(): void
    {
        $spec = [
            'mutations' => [
                'create' => ['method' => 'POST', 'path' => '/posts', 'body_from' => ['title']],
                'update' => [
                    ['method' => 'PUT', 'path' => '/posts/{id}/{slug}', 'when_present' => 'slug'],
                    ['method' => 'POST', 'path' => '/posts/{id}/status', 'body' => ['status' => '{status}']],
                ],
            ],
        ];

        $titleHits = SpecReferenceWalker::findReferences($spec, 'title');
        $this->assertNotEmpty($titleHits);

        $slugHits = SpecReferenceWalker::findReferences($spec, 'slug');
        $details = array_column($slugHits, 'detail');
        $this->assertContains('when_present', $details);
        $this->assertContains('path[placeholder]', $details);

        $statusHits = SpecReferenceWalker::findReferences($spec, 'status');
        $details = array_column($statusHits, 'detail');
        $this->assertTrue(in_array('body[placeholder]', $details, true) || in_array('body[key]', $details, true));
    }

    public function test_finds_layout_tree_and_groups_references(): void
    {
        $spec = [
            'layout' => [
                'form' => [
                    'tree' => [
                        'type' => 'box',
                        'children' => [
                            ['type' => 'fields', 'columns' => ['title', 'body']],
                        ],
                    ],
                    'groups' => [
                        ['label' => 'Meta', 'columns' => ['slug']],
                    ],
                ],
            ],
        ];

        $treeHits = array_column(SpecReferenceWalker::findReferences($spec, 'title'), 'location');
        $this->assertContains('layout.form.tree', $treeHits);

        $groupHits = array_column(SpecReferenceWalker::findReferences($spec, 'slug'), 'location');
        $this->assertContains('layout.form.groups[0]', $groupHits);
    }

    public function test_finds_sibling_depends_on_and_endpoint_query_placeholder(): void
    {
        $spec = [
            'fields' => [
                ['column' => 'project_id', 'type' => 'belongs_to'],
                [
                    'column' => 'status',
                    'type' => 'belongs_to',
                    'depends_on' => ['project_id'],
                    'endpoint_query' => ['project_id' => '{project_id}'],
                ],
            ],
        ];

        $hits = SpecReferenceWalker::findReferences($spec, 'project_id');
        $locations = array_column($hits, 'location');
        $this->assertContains('fields[1].depends_on', $locations);
        $this->assertContains('fields[1].endpoint_query', $locations);
    }

    public function test_self_reference_in_target_field_is_ignored(): void
    {
        $spec = [
            'fields' => [
                ['column' => 'title', 'type' => 'text'],
            ],
        ];

        $this->assertSame([], SpecReferenceWalker::findReferences($spec, 'title'));
    }

    public function test_remove_references_strips_search_rules_filters_layout(): void
    {
        $spec = [
            'fields' => [
                ['column' => 'id', 'type' => 'id'],
                ['column' => 'title', 'type' => 'text'],
            ],
            'search' => ['title'],
            'rules' => ['title' => 'required'],
            'filters' => [['column' => 'title', 'type' => 'text']],
            'layout' => [
                'form' => [
                    'tree' => [
                        'type' => 'box',
                        'children' => [
                            ['type' => 'fields', 'columns' => ['title', 'id']],
                        ],
                    ],
                    'groups' => [
                        ['label' => 'Main', 'columns' => ['title', 'id']],
                    ],
                ],
            ],
        ];

        $cleaned = SpecReferenceWalker::removeReferences($spec, 'title');

        $this->assertArrayNotHasKey('search', $cleaned);
        $this->assertArrayNotHasKey('rules', $cleaned);
        $this->assertArrayNotHasKey('filters', $cleaned);

        $fields = $cleaned['fields'];
        $this->assertIsArray($fields);
        $this->assertSame(['id'], array_column($fields, 'column'));

        $layout = $cleaned['layout'];
        $this->assertIsArray($layout);
        $form = $layout['form'];
        $this->assertIsArray($form);

        $tree = $form['tree'];
        $this->assertIsArray($tree);
        $children = $tree['children'];
        $this->assertIsArray($children);
        $first = $children[0];
        $this->assertIsArray($first);
        $this->assertSame(['id'], $first['columns']);

        $groups = $form['groups'];
        $this->assertIsArray($groups);
        $firstGroup = $groups[0];
        $this->assertIsArray($firstGroup);
        $columns = $firstGroup['columns'];
        $this->assertIsArray($columns);
        $this->assertSame(['id'], array_values($columns));
    }

    public function test_remove_references_strips_mutations_dropping_when_present_route(): void
    {
        $spec = [
            'fields' => [
                ['column' => 'status', 'type' => 'text'],
                ['column' => 'title', 'type' => 'text'],
            ],
            'mutations' => [
                'create' => ['method' => 'POST', 'body_from' => ['title', 'status']],
                'update' => [
                    ['method' => 'PUT', 'when_present' => 'status', 'path' => '/x/{id}/status'],
                    ['method' => 'PUT', 'when_present' => 'title', 'path' => '/x/{id}'],
                ],
            ],
        ];

        $cleaned = SpecReferenceWalker::removeReferences($spec, 'status');

        $mutations = $cleaned['mutations'];
        $this->assertIsArray($mutations);
        $create = $mutations['create'];
        $this->assertIsArray($create);
        $this->assertSame(['title'], $create['body_from']);

        $update = $mutations['update'];
        $this->assertIsArray($update);
        $this->assertCount(1, $update);
        $remaining = $update[0];
        $this->assertIsArray($remaining);
        $this->assertSame('title', $remaining['when_present']);
    }

    public function test_remove_references_strips_sibling_depends_on_and_endpoint_query(): void
    {
        $spec = [
            'fields' => [
                ['column' => 'project_id', 'type' => 'belongs_to'],
                [
                    'column' => 'status',
                    'type' => 'belongs_to',
                    'depends_on' => ['project_id'],
                    'endpoint_query' => ['project_id' => '{project_id}', 'tenant' => 'global'],
                ],
            ],
        ];

        $cleaned = SpecReferenceWalker::removeReferences($spec, 'project_id');
        $fields = $cleaned['fields'];
        $this->assertIsArray($fields);
        $this->assertSame(['status'], array_column($fields, 'column'));
        $remaining = $fields[0];
        $this->assertIsArray($remaining);
        $this->assertArrayNotHasKey('depends_on', $remaining);
        $this->assertSame(['tenant' => 'global'], $remaining['endpoint_query']);
    }
}
