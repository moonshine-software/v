<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Unit;

use App\ApiAdmin\Validation\ApiErrorMapper;
use Orchestra\Testbench\TestCase;

class ApiErrorMapperTest extends TestCase
{
    public function test_extracts_laravel_format_errors(): void
    {
        $mapper = new ApiErrorMapper;

        $errors = $mapper->extractErrors([
            'errors' => [
                'name' => ['The name field is required.'],
                'email' => ['The email must be valid.', 'The email is already taken.'],
            ],
        ]);

        $this->assertTrue($errors->has('name'));
        $this->assertTrue($errors->has('email'));
        $this->assertCount(1, $errors->get('name'));
        $this->assertCount(2, $errors->get('email'));
    }

    public function test_extracts_django_format_errors(): void
    {
        $mapper = new ApiErrorMapper;

        $errors = $mapper->extractErrors([
            'errors' => [
                ['field' => 'name', 'message' => 'This field is required.'],
                ['field' => 'price', 'message' => 'Ensure this value is greater than 0.'],
            ],
        ]);

        $this->assertTrue($errors->has('name'));
        $this->assertTrue($errors->has('price'));
    }

    public function test_maps_field_names(): void
    {
        $mapper = (new ApiErrorMapper)->fieldMap([
            'product_name' => 'name',
            'product_price' => 'price',
        ]);

        $errors = $mapper->extractErrors([
            'errors' => [
                'product_name' => ['Required'],
                'product_price' => ['Must be positive'],
            ],
        ]);

        $this->assertTrue($errors->has('name'));
        $this->assertTrue($errors->has('price'));
        $this->assertFalse($errors->has('product_name'));
    }

    public function test_custom_errors_key(): void
    {
        $mapper = (new ApiErrorMapper)->errorsKey('validation_errors');

        $errors = $mapper->extractErrors([
            'validation_errors' => [
                'title' => ['Required'],
            ],
        ]);

        $this->assertTrue($errors->has('title'));
    }

    public function test_flat_errors_at_top_level(): void
    {
        $mapper = new ApiErrorMapper;

        // Django REST style: errors directly at top level (not nested under 'errors')
        $errors = $mapper->extractErrors([
            'name' => ['This field is required.'],
            'email' => ['Invalid email.'],
        ]);

        $this->assertTrue($errors->has('name'));
        $this->assertTrue($errors->has('email'));
    }

    public function test_null_response_returns_empty_bag(): void
    {
        $mapper = new ApiErrorMapper;

        $errors = $mapper->extractErrors(null);

        $this->assertTrue($errors->isEmpty());
    }
}
