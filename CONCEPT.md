# moonshine-v — product concept

## In one paragraph

**moonshine-v is a free, AI-driven admin panel for REST APIs that runs in Docker.** A developer starts it with one command, connects its MCP server to Claude Code (or any MCP client) and builds a complete admin panel over their backend by voice or prompt. No editing of moonshine-v code. No knowledge of PHP, Laravel or MoonShine. No frontend developer on the team.

## Who it is for and why

**Target user** — a backend developer or CTO who:
- has a REST API (Laravel, Django, Express, FastAPI, Spring Boot — it does not matter)
- needs an admin panel for internal operations (CRUD, search, relations between entities)
- does not want to write UI, hire an admin frontend developer or learn Filament/Nova/Retool
- already works with an AI agent in their editor (Claude Code, Cursor)

**Key value** — from "I have an API" to "I have an admin panel" in half an hour, without a single line of code inside moonshine-v.

## Admin panel lifecycle (everything through a conversation with the agent)

```
[ Docker is up ]
        │
        ▼
┌─────────────────────────────┐
│ 1. SERVICE INITIALIZATION   │  "I have API X with a Bearer token, Django format. Connect it."
│ register_service            │  → panel /x-admin/ is ready, menu is empty
│ probe_endpoint              │  → the agent checked and confirmed the preset
└─────────────────────────────┘
        │
        ▼
┌─────────────────────────────┐
│ 2. RESOURCE GENERATION      │  "Build CRUD for posts, authors and tags.
│ create_resource × N         │   A post has a has_one relation to its author."
│ (related has_many/one       │  → Index/Form/Detail for each, relations work
│  resources are automatic)   │
└─────────────────────────────┘
        │
        ▼
┌─────────────────────────────┐
│ 3. APPEARANCE               │  "Change the logo and title to Acme."
│ configure_layout            │
│                             │  → layout rebuilt, UI updated
└─────────────────────────────┘
        │
        ▼
┌─────────────────────────────┐
│ 4. IMPROVEMENTS + FIELDS    │  "Add Status as a Select with draft/published.
│ update_resource             │   Remove body from the index page. Make title sortable."
│                             │  → files regenerated, reload, UI updated
└─────────────────────────────┘
        │
        ▼  (a loop — the developer returns to step 4 after every API change)
┌─────────────────────────────┐
│ 5. OBSERVATION / DIAGNOSTICS│  "What is in my admin? Do all services respond?"
│ list_services, list_resources│
│ health_check                │  → the agent sees the state and proposes changes
└─────────────────────────────┘
        │
        ▼
┌─────────────────────────────┐
│ 6. REMOVAL (when needed)    │  "Remove the crm service, we no longer need it."
│ remove_resource,            │  → clean, no traces in configs
│ remove_service              │
└─────────────────────────────┘
```

At every step the developer **says what they want in natural language**. The agent turns it into concrete MCP calls. If the agent makes a mistake, the MCP tool response contains `code` + `hint`, so the agent understands and retries on its own.

## Working with fields and components

This is the most frequent scenario: the API changes, requirements grow, and the admin panel has to keep up.

**Add a field** → "Add a `priority` field (number, sortable) to posts." The agent calls `add_field(service, resource, field: {...})`. If the generator stubs know this type, it appears immediately. If not (a custom type), the agent proposes the closest built-in one.

**Change a field type** → "Replace Status from text to a Select with draft/published/archived." `update_resource(..., fields: [..., {column:"status", type:"select", options:{draft:"Draft",...}}])`. The old field is replaced in Index/Form/Detail.

**Add a relation** → "Posts belong to an author, linked by author_id." The agent adds `{type: "belongs_to", endpoint: "/authors"}`. To show author details on the post page it uses `RestHasOne` with a resource parameter and defines the subfields there too.

**Remove a field** → "Remove `body` from the list, keep it only in the form and detail." The agent sets `hide_on` or defines `index_fields`. For precise edits there are `add_field`, `remove_field`, `rename_field` and `reorder_fields`, while `update_resource` stays a full spec replacement.

**Components beyond fields** — a form is laid out through `layout.form`: the `box`, `tabs` and `two-column` presets and a recursive tree of Box, Grid, Column, Tabs, Tab and Collapse. If no primitive fits, in local mode the agent creates its own fields and components through `create_field` and `create_component`.

## How it works for the developer

```
# 1. Start the product (one command)
npx --yes --package=https://github.com/moonshine-software/v/archive/refs/heads/main.tar.gz moonshine-v-install

# 2. The installer connects your MCP client
# (a template for manual setup ships as .mcp.json.example)

# 3. Restart Claude Code — the moonshine-<id> server's tools are visible
#    (a manual .mcp.json entry named moonshine-v exposes them as moonshine-v_*)

# 4. Talk to the agent:
>>> "I have an API at https://api.acme.com/v1, token sk-xxx.
     Build an admin for posts and authors. Laravel paginator."

# Under the hood the agent runs:
#   probe_endpoint(...) — inspects the response format itself
#   register_service(..., response_format: "laravel")
#   create_resource(posts, fields: [...])
#   create_resource(authors, fields: [...])
#   update_resource(posts, add RestHasOne to authors)

# 5. Open /acme-admin/ in a browser → the admin panel works.
```

The developer **does not open** moonshine-v code. All interaction goes through MCP tools from their editor.

## What is inside

**Foundation.** Laravel + MoonShine (CRUD UI, routing, authorization). A REST layer sits on these rails: resources do not talk to a database, they make HTTP calls to a specific service. Each admin has its own "panel" (URL prefix + layout + menu).

**Fields** mean the same as in MoonShine: `Text`, `Select`, `Textarea`, `Date`, `Checkbox`, plus the REST-specific `RestBelongsTo`, `RestBelongsToMany`, `RestHasOne`, `RestHasMany`. All operations (CRUD, relations, preview) go through HTTP requests to the API.

**MCP layer** — tools with complete JSON Schema, actionable errors and safe guards (`confirm: true` on destructive operations). The set follows the admin lifecycle: **discovery** (`probe_endpoint`, `health_check`), **creation** (`register_service`, `create_resource`), **observation** (`list_services`, `list_resources`, `get_resource`), **editing** (`update_resource`, `add_field`, `configure_layout`), **data** (`fetch_resource_items`, `update_resource_item`, `count_resource`), **removal** (`remove_resource`, `remove_service`). The full list is in `AGENTS.md`.

**Multi-panel.** One Docker instance serves N services, each with its own URL prefix, layout, menu and authorization. `/blog-admin`, `/shop-admin` and `/crm-admin` live in one installation but do not overlap.

**Generator.** MCP tools write PHP files from stubs. Each resource is a `{Service}/{Entity}/` directory with three pages (Index / Form / Detail). Hot reload after every change.

## What makes the product special

1. **Workflow-first, not code-first.** Competing admin tools (Nova, Filament, Retool, Forest) require code, visual drag-and-drop or an admin SDK in the backend. In moonshine-v the **AI builds the interface** through a conversation. The developer writes prompts, not resources.

2. **No backend vendor lock-in.** No SDK, decorators or schemas in the service code. A plain REST API is enough. The service does not know moonshine-v exists.

3. **Multi-service out of the box.** One Docker deployment covers a company's whole microservice ecosystem. There is no need for one admin per service.

4. **The agent reads errors, not a human.** All telemetry goes through MCP responses with `code` + `hint`, so the agent diagnoses and fixes problems itself. Logs are only needed for postmortems.

5. **Idempotency.** MCP tools can be rerun safely. `create_resource` with the same name regenerates the files. `remove_service` + `register_service` is a clean start. An AI agent can fail and retry without fear of breaking state.

## Delivery model

- **An interactive installer** (one command) that builds the Docker images locally from the source. No prebuilt images are published.
- **Free**, MIT license.
- **MCP connection** — the installer configures Claude Code, Codex, Cursor, Gemini CLI and Windsurf; the `.mcp.json.example` template remains for manual setup.
- **Data storage** — service data lives in their APIs. The admin itself stores users, generated state and hosted secrets in PostgreSQL and a persistent volume; upgrades are described in `docs/production.md`.

## Quality guarantees

- **No knowledge of internals needed.** A developer can work without ever opening moonshine-v PHP code. If something breaks, the agent reads the `hint` in the MCP tool response, refines the prompt and retries. Human documentation is only needed for "how to connect MCP" and "how to describe my API"; see `docs/SMOKE_CHECKLIST.md`.
- **Tests as a specification.** Automated tests cover the generator, REST fields, MCP tools and common API response formats. Regressions are caught before a release.
- **Observability through MCP.** The agent calls `health_check` / `probe_endpoint` as part of debugging, without reading logs or opening a browser.

## Limitations of the current generation

- **Service authentication** — Bearer, API key in a header or query, Basic, OAuth2 (client credentials, password, refresh), session/CSRF. Panel sign-in is local, a delegated token or OAuth2 Authorization Code + PKCE. SAML and OIDC discovery are not separate modes yet.
- **Response format** — Laravel paginator, Django REST, flat JSON array; anything else through `response_format: "custom"` + `response_mapping`.
- **Relations** — one level (post.author, post.comments, post.tags), including across services through `target_service`. Nested includes (post.author.posts) are for the future.
- **Admin UX** — MoonShine UI components are used as is. A service's look is customized through `configure_layout` (logos, title) and resource `group`/`icon` in the menu.

## Strategic direction: MCP as a two-way channel

MCP tools are not only a **generator** of the admin (`register_service`, `create_resource`, `configure_layout`) but also a **runtime channel** to the services' data: the admin works both in the browser and through the assistant.

After a developer registers `orders` through `register_service` + `create_resource`, the agent can use the data tools:

```
fetch_resource_items(service: "shop", resource: "orders",
                     filter: {status: "paid"}, sort: "-created_at", per_page: 25)
count_resource(service: "shop", resource: "orders",
               filter: {status: "paid"})
fetch_resource_item(service: "shop", resource: "orders", id: 42)
```

It reads data through the same `ServiceConfig` (`base_url` / `api_key` / `response_mapping`) that the admin UI uses. A resource is described once, and both the panel and the assistant work from one specification.

**What this gives:**

1. **A voice request instead of a UI click.** A developer asks in the editor "how many orders do I have today?" and the agent calls `count_resource(service: "shop", resource: "orders", time_window: {...})` and answers with a number. "Show the last 5 `paid` orders from Acme Corp" becomes `fetch_resource_items(...)`, formatted as a table in the chat. There is no need to open a browser for a quick answer.
2. **One source of truth for two channels.** If `orders` changes its auth, base_url or response format, or gains a field, `update_resource` updates both the UI and the runtime channel in one operation. No parallel descriptions for the assistant.
3. **Role scoping.** In hosted mode a token limits tool families (`tools:read`, `tools:data:read`, `tools:data:write` and others), and `actions` in the resource spec forbids individual operations.
4. **The admin as a knowledge layer.** The set of registered services, typed resources and relations is effectively a machine-readable map of the business. For an AI assistant this is ready context: "this user has orders, each order has a customer and items, the customer is linked to support_tickets", without a separate integration in every microservice.

Technically this is a thin wrapper around `AbstractRestResource`: the same `findItem`, `getItems`, filters, sorting and pagination the UI renders.

## Where we are going

- **Built-in analytics:** metrics, charts and dashboards on top of the same REST layer.
- **Plugins and third-party extensions** (a marketplace).
- **Enterprise SSO:** SAML, OIDC discovery and multi-provider sign-in.

## Definitions and jargon

- **Service** — a registered external REST API (example: `blog`, `shop`, `crm`).
- **Panel** — URL prefix + layout + menu for one service (example: `/blog-admin/`).
- **Resource** — a CRUD entity of a service (example: `post`, `author`). Generates Index / Form / Detail pages.
- **MCP** — Model Context Protocol. The protocol an AI agent (Claude Code, Cursor) uses to talk to moonshine-v tools.
- **Preset** — a ready-made `response_mapping` for a specific API format (`laravel`, `django`, `flat`, `custom`).
- **Relation resource** — a subordinate resource that exists only in the context of a has_one/has_many parent (example: `Comment` inside `Post`). Generated automatically by `create_resource(..., fields: [{type: "has_many", fields: [...]}])`.

---

**Links:**
- `.ai-factory/ROADMAP.md` — completed milestones.
- `docs/SMOKE_CHECKLIST.md` — a step-by-step product check on a real service.
- `AGENTS.md` — the "MCP tools" section with the tool table and the typical flow.
- `.mcp.json.example` — a template for connecting from Claude Code in another project.
