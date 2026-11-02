[← Back to README](../README.md) · [MCP Tools Reference](mcp-tools.md)

# Smoke checklist: a real service over MCP

A step-by-step check that moonshine-v is ready to serve a real external API initialized over MCP. Run it in full for every new service or after restarting the environment.

**Input:** moonshine-v is running locally or in Docker, you can open your own project in Claude Code, and you have a backend with a known `base_url` and a Bearer token.

**Output:** the admin panel shows your service's entity, CRUD (index / detail / form) works, relations render, and a clean remove↔create cycle completes.

## 0. Preparation

- [ ] `docker compose --env-file docker/.env -f docker/docker-compose.yml ps` or `php artisan serve` — moonshine-v is running.
- [ ] Open `http://127.0.0.1:8000/` in a browser → redirect to `/admin`, the login page is visible. Sign in with an administrator created through `php artisan moonshine:user`.
- [ ] No fatal errors in the logs: `tail -n 100 storage/logs/laravel.log | grep -i error`.
- [ ] Your service's API is reachable and returns valid JSON on its main endpoint. Check locally with `curl -H "Authorization: Bearer <token>" <base_url>/<entity>`.

## 1. Connect MCP to your project

- [ ] Your project root has a `.mcp.json` (copied from `v/.mcp.json.example`) containing only the block that matches how you run moonshine-v (docker exec or local php).
- [ ] Restart Claude Code. `list tools` shows tools prefixed with `moonshine-v_`; the full list is in [MCP Tools](mcp-tools.md#all-tools). Required:
  - `register_service`, `create_resource`, `update_resource`, `configure_layout`
  - `list_services`, `list_resources`
  - `remove_service`, `remove_resource`
  - `probe_endpoint`, `health_check`

If they are missing, the usual causes are: the docker container name does not match, `cwd` does not point at the moonshine-v root, or Claude Code did not restart the MCP server.

## 2. Register the service

Through Claude Code (or any MCP client):

```
register_service({
  name: "<svc>",          // unique key, used in class paths
  base_url: "<url>",
  api_key: "<bearer>",
  auth_type: "bearer",
  panel: "<svc>-admin",
  title: "<Display Title>",
  response_format: "laravel" | "django" | "flat" | "custom"
})
```

- [ ] Response: `{success: true, service, panel, files: [...]}`.
- [ ] `list_services` shows the new service with `resources_count: 0`.
- [ ] The admin sidebar has a panel switcher and the new panel can be selected (`/<svc>-admin/`).

## 3. Probe the endpoint

```
probe_endpoint({ service: "<svc>", path: "/<main-entity>" })
```

- [ ] `success: true`, `status: 200`, reasonable `timings_ms`.
- [ ] `guessed_format` matches your `response_format` (if you chose the wrong one, fix it with `remove_service` + another `register_service`).
- [ ] `guessed_mapping.primary_key` matches the real ID field.
- [ ] `sample_json.items_sample` is an array of objects with the expected fields.
- [ ] `hints` are empty or not critical. For auth errors or a 404, fix `api_key` or `base_url`.

If `guessed_format` is `flat` but your response is actually paginated in a different envelope, register the service with `response_format: "custom"` and pass `response_mapping` explicitly.

## 4. Create a resource

Minimal example (add your own fields):

```
create_resource({
  service: "<svc>",
  name: "<entity>",
  endpoint: "/<entity>",
  title: "<Entity>s",
  panel: "<svc>-admin",
  fields: [
    { column: "id", type: "id" },
    { column: "title", type: "text", methods: ["sortable", "required"] },
    { column: "body", type: "textarea" },
    { column: "status", type: "select", options: { "draft": "Draft", "published": "Published" } }
  ],
  search: ["title"]
})
```

- [ ] Response: `{success: true, file, class, pages: {index, form, detail}}`.
- [ ] `list_resources({service: "<svc>"})` shows the resource and its `endpoint` matches.
- [ ] The files are in `app/MoonShine/ApiAdmin/Resources/<Svc>/<Entity>/`.

## 5. UI — happy path

- [ ] `/<svc>-admin/` — landing page. The entity's menu item is visible.
- [ ] `/<svc>-admin/resource/<svc>-<entity>-resource/<entity>-index-page` — **Index**. Data loads, columns match `fields`. Pagination works (if the preset implies it).
- [ ] Click a row — **Detail**. All fields are present, with no empty cells where the API value was not null.
  - `RestHasOne` / `RestHasMany`, if any, render inline as vertical previews.
  - `RestBelongsToMany` shows tag labels, not IDs.
- [ ] Open the **Edit form**. The form is wrapped in a Box and fields hold the API values.
- [ ] Change a value and save — the upstream receives a PUT and the record updates in the API.
- [ ] Open the **Create form**. Fill it in and save — the upstream receives a POST and the record is created.

## 6. Update the schema

```
update_resource({
  service: "<svc>",
  name: "<entity>",
  fields: [ ...updated list... ],
  search: [...]
})
```

- [ ] `success: true`. `endpoint` is taken from the existing resource unless overridden.
- [ ] `api-admin:reload` ran and the new field is visible in the UI.
- [ ] A wrong name returns `{success:false, code:"RESOURCE_NOT_FOUND", hint:"..."}` and nothing breaks.

Also check `health_check({})` — every registered service reports `status: "ok"` with reasonable latency.

## 6a. Hosted smoke

For hosted mode, run a separate minimal cycle with two tenants.

- [ ] `API_ADMIN_HOSTED_MODE=hosted`, `API_ADMIN_HOSTED_MCP_ENABLED=true`, the route prefix stays `t/{tenant}` or another one is documented.
- [ ] `php artisan api-admin:tenant acme` created an active tenant and `php artisan api-admin:mcp-token acme --scope=tools:read` printed a token for the initial check.
- [ ] `php artisan api-admin:mcp-token acme --list` shows the token without revealing it; after `--revoke=<id>` the token gets 401.
- [ ] The developer project's `.mcp.json` points only at a hosted URL like `https://<host>/t/acme/api-admin/mcp` plus the bearer token. No local moonshine-v clone is needed.
- [ ] `initialize` over hosted MCP returns `serverInfo.name: "moonshine-v-hosted"`.
- [ ] `list_services` returns only the services of tenant `acme`.
- [ ] `health_check({})` returns only the services of tenant `acme`, `diagnostics.mode: "hosted"`, and `diagnostics.warnings` is empty or contains clear deployment warnings.
- [ ] Warnings about `CACHE_STORE=array|file`, `SESSION_DRIVER=array|file|cookie`, `QUEUE_CONNECTION=sync`, `BROADCAST_CONNECTION=log|null` are resolved before production.
- [ ] Using the `acme` token on `/t/<other>/api-admin/mcp` returns unauthorized.
- [ ] Requesting another tenant's service or resource through a tool call returns a tenant denial and makes no upstream HTTP request.
- [ ] The hosted support route `/t/acme/api-admin/async-search` is available and panel URLs use the tenant prefix.

## 7. Cleanup cycle

```
remove_resource({ service: "<svc>", name: "<entity>", confirm: true })
```

- [ ] `removed_paths` contains the resource directory.
- [ ] `list_resources({service: "<svc>"})` is empty.
- [ ] The entity disappeared from the UI menu.

```
remove_service({ name: "<svc>", confirm: true })
```

- [ ] `removed_paths`: Configs, Layouts, Resources/{Svc}.
- [ ] `list_services` no longer contains the service.
- [ ] Panel `<svc>-admin` disappeared from `storage/api-admin/generated/panels.php`.
- [ ] `/admin/` still works.

## 8. Repeat the cycle

- [ ] Run steps 2–7 once more: the system must come up from a clean state without manual edits.

## Known limitations

- `RestBelongsToMany` on an index page makes one association request per row unless a bulk endpoint is configured. This is noticeable on pages with more than 100 rows.
- `update_resource` is a full overwrite (you pass the whole `fields` list). Use `add_field`, `remove_field`, `rename_field` and `reorder_fields` for partial changes.

## If something does not work

- **500 when opening a page** — check `storage/logs/laravel.log`; the mapping most likely does not fit. Try `probe_endpoint` on the same endpoint and compare `guessed_format` with the actual `response_format`.
- **Empty index** — wrong response mapping: `data_key` is not in your payload. Example: the API returns `{items: [...]}`, which needs `response_format: "custom", response_mapping: {data_key: "items"}`.
- **Auth failed (401 / 403)** — `probe_endpoint` returns a hint. If it does not, check `auth_type` (`bearer`/`header`/`query`) and that the token is valid.
- **Empty detail** — the mapping may return a `primary_key` that differs from your API. Go back to `probe_endpoint` and check its primary_key.
- **MCP tools not visible in Claude Code** — check `.mcp.json` and restart Claude Code completely (not just the window). `claude mcp list` can help with diagnostics.
