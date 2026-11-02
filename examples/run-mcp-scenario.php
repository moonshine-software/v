<?php

declare(strict_types=1);

use App\ApiAdmin\MCP\McpServer;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();

$blogUrl = $argv[1] ?? 'http://127.0.0.1:9101/v1';
$shopUrl = $argv[2] ?? 'http://127.0.0.1:9102/api';

$server = new McpServer;

$steps = [
    [
        'title' => 'register_service(blog)',
        'tool' => 'register_service',
        'params' => [
            'name' => 'blog',
            'base_url' => $blogUrl,
            'panel' => 'blog-admin',
            'title' => 'Blog Admin',
            'health_path' => '/health',
        ],
    ],
    [
        'title' => 'register_service(shop)',
        'tool' => 'register_service',
        'params' => [
            'name' => 'shop',
            'base_url' => $shopUrl,
            'panel' => 'shop-admin',
            'title' => 'Shop Admin',
            'health_path' => '/health',
            'response_format' => 'django',
        ],
    ],
    [
        'title' => 'create_resource(blog/posts)',
        'tool' => 'create_resource',
        'params' => [
            'service' => 'blog',
            'name' => 'post',
            'endpoint' => '/posts',
            'title' => 'Posts',
            'fields' => [
                ['column' => 'id', 'type' => 'id'],
                ['column' => 'title', 'type' => 'text', 'methods' => ['sortable', 'required']],
                ['column' => 'body', 'type' => 'textarea'],
                ['column' => 'category_id', 'type' => 'belongs_to', 'label' => 'Category', 'endpoint' => '/categories'],
                [
                    'column' => 'comments',
                    'type' => 'has_many',
                    'endpoint' => '/posts/{parent_id}/comments',
                    'fields' => [
                        ['column' => 'author_name', 'type' => 'text', 'label' => 'Author'],
                        ['column' => 'body', 'type' => 'text', 'label' => 'Body'],
                    ],
                ],
                [
                    'column' => 'author',
                    'type' => 'has_one',
                    'endpoint' => '/posts/{parent_id}/author',
                    'fields' => [
                        ['column' => 'name', 'type' => 'text', 'label' => 'Name'],
                        ['column' => 'email', 'type' => 'email', 'label' => 'Email'],
                        ['column' => 'bio', 'type' => 'textarea', 'label' => 'Bio'],
                    ],
                ],
                ['column' => 'tags', 'type' => 'belongs_to_many', 'endpoint' => '/tags'],
                [
                    'column' => 'status',
                    'type' => 'select',
                    'options' => [
                        'draft' => 'Draft',
                        'published' => 'Published',
                        'archived' => 'Archived',
                    ],
                ],
            ],
            'search' => ['title', 'body'],
        ],
    ],
    [
        'title' => 'create_resource(shop/products)',
        'tool' => 'create_resource',
        'params' => [
            'service' => 'shop',
            'name' => 'product',
            'endpoint' => '/products',
            'title' => 'Products',
            'fields' => [
                ['column' => 'id', 'type' => 'id'],
                ['column' => 'name', 'type' => 'text', 'methods' => ['sortable', 'required']],
                ['column' => 'sku', 'type' => 'text', 'methods' => ['sortable']],
                ['column' => 'price', 'type' => 'number', 'methods' => ['required']],
                ['column' => 'brand_id', 'type' => 'belongs_to', 'endpoint' => '/brands'],
            ],
            'search' => ['name', 'sku'],
        ],
    ],
];

echo "Running MCP scenario for two APIs...\n";

foreach ($steps as $step) {
    echo "- {$step['title']}\n";
    $result = $server->executeTool($step['tool'], $step['params']);
    if (($result['success'] ?? false) !== true) {
        $error = $result['error'] ?? 'unknown error';
        fwrite(STDERR, "FAILED: {$error}\n");
        exit(1);
    }
}

echo "Done.\n";
echo "Generated panels: /blog-admin and /shop-admin\n";
