# ServiceConfig Format

Each microservice connected to the admin panel has a ServiceConfig that defines how to communicate with its API.

## Configuration File

```php
// config/api-admin.php
return [
    'services' => [
        'blog' => [
            'name' => 'blog',
            'base_url' => env('BLOG_API_URL', 'https://api.blog.example.com/v1'),
            'api_key' => env('BLOG_API_KEY'),
            'panel' => 'blog-admin',
            'auth_type' => 'bearer',
            'auth_header' => 'Authorization',
            'realtime' => [
                'enabled' => true,
                'webhook_secret' => env('BLOG_WEBHOOK_SECRET', ''),
                'webhook_signature_header' => 'X-ApiAdmin-Signature',
                'webhook_signature_algo' => 'sha256',
                'webhook_event_key' => 'event',
                'webhook_resource_key' => 'resource',
                'webhook_action_key' => 'action',
                'webhook_id_key' => 'id',
                'allowed_events' => ['created', 'updated', 'deleted', 'upserted'],
                'refresh_debounce_ms' => 750,
                'refresh_strategy' => 'auto',
                'sensitive_payload_keys' => ['password', 'token', 'secret', 'api_key', 'authorization'],
            ],
            'response_mapping' => [
                'data_key' => 'data',
                'total_key' => 'total',
                'per_page_key' => 'per_page',
                'current_page_key' => 'current_page',
                'last_page_key' => 'last_page',
                'primary_key' => 'id',
            ],
        ],
    ],
];
```

## Realtime Webhooks

Realtime settings are service-level. Use Laravel Reverb as the recommended
WebSocket backend by configuring Laravel broadcasting/Echo normally; this
package stores the upstream webhook contract and broadcasts through Laravel's
broadcasting abstractions.

Incoming upstream mutations are accepted at:

```text
POST /api-admin/webhooks/{service}
```

`webhook_secret` is sensitive. `register_service` writes it to
`<SERVICE>_WEBHOOK_SECRET` in `.env` and keeps `config/api-admin.php` on an
empty stub so secrets are not committed. Omitting it on re-register preserves
the existing value. Hosted enabled realtime requires a nonempty secret, stored
per tenant in `TenantSecretStore`; disable realtime before clearing it. Hosted
refresh channels are private and use `/broadcasting/auth` with the panel user and
tenant membership. Configure the Laravel broadcaster and Echo separately.

`refresh_strategy` controls what happens in the browser after a matching
mutation: `auto` reloads list/detail pages and shows a notice on forms,
`notice` always shows the notice, and `disabled` suppresses browser refresh.

## Authentication Types

### Bearer Token (default)

```php
'auth_type' => 'bearer',
// Sends: Authorization: Bearer {api_key}
```

### Custom Header

```php
'auth_type' => 'header',
'auth_header' => 'X-Api-Key',
// Sends: X-Api-Key: {api_key}
```

### Query Parameter

```php
'auth_type' => 'query',
// Sends: ?api_key={api_key}
```

### Delegated Admin Login

For service-backed panels, set `admin_login` to `true` with `auth_type`
`oauth2_password` or `session`. The `/\<panel>/login` form stays the normal
MoonShine username/password form, but credentials are validated by the upstream
service. `moonshine-v` stores only the resulting token or cookie/CSRF state in
the Laravel session; it does not store the user's upstream password.

```php
'auth_type' => 'oauth2_password',
'admin_login' => true,
'token_url' => 'https://idp.example.com/oauth/token',
'oauth_client_id' => 'admin-panel',
'oauth_client_secret' => '...',
```

```php
'auth_type' => 'session',
'admin_login' => true,
'session_login_endpoint' => '/login',
'csrf_bootstrap_path' => '/sanctum/csrf-cookie',
'csrf_token_source' => 'cookie',
```

### Per-Panel SSO

For organization-wide browser SSO, use `admin_auth_mode` separately from
`auth_type`. `auth_type` controls API calls after login; `admin_auth_mode`
controls how the human signs in to the panel.

```php
'auth_type' => 'none',
'oauth_client_id' => env('CRM_OAUTH_CLIENT_ID', ''),
'oauth_client_secret' => env('CRM_OAUTH_CLIENT_SECRET', ''),
'admin_auth_mode' => 'oauth2_authorization_code',
'admin_auth' => [
    'authorize_url' => 'https://idp.example.com/oauth/authorize',
    'token_url' => 'https://idp.example.com/oauth/token',
    'scopes' => ['openid', 'profile', 'email'],
    'redirect_uri' => 'https://moonshine.example.com/crm-admin/oauth/callback',
],
```

This uses OAuth2 Authorization Code + PKCE and syncs the upstream identity into
`moonshine_users`. SAML-only SSO and full OIDC discovery/JWKS/ID-token
validation are not separate modes yet. If a user needs those or another SSO
protocol, ask them to open an issue in the project repository so we can evaluate adding it.

## Response Mapping

Different APIs return paginated data in different formats. The response mapping configures how to extract data from each API's response.

### Laravel API (default)

```json
{
  "data": [...],
  "total": 100,
  "per_page": 25,
  "current_page": 1,
  "last_page": 4
}
```

```php
'response_mapping' => [
    'data_key' => 'data',
    'total_key' => 'total',
    'per_page_key' => 'per_page',
]
```

### Django REST Framework

```json
{
  "results": [...],
  "count": 100,
  "page_size": 20
}
```

```php
'response_mapping' => [
    'data_key' => 'results',
    'total_key' => 'count',
    'per_page_key' => 'page_size',
]
```

### Custom Format

```json
{
  "items": [...],
  "pagination": {"total_items": 100, "items_per_page": 50}
}
```

```php
'response_mapping' => [
    'data_key' => 'items',
    'total_key' => 'pagination.total_items',
    'per_page_key' => 'pagination.items_per_page',
]
```

## Accessing ServiceConfig

```php
use App\ApiAdmin\Providers\ApiAdminServiceProvider;

$config = ApiAdminServiceProvider::resolveService('blog');

$config->name;        // 'blog'
$config->baseUrl;     // 'https://api.blog.example.com/v1'
$config->authHeaders(); // ['Authorization' => 'Bearer ...']
```
