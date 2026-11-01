<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Unit;

use App\ApiAdmin\Instructor\InstructorProposalBuilder;
use Orchestra\Testbench\TestCase;

final class InstructorProposalBuilderTest extends TestCase
{
    public function test_builds_register_service_and_initial_resource_proposal(): void
    {
        $result = (new InstructorProposalBuilder)->build([
            'success' => true,
            'base_url' => 'https://blog.example.test/api',
            'warnings' => [],
            'probes' => [[
                'success' => true,
                'path' => '/posts',
                'guessed_format' => 'laravel',
                'guessed_mapping' => ['data_key' => 'data', 'primary_key' => 'id'],
                'detected_columns' => ['id', 'title', 'body', 'published_at'],
            ]],
        ], [
            'auth_type' => 'bearer',
            'api_key' => 'secret-token',
        ]);

        $proposal = $result['proposal'];
        $service = $proposal['service'];
        $this->assertIsArray($service);
        $resources = $proposal['resources'];
        $this->assertIsArray($resources);
        $resource = $resources[0];
        $this->assertIsArray($resource);
        $fields = $resource['fields'];
        $this->assertIsArray($fields);
        $thirdField = $fields[2];
        $this->assertIsArray($thirdField);
        $search = $resource['search'];
        $this->assertIsArray($search);

        $this->assertSame('blog', $service['name']);
        $this->assertSame('[redacted]', $service['api_key']);
        $this->assertSame('blog-admin', $service['panel']);
        $this->assertSame('/posts', $resource['endpoint']);
        $this->assertSame('post', $resource['name']);
        $this->assertSame('textarea', $thirdField['type']);
        $this->assertContains('title', $search);
    }
}
