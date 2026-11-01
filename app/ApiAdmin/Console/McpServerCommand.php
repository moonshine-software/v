<?php

declare(strict_types=1);

namespace App\ApiAdmin\Console;

use App\ApiAdmin\MCP\McpServer;
use App\ApiAdmin\MCP\Tools\ListComponentsTool;
use App\ApiAdmin\MCP\Tools\ListFieldMethodsTool;
use App\ApiAdmin\MCP\Tools\ListFieldsTool;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Override;
use stdClass;
use Throwable;

class McpServerCommand extends Command
{
    #[Override]
    protected $signature = 'api-admin:mcp-server {--debug : Write protocol debug info to STDERR}';

    #[Override]
    protected $description = 'Run MCP server over stdio (JSON-RPC) for register_service/create_resource/configure_layout tools';

    public function handle(): int
    {
        // Any byte on STDOUT that isn't a JSON-RPC frame corrupts the stream
        // and makes Claude Code flip the server to "failed". Force PHP errors
        // to STDERR and strip any output buffers inherited from Laravel's
        // bootstrap so a stray warning/deprecation can't poison the channel.
        @ini_set('display_errors', 'stderr');
        @ini_set('log_errors', '1');
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        // SIGPIPE (broken pipe on STDOUT when the client closes its end)
        // terminates the PHP process by default without any log entry. Ignore
        // it here so fwrite() instead returns false and we can exit cleanly.
        if (function_exists('pcntl_signal') && defined('SIGPIPE')) {
            pcntl_signal(SIGPIPE, SIG_IGN);
        }

        // A fatal error during tool execution (or right after, while PHP
        // shuts down) currently vanishes into the client-discarded STDERR.
        // Promote it to laravel.log so we can tell WHY the server died when
        // Claude Code reports "failed".
        register_shutdown_function(static function (): void {
            $err = error_get_last();
            if ($err === null) {
                Log::info('McpServer: shutdown (clean)');

                return;
            }

            $fatalMask = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;
            if (($err['type'] & $fatalMask) === 0) {
                Log::info('McpServer: shutdown (clean)', ['last_nonfatal' => $err]);

                return;
            }

            Log::error('McpServer: shutdown (fatal)', [
                'type' => $err['type'],
                'message' => $err['message'],
                'file' => $err['file'],
                'line' => $err['line'],
            ]);
        });

        $server = new McpServer;
        $debug = (bool) $this->option('debug');

        // PHP's default_socket_timeout (60s) applies to blocking stream reads
        // including STDIN pipes. When the MCP client sits idle for 60s,
        // fgets(STDIN) returns false even though the stream is not closed,
        // the loop exits, PHP terminates cleanly — and Claude Code sees the
        // server "vanish" and flags it as failed. Disable the timeout so we
        // only exit on real EOF, and use stream_get_meta_data as a belt-and
        // -suspenders check in case the platform ignores the setting.
        stream_set_blocking(STDIN, true);
        @stream_set_timeout(STDIN, 365 * 86400);

        while (! feof(STDIN)) {
            $line = fgets(STDIN);

            if ($line === false) {
                $meta = @stream_get_meta_data(STDIN);
                if (! empty($meta['timed_out'])) {
                    // Spurious read timeout — keep waiting for the next frame.
                    continue;
                }

                Log::info('McpServer: stdin read returned false — exiting loop', [
                    'meta' => $meta,
                ]);
                break;
            }

            $line = trim($line);
            if ($line === '') {
                continue;
            }

            try {
                if (! $this->handleFrame($server, $line, $debug)) {
                    break;
                }
            } catch (Throwable $e) {
                // Any exception that escapes handleFrame() would otherwise
                // terminate the PHP process — we never want a single bad
                // request to kill the whole MCP connection.
                Log::error('McpServer: uncaught exception in handleFrame', [
                    'error' => $e->getMessage(),
                    'class' => $e::class,
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        Log::info('McpServer: event loop exited', [
            'feof' => feof(STDIN),
        ]);

        return self::SUCCESS;
    }

    /**
     * Handle a single JSON-RPC frame. Returns false to signal the loop to
     * exit (JSON-RPC `exit` method), true to continue reading.
     */
    private function handleFrame(McpServer $server, string $line, bool $debug): bool
    {
        $request = json_decode($line, true);

        if (! is_array($request)) {
            $this->respondError(null, -32700, 'Parse error');

            return true;
        }

        // JSON-RPC 2.0: a request is a notification iff the `id` member is
        // absent entirely (null is still a request id). Notifications MUST
        // NOT receive a response — replying breaks strict clients.
        $isNotification = ! array_key_exists('id', $request);
        $id = $request['id'] ?? null;
        $method = $request['method'] ?? null;
        $params = $request['params'] ?? [];

        // Log every inbound method so we can see what Claude Code sends
        // (including ping frames) — makes it trivial to diagnose "server
        // went to failed state" by inspecting laravel.log timing.
        Log::info('McpServer: request', [
            'method' => is_string($method) ? $method : gettype($method),
            'id' => $id,
            'notification' => $isNotification,
        ]);

        if ($debug) {
            fwrite(STDERR, '[mcp] method='.(is_string($method) ? $method : gettype($method))."\n");
        }

        if (! is_string($method)) {
            if (! $isNotification) {
                $this->respondError($id, -32600, 'Invalid Request');
            }

            return true;
        }

        // Known notification frames — swallow silently. Anything else
        // prefixed with `notifications/` (e.g. notifications/cancelled,
        // notifications/progress) gets the same treatment below.
        if ($isNotification || str_starts_with($method, 'notifications/')) {
            return true;
        }

        // MCP spec: either peer MAY periodically send `ping` to check liveness;
        // the receiver MUST reply with an empty result. Without this handler,
        // we'd return a "Method not found" error on every ping and the client
        // would mark the server as failed after a few unanswered pings.
        if ($method === 'ping') {
            $this->respond($id, new stdClass);

            return true;
        }

        if ($method === 'initialize') {
            // Echo back the client's protocolVersion if we support it,
            // otherwise fall back to our highest supported version. Some
            // MCP clients disconnect when the server responds with a
            // different major version than what the client advertised.
            $supported = ['2025-06-18', '2025-03-26', '2024-11-05'];
            $clientVersion = is_array($params) ? ($params['protocolVersion'] ?? null) : null;
            $protocolVersion = (is_string($clientVersion) && in_array($clientVersion, $supported, true))
                ? $clientVersion
                : $supported[0];

            $this->respond($id, [
                'protocolVersion' => $protocolVersion,
                // Empty object form (not `{"listChanged": false}`) — some
                // MCP clients ignore the tools capability when listChanged
                // is explicitly false and then never call tools/list,
                // leaving tools invisible in the client UI.
                'capabilities' => [
                    'tools' => new stdClass,
                    // `resources` surface exposes the agent cheatsheet (LLMS.txt
                    // in repo root) so the MCP client can pin it without
                    // re-reading it on every tools/list call. See resources/list
                    // + resources/read below.
                    'resources' => new stdClass,
                ],
                'serverInfo' => [
                    'name' => 'moonshine-api-admin',
                    'version' => '0.1.0',
                ],
                // Standard MCP `instructions` field (2024-11-05+). The
                // client surfaces this to the agent as system context so
                // the agent doesn't have to discover terminology from
                // tool-schema descriptions alone.
                'instructions' => $this->agentInstructions(),
            ]);

            return true;
        }

        if ($method === 'tools/list') {
            $schemas = $server->getToolSchemas();
            $this->respond($id, [
                'tools' => array_values($schemas),
            ]);

            return true;
        }

        if ($method === 'resources/list') {
            $this->respond($id, [
                'resources' => $this->resourceCatalog(),
            ]);

            return true;
        }

        if ($method === 'resources/read') {
            $uri = is_array($params) ? ($params['uri'] ?? null) : null;
            if (! is_string($uri)) {
                $this->respondError($id, -32602, 'Invalid params: uri is required');

                return true;
            }

            $resource = $this->readResource($uri);
            if ($resource === null) {
                $this->respondError($id, -32602, "Resource not found: {$uri}");

                return true;
            }

            $this->respond($id, [
                'contents' => [$resource],
            ]);

            return true;
        }

        if ($method === 'tools/call') {
            $name = is_array($params) ? ($params['name'] ?? null) : null;
            $arguments = is_array($params) ? ($params['arguments'] ?? []) : [];

            if (! is_string($name) || ! is_array($arguments)) {
                $this->respondError($id, -32602, 'Invalid params');

                return true;
            }

            /** @var array<string, mixed> $toolArgs */
            $toolArgs = $arguments;
            $result = $server->executeTool($name, $toolArgs);
            $success = (bool) ($result['success'] ?? false);

            $this->respond($id, [
                'content' => [[
                    'type' => 'text',
                    'text' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]],
                'isError' => ! $success,
            ]);

            return true;
        }

        if ($method === 'shutdown') {
            $this->respond($id, null);

            return true;
        }

        if ($method === 'exit') {
            return false;
        }

        $this->respondError($id, -32601, "Method not found: {$method}");

        return true;
    }

    /**
     * MCP resource catalog. One entry per agent-readable cheatsheet.
     * Agents fetch via `resources/read` once per session instead of
     * re-parsing tool-schema descriptions on every iteration.
     *
     * @return list<array{uri: string, name: string, description: string, mimeType: string}>
     */
    private function resourceCatalog(): array
    {
        $catalog = [];

        if (is_file(base_path('LLMS.txt'))) {
            $catalog[] = [
                'uri' => 'moonshine-api-admin://llms',
                'name' => 'LLMS.txt — agent cheatsheet',
                'description' => 'Full agent guide: guardrails, typical flow, field types, auth schemes, response-mapping presets, layout presets, relations. Read once per session.',
                'mimeType' => 'text/markdown',
            ];
        }

        // Live introspection snapshots — same output as the paired MCP
        // tool, served as a JSON resource so an agent can
        // `resources/read` once and cache the catalog for the session.
        $catalog[] = [
            'uri' => 'moonshine-api-admin://fields',
            'name' => 'Field catalog',
            'description' => 'Flat list of every usable `fields[].type` (list_fields output).',
            'mimeType' => 'application/json',
        ];
        $catalog[] = [
            'uri' => 'moonshine-api-admin://components',
            'name' => 'Component catalog',
            'description' => 'Flat list of every layout.form.tree-addressable component (list_components output).',
            'mimeType' => 'application/json',
        ];
        $catalog[] = [
            'uri' => 'moonshine-api-admin://field-methods',
            'name' => 'Field methods union',
            'description' => 'Union of chainable methods across every field type (list_field_methods output).',
            'mimeType' => 'application/json',
        ];

        return $catalog;
    }

    /**
     * @return array{uri: string, mimeType: string, text: string}|null
     */
    private function readResource(string $uri): ?array
    {
        if ($uri === 'moonshine-api-admin://llms') {
            $path = base_path('LLMS.txt');
            if (! is_file($path)) {
                return null;
            }
            $text = @file_get_contents($path);
            if ($text === false) {
                return null;
            }

            return [
                'uri' => $uri,
                'mimeType' => 'text/markdown',
                'text' => $text,
            ];
        }

        // Introspection snapshots — delegate to the paired tool's
        // execute() and JSON-encode its result. Keeps the resource view
        // in lockstep with the tool contract at zero ongoing cost.
        $introspection = [
            'moonshine-api-admin://fields' => ListFieldsTool::class,
            'moonshine-api-admin://components' => ListComponentsTool::class,
            'moonshine-api-admin://field-methods' => ListFieldMethodsTool::class,
        ];
        if (isset($introspection[$uri])) {
            $toolClass = $introspection[$uri];
            $tool = new $toolClass;
            $payload = $tool->execute([]);
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return [
                'uri' => $uri,
                'mimeType' => 'application/json',
                'text' => $json === false ? '{}' : $json,
            ];
        }

        return null;
    }

    /**
     * Ambient guidance the agent receives right after `initialize`. Kept
     * short: the tool-schema descriptions carry per-argument detail, this
     * block only covers the cross-cutting conventions that trip agents up.
     */
    private function agentInstructions(): string
    {
        return <<<'TXT'
moonshine-api-admin MCP — admin-panel generator for REST microservices and local Eloquent models.
The agent talks to the generator through MCP. It MUST NOT touch the moonshine-v
working copy directly (no shell commands, no filesystem reads, no package
rebuilds) — every tool handles autoload + cache refresh internally.
Hosted mode uses HTTPS Streamable HTTP MCP routes protected by tenant-bound bearer
tokens. The local `.mcp.json` should point at the hosted URL and carry only
that token; it must not include upstream API secrets. Token scopes may allow
all tools (`*` / `tools:*`) or tool families (`tools:read`,
`tools:generate`, `tools:data:read`, `tools:data:write`,
`tools:destructive`).
Every tool dispatch also checks the active tenant context. Cross-tenant
service/resource access is denied even when the token has the requested
tool-family scope, and generated resources/configs/layouts/spec sidecars
are resolved under tenant-scoped paths in hosted mode.
Hosted register_service stores service secrets (api_key,
oauth_client_secret, OAuth/session passwords, refresh tokens, webhook
secrets) in tenant-scoped encrypted storage; it does not write hosted
secrets to .env or bake literal secret fallbacks into generated PHP
config. Local/self-hosted mode keeps the existing .env fallback behavior.
Hosted generation policy: create_field/update_field/delete_field,
create_component/update_component/delete_component, initialize_local_service,
and create_model_resource require trusted local mode; hosted calls fail with
MCP_HOSTED_TOOL_DISABLED even for wildcard scopes. Service, panel, and generated
class names must start with a letter and contain only letters, digits,
underscores or hyphens (maximum 80 characters). Hosted outbound requests reject
private/link-local destinations by default; all modes enforce the configured
origin, TLS verification, and no redirects. Hosted service secrets are encrypted
in the database and survive cache clearing. Browser tenant access requires an
explicit operator-granted membership (api-admin:tenant-member).
Generation operations are serialized through a shared filesystem lock. A failed
operation restores modified files and configuration; Instructor confirmation
rolls back the whole service/resource operation on failure. This does not roll
back upstream data CRUD calls. Deploy writers on the same persistent filesystem.
Hosted fields use built-in types only. Allowed chain methods are: required,
disabled, readonly, nullable, multiple, vertical, searchable, tabMode, sortable,
reactive, placeholder, default, min, max, step, minLength, maxLength, pattern,
mask, format, inputFormat, onValue, offValue, options, raw, creatable, editable,
removable, fullCrud. list_field_methods returns the permitted catalog for the
current mode; arbitrary callbacks, storage methods and custom views are blocked.
Hosted native file/image fields are disabled, including existing resource forms,
because their storage is shared. Use upstream media URLs instead. Generated
validation rules are limited to the scalar/list/date rules advertised in
create_resource.rules; database, custom, file, regex and network validation
rules are rejected (email accepts only rfc/filter/filter_unicode modes).
Service names and panel identifiers must be unique installation-wide. Ownership
is rechecked from persistent state while the generation lock is held.
Runtime: MoonShine 5.x-dev, exact commit pinned in composer.lock.
REST index pages generated by create_resource/update_resource extend
App\ApiAdmin\Pages\RestIndexPage (aliased as IndexPage). Filters belong
to the page; RestIndexPage attaches relation filters to the parent resource.
Hosted health_check only probes tenant-visible services and returns
diagnostics for cache, session, queue, and broadcast backends. Warnings
about local/development backends (array, file, cookie, sync, log, null)
are deployment issues.
Operators should watch INFO logs for MCP sessions, provisioning, and
health-check lifecycle events; WARNING logs for denied tenant/scope access
and degraded dependencies; and ERROR logs for persistence, protocol, and
health-check failures. Token values, upstream credentials, delegated
session state, webhook secrets, and tenant-sensitive payloads must never
be logged.

Canonical REST microservice flow:
  1) register_service(name, base_url, auth_type, response_format, response_mapping?, realtime?)
  2) probe_endpoint(service, path, method?: GET|POST, body?: object,
     detect_filter_for?: {column, value})
     → detected_columns, guessed_filter_format, guessed_mapping.
     Use method=POST + body for POST-only list/search endpoints; do not
     infer mapping from a GET 405 error body.
     Use the output to finalize response_mapping before create_resource.
  3) create_resource(service, name, endpoint, fields[], list_request?, ...).

Canonical local monolith flow:
  1) initialize_local_service(name, panel?, title?, auth_type?: local|none)
     Do not pass base_url, api_key, response_mapping, OAuth/session auth,
     admin_auth, health_path, or realtime. Those are REST-only settings.
  2) create_model_resource(service, name, model, fields[], index_fields?,
     form_fields?, detail_fields?, rules?, search?, actions?, icon?, group?)
     Generates native MoonShine ModelResource pages for an Eloquent model.
     Local field types are scalar/UI types only: id, text, textarea,
     number, email, password, date, datetime, checkbox, switcher, select,
     image, file, json, color, slug, url.

REST and local tools are intentionally separate. create_model_resource
refuses REST services with LOCAL_SERVICE_REQUIRED. Missing or non-Eloquent
models return MODEL_NOT_FOUND. Local services do not use probe_endpoint,
create_resource, response_mapping, fetch_resource_items, analytics, or
resource-item CRUD; those data tools go through AbstractRestResource and are
REST-only. list_services includes each service kind and counts local resources;
list_resources remains REST-resource oriented.

When there is exactly one visible local service/panel, the generated local
ModelResource hides the panel/service switcher. Multiple local panels, or any
REST service, keep the switcher visible.

Known gotchas (repeat offenders — scan before debugging):
  • "Parse error: unexpected token" on autoload = stale MCP process.
    Kill `php artisan api-admin:mcp-server` so the client respawns
    with fresh code. The generator fails fast with "stub '...' still
    contains unreplaced placeholder(s)" when that happens.
  • Schema changes don't reach the agent without reconnect. If a
    param "doesn't exist" but this guide mentions it, reconnect MCP.
  • update_resource is a FULL replace. Call get_resource(service,
    name) first when you only want to edit a slice — merge into the
    returned spec, then pass the merged spec. Resources predating the
    sidecar return SPEC_MISSING; reconstruct from the generated
    *Page.php files or reseed with one full update_resource call.
  • Prefer the partial-delta tools for single-field mutations:
      - add_field(service, resource, field, position?, pages?) —
        position via before/after/index (mutually exclusive); pages
        subset of [index, form, detail] emits hide_on of complement.
      - remove_field(service, resource, column, confirm:true, force?)
        — destructive. Refuses with FIELD_REFERENCED listing every
        location (search/rules/filters/layout/mutations/depends_on/
        endpoint_query) unless force:true (which strips them).
      - rename_field(service, resource, from, to, update_mutations?)
        — automatic cross-reference rewrite across all of the above
        plus sibling depends_on and endpoint_query placeholder VALUES
        (key sides are upstream-bound and stay). Returns per-location
        references_updated counts.
      - reorder_fields(service, resource, order, strict?) — order is
        partial by default (unlisted retain relative order, append).
        Layout tree is NOT touched.
    All four require an existing spec sidecar; if SPEC_MISSING, run
    update_resource once with the full current spec to seed it.
  • Per-page field arrays. index_fields[] / form_fields[] /
    detail_fields[] each REPLACE (not merge with) canonical fields[]
    on that page. Use when one page needs a genuinely different
    field set (mass-edit form, summary index). Empty override is
    treated as absent. Delta tools keep all 4 arrays consistent so a
    new column does not silently disappear on the override page.
  • register_service PRESERVES api_key when omitted. Re-register to
    tweak response_mapping / filter_param_name without restating the
    token — the stored secret survives. Explicit empty string clears
    (for auth_type="none").
    The same preserve/clear contract applies to other secrets in local and
    hosted modes; required credentials still undergo validation.
  • Embedded Laravel relations (GET /x/1 → {resource:{data:{...,
    client:{id,name}}}}) need detail_data_key: "resource.data" +
    fields[].column = embed key name (client, not client_id).
    RestBelongsTo auto-unwraps. If list endpoint omits the embed,
    hide_on: ["index"] for that column.
  • Cross-service relation fields use target_service. Keep the parent
    resource in `service`, set fields[].target_service (or filter
    target_service) to the remote lookup/service owner, and set endpoint
    relative to that target service. Omit target_service for same-service
    relations. For cross-service has_one/has_many the generator does not
    create an in-service nested relation resource; it emits field-level
    targetService()/endpoint() instead.
    Debug with LOG_LEVEL=debug for source/target service, endpoint, and
    query keys. Warnings cover missing target services or request service
    metadata that disagrees with the field definition; upstream failures
    are errors with redacted context. Use LOG_LEVEL=info or higher in
    production to reduce relation traces.
  • Cascade placeholders in endpoint_query resolve at render time.
    Use depends_on plus a reactive driver field in CREATE mode so
    MoonShine refreshes dependent relation options after sibling changes.

Terminology (use these exact keys in tool arguments):

  • fields[].type — one of: id, text, textarea, number, email, password,
    date, datetime, checkbox, switcher, select, image, file, json, color,
    slug, url, belongs_to, has_many, has_one, belongs_to_many.
    NOTE: `password` emits MoonShine\UI\Fields\Password with
    `->raw()` auto-applied — sends the RAW value to the upstream
    API (no Hash::make). Upstream is expected to hash; double-hashing
    would lock users out. Do not add `raw` to `methods` manually; the
    generator always emits it for `type: password`.

  • fields[].methods — chainable calls emitted on the field. Two shapes:
      (1) flat list — `["sortable", "required", "nullable"]` applied to
          every page/mode;
      (2) per-mode map — `{"create": ["required"], "update": []}` applies
          different chains on the FormPage for create vs edit. Classic
          case: `password.methods = {"create": ["required"], "update": []}`
          so the UI asterisk appears only on the Create page; validation
          is still enforced via `rules`. Generator emits the native
          MoonShine conditional form `->required($this->getItem() === null)`
          when the method accepts Closure|bool (detected via Reflection —
          required/disabled/readonly/nullable/multiple/vertical/searchable
          /tabMode), otherwise falls back to `->when($cond, fn ($f) =>
          $f->method(args))`. Index/Detail pages get the union as a flat
          chain (no create-vs-edit mode on those pages).
    All method names are validated via reflection against the generated
    MoonShine\UI\Fields\* class. Unknown names are dropped.
    IMPORTANT: DO NOT use hideOnForm / hideOnCreate / hideOnUpdate /
    hideOnIndex — those are MoonShine v2/v3 and do not exist in v4.

  • fields[].hide_on — ["index" | "form" | "detail"] — hide this field from
    specific pages. This is the v4-native way to hide fields (replaces the
    legacy hideOn* methods).

  • fields[].options — static {"key": "Label"} map for select fields.

  • fields[].endpoint — API endpoint for relation fields.

  • fields[].target_service — target service for cross-service relation
    fields (belongs_to, belongs_to_many, has_one, has_many). Omit for
    same-service relations. Option loading, has_one/has_many reads, and
    nested relation actions use the target service config and response
    mapping, not the parent service.

  • fields[].value_key / fields[].label_key — relation option key
    overrides (default id / name).

  • actions — top-level allowlist of CRUD abilities the upstream API
    actually supports: subset of ["create", "view", "update", "delete",
    "mass_delete"]. Omit ones the API lacks — e.g. ["create","view",
    "update"] for APIs with no DELETE /resource/{id}. The generated
    Resource overrides activeActions() so unsupported buttons don't render.

  • filters — per-field filter spec for the index page. Same field shape
    as fields[] (including `options` for select filters and
    `target_service` for cross-service relation filters).

  • layout (in create_resource / update_resource) — page layout preset.
    {"form": {"type": "box" | "two-column" | "tabs", "groups":
      [{"label": "...", "span": 8, "columns": ["first_name", ...]}, ...]}}.
    Default "box" reproduces the original single-Box wrap. "tabs" emits
    Tabs::make + Tab::make; "two-column" emits Grid::make + Column::make
    (span defaults to 6, sum should total 12). Columns not listed in any
    group spill into the last group so nothing silently drops. For nested
    layouts (Grid inside Column inside Box) pass the recursive
    `layout.form.tree` — reach for it only when presets run out.

  • detail_strategy / detail_list_key_param (per-resource, in
    create_resource / update_resource) — override the service default
    for THIS resource only. Use when most of the service has GET /{id}
    but one or two resources (lookup tables: statuses, tags, categories)
    expose only list + CUD. detail_strategy: "list" routes just that
    resource through the list filter, siblings keep "endpoint".
    detail_list_key_param overrides the filter key if the list uses a
    non-primary-key filter (e.g. ?status_id=7 vs ?id=7).

  • rules — validation for the FormPage. Three accepted shapes:
      (1) flat {col: "required|string"} — same for create+update;
      (2) per-column per-mode {col: {create: "required|...", update:
          "nullable|..."}} — one column differs between create/update;
      (3) top-level split {create: {col: "..."}, update: {col: "..."}}.
    Canonical pattern for user-like resources: `password` required on
    create, nullable on edit so a blank field does not wipe the upstream
    value. Generator branches the emitted rules() method on
    $item->getKey() === null (the "fresh record" signal).

  • default_query — query params auto-attached to every list / detail
    HTTP call: {index: {k: v}, detail: {k: v}}. Use for scoped APIs
    (e.g. `?for_entity=project` on multi-tenant statuses). User filters
    / sort / search / pagination always win — defaults only ride when
    nothing user-driven is set for that key.

  • fields[].endpoint_query — {k: v} map on a single relation field.
    Applied to every HTTP call that field makes (dropdown options,
    has_many list, has_one get). Field-local because the right scope
    often differs per referring resource. Use when the relation target
    demands a scope param (`?for_entity=project`) and `default_query`
    on the target resource doesn't fit all callers.
    Values support `{col}` placeholders that resolve from the parent
    form's current item + request state at render time — same syntax as
    mutations.path/body. `endpoint_query: {"project_id": "{project_id}"}`
    auto-scopes statuses to the saved project on the EDIT page.
    Placeholders resolving to null/empty get dropped. A CREATE-mode
    client-side reactive cascade (pick a project → refetch statuses)
    is not supported yet.

  • mutations — declarative CQRS routing. Use when the API does not expose
    plain POST /x / PUT /x/{id} (e.g. task-manager splits updates across
    /x/{id}/assignee and /x/{id}/status-transitions):

      mutations: {
        create: {method, path, body_from?, body?},
        update: [
          {method, path, body?, body_from?,
           when_present?: "column",   // skip if column not in changeset
           ignore_status?: [409]}     // HTTP codes to treat as success
        ]
      }

    Placeholders in path/body strings: {id} → item key, {column} → form
    value. After all update mutations run, the base resource GETs the item
    so the form sees canonical state.

  • auth_type (in register_service) — nine schemes:
    "bearer" (default, Authorization: Bearer <api_key>; works for Laravel
    Sanctum PATs, OAuth access tokens, GitHub PATs), "header" (custom
    header named by auth_header, value = api_key), "query"
    (?<auth_header>=<api_key>), "basic" (Authorization: Basic
    <base64(user:password)>; api_key = "user:password"), "oauth2_cc"
    (OAuth 2.0 client-credentials grant — requires token_url +
    oauth_client_id + oauth_client_secret + optional oauth_scope),
    "oauth2_password" (Resource Owner Password Credentials — static
    mode adds oauth_username + oauth_password on top of oauth_cc
    requirements; with admin_login=true omit those two and validate the
    human's /<panel>/login credentials through token_url, storing only
    token state in the Laravel session), "oauth2_refresh_token" (long-lived
    refresh token rotation — seed via oauth_refresh_token; rotations persist
    encrypted in DB and refreshes share a cache lock), "session"
    (cookie + CSRF login — static mode requires session_login_endpoint +
    session_username + session_password; with admin_login=true omit user
    credentials and validate the human's /<panel>/login credentials
    through session_login_endpoint, storing only cookie/CSRF state in the
    Laravel session; configurable csrf_token_source [cookie|header|body],
    csrf_token_key, csrf_header_name; optional csrf_bootstrap_path for
    Sanctum SPA's /sanctum/csrf-cookie pre-flight),
    or "none". All refresh-capable schemes (oauth2_*, session) auto-retry
    once on 401/419 with fresh credentials. Sensitive delegated secrets
    (oauth_password, oauth_refresh_token, session_password) live in .env
    only for local/self-hosted mode, or tenant-scoped encrypted storage in
    hosted mode — never in the baked config file.

  • admin_auth_mode (in register_service) — per-panel human login mode,
    separate from auth_type. auth_type is server-to-upstream API auth;
    admin_auth_mode is admin-to-panel login. "moonshine" keeps standard
    MoonShine login; "delegated_token" lets the panel accept a per-user
    token/PAT and requires admin_auth.validate_endpoint; "oauth2_authorization_code"
    enables OAuth Authorization Code + PKCE panel login and requires
    admin_auth.authorize_url and admin_auth.token_url. Pass
    admin_auth.scopes / admin_auth.redirect_uri when the IdP requires them.
    Never put "delegated_token" or "oauth2_authorization_code" in auth_type.
    Decision tree: choose "moonshine" for local admins + service credentials;
    choose "delegated_token" when each admin already has an upstream PAT/API
    token; choose "oauth2_authorization_code" for IdP/browser SSO. Upstream
    users are synced into moonshine_users and linked by
    moonshine_user_upstream_identities(provider_service, subject_id).
    Current SSO limits: SAML-only SSO is not supported directly, and full
    OIDC discovery/JWKS/ID-token validation is not a separate mode yet. Do
    not promise those as built-in; if the user needs SAML, OIDC
    discovery/JWKS, a multi-provider chooser, or another enterprise SSO
    protocol, tell them to open an issue in the project repository so we can evaluate adding it.

  • api_key (in register_service) — the secret value. Tool persists it
    both as a literal fallback in the generated <Name>Config.php AND as
    <NAME>_API_KEY in moonshine-v's .env in local/self-hosted mode. Hosted
    mode stores it per tenant and generates an empty fallback. env() wins
    at runtime locally so ops can rotate tokens without regenerating.
    Only <NAME>_API_URL / <NAME>_API_KEY / <NAME>_HEALTH_PATH are ever
    touched in .env.

  • health_path (in register_service) — "/health" (relative, appended to
    base_url) | "http://host/up" (absolute, used verbatim — handy when
    health lives outside base_url, e.g. base_url=/api but Laravel /up at
    host root) | "" (pings base_url itself).

  • realtime (in register_service) — optional webhook + browser refresh
    contract. Recommended broadcaster backend is Laravel Reverb, configured
    through Laravel broadcasting; the service config stores only:
      enabled: true|false
      webhook_secret: HMAC secret (required for enabled hosted realtime), persisted as <NAME>_WEBHOOK_SECRET in
        .env locally or TenantSecretStore in hosted mode, and preserved
        when omitted on re-register
      webhook_signature_header / webhook_signature_algo: defaults
        X-ApiAdmin-Signature / sha256
      webhook_event_key / webhook_resource_key / webhook_action_key /
        webhook_id_key: payload key names used by the normalizer
      allowed_events: default created, updated, deleted, upserted
      refresh_debounce_ms: default 750
      refresh_strategy: "auto" | "notice" | "disabled"
      sensitive_payload_keys: keys redacted from verbose webhook logs

  • response_mapping (in register_service) — envelope config. Keys:
    data_key, total_key, per_page_key, current_page_key, last_page_key,
    primary_key (envelope / pagination), plus:
      per_page_param_name: default "per_page"; Django "page_size", Spring "size".
      zero_based_pages: true translates upstream zero-based page numbers;
        UI/MCP page numbers remain one-based (default false).
      next_page_url_key / prev_page_url_key: body navigation URLs. The django
        preset maps next/previous and uses simple navigation when last-page
        metadata is absent, without guessing a page count from requested size.
      filter_format: "nested" (default, ?filter[x]=y) | "flat" (?x=y)
      filter_param_name: default "filter". Use "filters" for Laravel
        projects using Spatie Query Builder default config
        (?filters[status]=todo). If UI filters silently yield an
        unfiltered list, this is the most common cause — run
        probe_endpoint with detect_filter_for to confirm the shape
        (probe tests filter[], filters[], and flat; returns
        guessed_filter_format ∈ {nested, plural_nested, flat}).
      sort_param_name: default "sort". Override for APIs expecting
        "order_by" / "ordering".
      search_param_name: default "search". Override for APIs expecting
        "q" or "query".
      detail_data_key: dot-path inside the single-item response envelope
        where the item body lives (default empty = use data_key/unwrap
        heuristic). Set when single-item has a different / deeper
        wrapper than the list — e.g. Laravel JsonResource returning
        GET /resource/{id} as {"resource":{"data":{...}}} —
        detail_data_key: "resource.data". Without this the form page
        silently renders empty (primary_key unresolved in the caster).
      supports_sorting: false disables sort emission entirely for APIs
        that ignore it (avoids "sort UI silently no-ops" bug).
      pagination_type: "length_aware" (default) | "cursor" (resource
        emits ?cursor=<token>&per_page=M and renders a next-only
        CursorPaginator for `{data, cursor:{next}}` APIs) | "link_header"
        (RFC 5988 Link header with rel=first/prev/next/last — body is a
        bare JSON array, total comes from X-Total-Count or similar;
        matches GitHub/GitLab/Atlassian shapes — use `response_format:
        "github"` to get the whole preset in one shot) | "none".
      next_cursor_key / cursor_query_param: used when pagination_type
        = "cursor" (defaults "cursor.next" and "cursor").
      total_header: HTTP header carrying total row count when
        pagination_type = "link_header". Default "X-Total-Count"
        (GitHub). Use "X-Total" for GitLab. Empty = simple mode
        (next/prev only, no page count).
      detail_strategy: "endpoint" (default) | "list". Set "list" for
        APIs that expose only a list endpoint (no GET /resource/{id}) —
        findItem() then hits the list endpoint with <key>={id} and returns
        the matching row, never an unmatched first row. detail_list_key_param overrides the filter key
        (defaults to primary_key).
      list_request: {method:"POST", body:{...}} on create_resource /
        update_resource makes Index/fetch_resource_items/count/aggregate
        and list-strategy detail read via POST JSON body instead of GET.
        If body contains a list key like {"ids":[]}, detail_list_key_param
        = "ids" wraps id lookup as {"ids":[id]}.

Data-read tools (no generation, no mutation):

  • fetch_resource_items(service, resource, filter?, search?, sort?,
    page?, per_page?, cursor?, parent_context?) — wraps the generated
    resource's getItems(). Filter format, sort/search param names,
    pagination strategy (length_aware / cursor / link_header / none)
    all come from the resource config; agent never hand-builds query
    strings. Returns {items[], pagination: {strategy, ...}}. Cursor
    paginators expose pagination.next_cursor — pass it back as
    `cursor` on the next call.

  • fetch_resource_item(service, resource, id, parent_context?) —
    wraps findItem(). Honors per-resource detail_strategy override
    (endpoint vs list). Returns {item: {...}} with primary_key
    guaranteed at the top level.

  Relation resources (endpoint contains `{parent_id}` or similar)
  require `parent_context: {parent_id: N}` — otherwise both tools
  fail with code RELATION_RESOURCE_REQUIRES_PARENT and a placeholders
  list. Non-relation resources silently drop extra parent_context
  keys and set `ignored_parent_context: true` in the response.
  Use these instead of probe_endpoint when you need actual data —
  probe_endpoint is for shape discovery only.

Analytics tools (read-only, same resource path as data-read):

  • count_resource(service, resource, filter?, search?, time_window?,
    parent_context?, per_page?) — counts rows through getItems().
    Uses upstream pagination.total when available; otherwise counts the
    materialized page. Returns warning PARTIAL_COUNT when upstream says
    more pages exist but exposes no total.

  • aggregate_resource(service, resource, operation, field?, group_by?,
    filter?, search?, time_window?, parent_context?, limit?, per_page?)
    — operation is count/sum/avg/min/max. Numeric operations require
    field; count does not. group_by returns groups. Returns warning
    PARTIAL_AGGREGATE when pagination indicates more pages.

  time_window shape: {"field":"created_at","from":"2026-01-01",
  "to":"2026-01-31"}; at least one boundary is required. It becomes a
  normal filter entry under that field and follows response_mapping
  filter serialization.

Instructor tool (read-only onboarding status):

  • instructor_status(session_id?) — returns whether the built-in
    /api-admin/instructor browser wizard has an active session and a
    sanitized proposal. It does NOT register services or generate
    resources; use the existing register_service/create_resource tools
    or the browser confirmation flow for mutations. Secrets are redacted.
    Hosted sessions are isolated to the active tenant, even with an explicit ID.

Introspection tools (read-only, call once per session and cache):

  • list_fields — every `fields[].type` string the generator accepts,
    with group (scalar/relation/media), description, and is_custom
    flag. New types registered via create_field auto-appear.
  • describe_field_type(type) — chainable methods on the backing UI
    class (with accepts_condition flag) + curated schema_hints.
  • list_field_methods(type?) — union of chainable methods across
    types; `field_types: [...]` per entry answers "which types
    support sortable?" without per-type reflection.
  • list_components — layout primitives (Box/Tabs/Grid/Column/…) +
    caller-generated custom components.

  Live JSON snapshots also at moonshine-api-admin://fields, ://components,
  ://field-methods.

Data-write tools (resource-item CRUD, honour mutations.* routing):

  • create_resource_item(service, resource, payload, parent_context?)
  • update_resource_item(service, resource, id, payload,
    parent_context?) — findItem pre-check → NOT_FOUND before mutation.
  • delete_resource_item(service, resource, id, confirm: true,
    parent_context?) — DESTRUCTIVE, requires confirm: true.

  IDs/path placeholders are single URL segments: empty values, dot segments,
  slashes, backslashes, percent escapes and controls return VALIDATION before
  upstream writes. Other opaque IDs are encoded; body values stay unchanged.

  All three respect the resource-level `actions` allowlist. Sidecar
  `actions: ["view"]` → ACTION_NOT_ALLOWED on any write attempt.

Custom extension tools (scaffold missing primitives):

  • create_field(name, extends, alias?, methods_override?, properties?)
  • update_field(name, ...) — full rewrite, FIELD_NOT_FOUND if absent.
  • delete_field(name, confirm: true, force?) — DESTRUCTIVE, scans
    every spec sidecar for dependents (FIELD_IN_USE with a list).
  • create_component(name, extends?, view_content?, properties?) +
    update_component / delete_component — same contract, dependents
    scan walks layout.form.tree.

  Before calling create_field / create_component: ALWAYS call
  list_fields + describe_field_type (or list_components) first.
  A primitive you didn't know about almost always covers the case.

On success every tool returns {success: true, ...}. On failure
{success: false, error, code, hint?} — read `code` first, then `hint`.
Never retry a tool call after {success: true} — everything is already
applied, including hot-reload.

create_resource and update_resource additionally run a post-generation
validator and return `validation: {ok, issues[], files_checked}`. Each
issue carries level/file/message/hint. If ok=false, success is also
false — inspect `validation.issues` and re-run update_resource with a
corrected spec rather than patching the generated file by hand.
TXT;
    }

    private function respond(mixed $id, mixed $result): void
    {
        $payload = [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ];

        fwrite(STDOUT, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL);
        fflush(STDOUT);
    }

    private function respondError(mixed $id, int $code, string $message): void
    {
        $payload = [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];

        fwrite(STDOUT, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL);
        fflush(STDOUT);
    }
}
