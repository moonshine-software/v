# Example APIs For MCP E2E

Four test APIs for exercising MoonShine V:

- `examples/blog-api` (`http://127.0.0.1:9101`) — relation types.
- `examples/shop-api` (`http://127.0.0.1:9102`) — Django-style envelope.
- `examples/oauth-idp-api` (`http://127.0.0.1:9201`) — OAuth 2.0 IdP with three grants for testing `auth_type=oauth2_cc|oauth2_password|oauth2_refresh_token`.
- `examples/session-api` (`http://127.0.0.1:9202`) — Sanctum-style cookie+CSRF for testing `auth_type=session`.

`blog-api` covers the relation types:

- `belongs_to` (`/v1/categories`)
- `has_many` (`/v1/posts/{id}/comments`)
- `has_one` (`/v1/posts/{id}/author`)
- `belongs_to_many` (`/v1/tags` + `/v1/posts/{id}/tags`)

## Auth-flow demo APIs

### `oauth-idp-api` (port 9201)

Hard-coded credentials:

| param          | value                  |
|----------------|------------------------|
| client_id      | `demo-client`          |
| client_secret  | `demo-secret`          |
| username       | `admin`                |
| password       | `123456`               |
| scope          | `read write` (optional) |

Endpoints:

- `POST /oauth/token` — `grant_type` ∈ {`client_credentials`, `password`, `refresh_token`}. The refresh token rotates on every exchange.
- `GET /me` — protected, requires `Authorization: Bearer <access_token>`. Returns 401 + `WWW-Authenticate: Bearer` for an invalid token.
- `GET /tasks` — paginated list (Laravel envelope) protected by Bearer.
- `POST /__demo/reset` — wipes issued tokens (for repeated runs).

`expires_in` is 60 seconds so the 401-retry middleware can be seen working live.

Registration:

```
register_service(
  name: "oauth-cc",
  base_url: "http://127.0.0.1:9201",
  auth_type: "oauth2_cc",
  token_url: "http://127.0.0.1:9201/oauth/token",
  oauth_client_id: "demo-client",
  oauth_client_secret: "demo-secret",
  oauth_scope: "read write",
  health_path: "/health",
  response_format: "laravel"
)
```

```
register_service(
  name: "oauth-pwd",
  base_url: "http://127.0.0.1:9201",
  auth_type: "oauth2_password",
  token_url: "http://127.0.0.1:9201/oauth/token",
  oauth_client_id: "demo-client",
  oauth_client_secret: "demo-secret",
  admin_login: true,
  health_path: "/health",
  response_format: "laravel"
)
```

### `session-api` (port 9202)

Hard-coded credentials:

| param    | value                |
|----------|----------------------|
| email    | `admin`              |
| password | `1234567`            |

Endpoints:

- `GET /sanctum/csrf-cookie` — bootstrap, sets the `XSRF-TOKEN` + `sanctum_session` cookies.
- `POST /login` — requires the `X-XSRF-TOKEN` header + cookie. Body is JSON `{email, password}`.
- `GET /me` / `GET /posts` — protected, need the cookie + `X-XSRF-TOKEN`. CSRF mismatch → 419, no session → 401.
- `GET /posts/{id}`, `POST /posts`, `PUT/PATCH /posts/{id}`, `DELETE /posts/{id}` — protected post CRUD with the same cookie and CSRF checks. Posts persist in `storage/posts.sqlite` across logins; `title` is required and `status` is `draft` or `published`.
- `POST /__demo/expire-csrf` — forces a 419 on the next request (to test re-login retry).
- `POST /__demo/destroy-session` — destroys the session (401).

Registration:

```
register_service(
  name: "spa",
  base_url: "http://127.0.0.1:9202",
  auth_type: "session",
  session_login_endpoint: "/login",
  admin_login: true,
  csrf_bootstrap_path: "/sanctum/csrf-cookie",
  csrf_token_source: "cookie",
  csrf_token_key: "XSRF-TOKEN",
  csrf_header_name: "X-XSRF-TOKEN",
  health_path: "/health",
  response_format: "laravel"
)
```

## Running

```bash
bash examples/start-apis.sh
```

Check:

```bash
curl -s http://127.0.0.1:9101/health
curl -s http://127.0.0.1:9102/health
```

Stop:

```bash
bash examples/stop-apis.sh
```

## MCP Smoke Scenario

With the APIs running:

```bash
php examples/run-mcp-scenario.php
```

The command:

1. Registers 2 services (`blog`, `shop`) through the MCP tool `register_service`.
2. Generates one resource each through `create_resource`.
3. Enables relation fields for `blog` (`belongs_to`, `has_many`, `has_one`, `belongs_to_many`).

## Where to look at the result

- `storage/api-admin/generated/services.php` and `panels.php` — the 2 services
- `app/MoonShine/ApiAdmin/Resources/Blog/Post/PostResource.php`
- `app/MoonShine/ApiAdmin/Resources/Shop/Product/ProductResource.php`
- `app/MoonShine/ApiAdmin/Configs/BlogConfig.php`
- `app/MoonShine/ApiAdmin/Configs/ShopConfig.php`

## One-command QA smoke

```bash
bash examples/qa-smoke.sh
```

The script:

1. Starts both APIs.
2. Checks `/health`.
3. Runs MCP generation (`register_service` + `create_resource`).
4. Checks `storage/api-admin/generated/services.php`.
5. Checks the relation fields in `PostResource`.
6. Checks that `PostResource` and `ProductResource` are registered in `moonshine:resources`.
