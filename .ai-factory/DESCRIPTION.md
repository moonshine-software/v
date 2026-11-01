# Project: moonshine-api-admin

## Overview
A free, MIT-licensed Laravel application that provides an AI-driven admin panel for microservices. Ships as Docker images with Laravel + MoonShine 5, where an AI agent (Claude) initializes and manages the admin panel via MCP server. Developers never touch the admin code — everything is controlled by AI through code generation.

## Core Features
- REST resources that work over HTTP — supports any microservice regardless of stack
- Local Eloquent services: native MoonShine resources for models in the same application, in trusted local mode
- Configurable API response mapping (Django REST, Express, Spring Boot, Laravel formats)
- Relation fields (BelongsTo, HasMany, HasOne, BelongsToMany) that operate via HTTP
- Multi-panel architecture: one panel per microservice, isolated routing and config
- Two-layer validation: Laravel rules + API 422 error mapping to form fields
- MCP server with tools for services, resources, field deltas, data, analytics, introspection and extensions (see `AGENTS.md`)
- Hosted MCP transport over HTTP/SSE with tenant-bound developer bearer tokens
- Built-in Instructor Agent: browser onboarding that probes an API, proposes `register_service` + initial `create_resource`, and waits for human confirmation before generation
- Hot-reload after code generation (composer dump-autoload + cache clear)
- Realtime upstream updates via service webhooks, Laravel broadcasting/Reverb, and MoonShine browser refresh metadata

## Tech Stack
- **Language:** PHP 8.5
- **Framework:** Laravel 13
- **Admin Panel:** MoonShine 5, `5.x-dev` pinned in `composer.lock` (REST resources are CrudResource-based; local services use ModelResource)
- **Database:** SQLite for source development; PostgreSQL + Redis in installer and production stacks (admin panel metadata only)
- **Frontend:** Tailwind CSS 4, Vite 8 (MoonShine built-in UI)
- **Testing:** PHPUnit 12
- **Code Quality:** Laravel Pint, Rector, PHPStan max, `composer audit`
- **Distribution:** runnable Laravel application with the API Admin module in `app/ApiAdmin/`

## Architecture Notes
- Extends CrudResource (bypasses Eloquent entirely) for all REST resources
- ConfigurableApiCaster wraps any API response format into MixedDataWrapper
- ServiceConfig DTO holds base_url, auth, response mapping per microservice
- RealtimeConfig DTO holds per-service webhook verification, event normalization, and refresh behavior
- MCP tools generate PHP files (resources, configs, layouts) — not runtime configuration
- Multi-panels mechanism isolates each microservice into its own admin panel with unique prefix
- API key propagated through entire chain: ServiceConfig -> HttpClient -> RelationFields
- Webhook secrets are sourced from `<SERVICE>_WEBHOOK_SECRET`; generated config must not contain literal secret fallbacks

## Architecture
See `.ai-factory/ARCHITECTURE.md` for detailed architecture guidelines.
Pattern: Modular Monolith

## Non-Functional Requirements
- Logging: Configurable via LOG_LEVEL
- Error handling: Structured error responses, API error mapping to form fields
- Security: API key management per service, no sensitive data in generated code
- Docker: Ready-to-run stack (Angie + php-fpm, Postgres, Redis)
