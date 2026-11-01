@include('api-admin::instructor.page', [
    'startUrl' => route('api-admin.instructor.start', \App\ApiAdmin\Tenancy\TenantRoutes::routeParams()),
    'confirmUrl' => route('api-admin.instructor.confirm', \App\ApiAdmin\Tenancy\TenantRoutes::routeParams()),
    'resetUrl' => route('api-admin.instructor.reset', \App\ApiAdmin\Tenancy\TenantRoutes::routeParams()),
])
