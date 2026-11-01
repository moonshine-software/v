<?php

declare(strict_types=1);

namespace App\MoonShine\Http\Requests;

use App\ApiAdmin\Tenancy\TenantBrowserAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

final class InstructorConfirmRequest extends FormRequest
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
            'api_key' => ['nullable', 'string', 'max:4096'],
            'oauth_client_secret' => ['nullable', 'string', 'max:4096'],
            'oauth_password' => ['nullable', 'string', 'max:4096'],
            'oauth_refresh_token' => ['nullable', 'string', 'max:4096'],
            'session_password' => ['nullable', 'string', 'max:4096'],
        ];
    }
}
