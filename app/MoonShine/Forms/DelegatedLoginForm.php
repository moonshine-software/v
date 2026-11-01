<?php

declare(strict_types=1);

namespace App\MoonShine\Forms;

use App\ApiAdmin\ServiceConfig\AdminAuthConfig;
use App\ApiAdmin\Tenancy\TenantRoutes;
use MoonShine\Contracts\Core\DependencyInjection\CoreContract;
use MoonShine\Contracts\UI\FieldContract;
use MoonShine\Contracts\UI\FormBuilderContract;
use MoonShine\Contracts\UI\FormContract;
use MoonShine\Crud\Forms\LoginForm;
use MoonShine\Support\Enums\FormMethod;
use MoonShine\UI\Fields\Password;

final readonly class DelegatedLoginForm implements FormContract
{
    public function __construct(private string $action, private CoreContract $core) {}

    public function __invoke(): FormBuilderContract
    {
        $form = new LoginForm(action: $this->action, core: $this->core)();
        $panel = request()->getPanelName();
        $services = config('api-admin.services', []);
        if (! is_array($services)) {
            return $form;
        }
        foreach ($services as $service) {
            if (! is_array($service) || ($service['panel'] ?? null) !== $panel) {
                continue;
            }
            $mode = data_get($service, 'admin_auth.mode');
            if ($mode === AdminAuthConfig::MODE_OAUTH2_AUTHORIZATION_CODE) {
                $form->fields([]);

                return $form->method(FormMethod::GET)
                    ->action(route('api-admin.oauth.redirect', ['panel' => $panel, ...TenantRoutes::routeParams()]))
                    ->submit('Sign in with OAuth');
            }
            if ($mode === AdminAuthConfig::MODE_DELEGATED_TOKEN) {
                $form->fields($form->getFields()->map(static fn (FieldContract $field): FieldContract => match ($field->getColumn()) {
                    'username' => Password::make('API token', 'username')->required()->customAttributes(['autocomplete' => 'off']),
                    'password' => Password::make('Refresh token (optional)', 'password')->customAttributes(['autocomplete' => 'off']),
                    default => $field,
                }));

                return $form;
            }
        }

        return $form;
    }
}
