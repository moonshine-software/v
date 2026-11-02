<?php

declare(strict_types=1);

use App\ApiAdmin\Auth\EnsureDelegatedPanelState;
use App\ApiAdmin\Http\Middleware\EnsureTenantMembership;
use App\MoonShine\ApiAdmin\Layouts\AdminHubLayout;
use App\MoonShine\ApiAdmin\Pages\AdminHubPage;
use App\MoonShine\ApiAdmin\Pages\InstructorPage;
use App\MoonShine\Forms\DelegatedLoginForm;
use App\MoonShine\Layouts\MoonShineLayout;
use App\MoonShine\Pages\Dashboard;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use MoonShine\ColorManager\Palettes\PurplePalette;
use MoonShine\Crud\Forms\FiltersForm;
use MoonShine\Laravel\Exceptions\MoonShineNotFoundException;
use MoonShine\Laravel\Http\Middleware\Authenticate;
use MoonShine\Laravel\Http\Middleware\ChangeLocale;
use MoonShine\Laravel\Models\MoonshineUser;
use MoonShine\Laravel\Pages\ErrorPage;
use MoonShine\Laravel\Pages\LoginPage;
use MoonShine\Laravel\Pages\ProfilePage;
use MoonShine\MultiPanels\Http\Middleware\MultiPanelMiddleware;

$apiAdminHostedMode = env('API_ADMIN_HOSTED_MODE', 'local') === 'hosted';
$apiAdminTenantPrefix = trim((string) env('API_ADMIN_TENANT_ROUTE_PREFIX', 't/{tenant}'), '/');
$apiAdminPanelPrefix = $apiAdminHostedMode && $apiAdminTenantPrefix !== ''
    ? $apiAdminTenantPrefix.'/'
    : '';
$apiAdminGeneratedPanelsPath = env('API_ADMIN_GENERATED_PANELS_PATH', storage_path('api-admin/generated/panels.php'));
$apiAdminGeneratedPanels = is_string($apiAdminGeneratedPanelsPath) && is_file($apiAdminGeneratedPanelsPath)
    ? include $apiAdminGeneratedPanelsPath
    : [];
$apiAdminGeneratedPanels = is_array($apiAdminGeneratedPanels) ? $apiAdminGeneratedPanels : [];

return [
    'title' => env('MOONSHINE_TITLE', 'MoonShine V'),
    'logo' => '/vendor/moonshine/logo-small.svg',
    'logo_small' => '/vendor/moonshine/logo-small.svg',

    'favicons' => [
        'apple-touch' => '/vendor/moonshine/apple-touch-icon.png',
        '32' => '/vendor/moonshine/favicon-32x32.png',
        '16' => '/vendor/moonshine/favicon-16x16.png',
        'safari-pinned-tab' => '/vendor/moonshine/safari-pinned-tab.svg',
    ],

    // Default flags
    'use_migrations' => true,
    'use_notifications' => true,
    'use_database_notifications' => true,
    'use_routes' => false,
    'use_profile' => true,

    // Escaping defaults. Values are escaped when displayed, never on apply:
    // the payload belongs to the upstream API and must stay exactly as entered.
    'escapes' => [
        'label' => true,
        'hint' => true,
        'prefix' => true,
        'suffix' => true,
        'before_render' => true,
        'after_render' => true,
        'value' => true,
        'value_on_apply' => false,
    ],

    // Routing
    'domain' => env('MOONSHINE_DOMAIN'),
    'prefix' => env('MOONSHINE_ROUTE_PREFIX', 'admin'),
    'page_prefix' => env('MOONSHINE_PAGE_PREFIX', 'page'),
    'resource_prefix' => env('MOONSHINE_RESOURCE_PREFIX', 'resource'),
    'home_route' => 'moonshine.index',

    // Error handling
    'not_found_exception' => MoonShineNotFoundException::class,

    // Middleware
    'middleware' => [
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
        StartSession::class,
        AuthenticateSession::class,
        ShareErrorsFromSession::class,
        VerifyCsrfToken::class,
        SubstituteBindings::class,
        ChangeLocale::class,
        MultiPanelMiddleware::class,
        EnsureTenantMembership::class,
    ],

    // Storage
    'disk' => 'public',
    'disk_options' => [],
    'cache' => 'file',

    // Authentication and profile
    'auth' => [
        'enabled' => true,
        'guard' => 'moonshine',
        'model' => MoonshineUser::class,
        'middleware' => [
            Authenticate::class,
            EnsureDelegatedPanelState::class,
        ],
        'pipelines' => [],
    ],

    // Authentication and profile
    'user_fields' => [
        'username' => 'email',
        'password' => 'password',
        'name' => 'name',
        'avatar' => 'avatar',
    ],

    // Layout, palette, pages, forms
    'layout' => MoonShineLayout::class,
    'palette' => PurplePalette::class,

    'forms' => [
        'login' => DelegatedLoginForm::class,
        'filters' => FiltersForm::class,
    ],

    'pages' => [
        'dashboard' => Dashboard::class,
        'profile' => ProfilePage::class,
        'login' => LoginPage::class,
        'error' => ErrorPage::class,
    ],

    // Localizations
    'locale' => 'en',
    'locale_key' => ChangeLocale::KEY,
    'locales' => [
        // en
    ],
    // Multi-panel configuration
    'panels' => [
        ...$apiAdminGeneratedPanels,
        'admin' => [
            'prefix' => $apiAdminPanelPrefix.'admin',
            'title' => 'MoonShine V',
            'layout' => AdminHubLayout::class,
            'pages' => [
                'dashboard' => AdminHubPage::class,
                'instructor' => InstructorPage::class,
                'profile' => ProfilePage::class,
                'login' => LoginPage::class,
                'error' => ErrorPage::class,
            ],
        ],
    ],
];
