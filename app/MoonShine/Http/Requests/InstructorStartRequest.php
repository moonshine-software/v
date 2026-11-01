<?php

declare(strict_types=1);

namespace App\MoonShine\Http\Requests;

use App\ApiAdmin\Tenancy\TenantBrowserAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

final class InstructorStartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return config('api-admin.hosted.mode', 'local') !== 'hosted'
            || app(TenantBrowserAccess::class)->allows(Auth::guard('moonshine')->user());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'base_url' => ['required', 'url', 'max:2048'],
            'service_name' => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9_-]+$/'],
            'resource_name' => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9_-]+$/'],
            'endpoint_paths' => ['nullable', 'string', 'max:2000'],
            'auth_type' => ['required', Rule::in(['none', 'bearer', 'header', 'query', 'basic'])],
            'auth_header' => ['nullable', 'string', 'max:120'],
            'api_key' => ['nullable', 'string', 'max:4096'],
            'health_path' => ['nullable', 'string', 'max:2048'],
        ];
    }
}
