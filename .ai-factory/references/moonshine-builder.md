# MoonShine Builder Reference

> Source: https://github.com/dev-lnk/moonshine-builder (branch: 3.x)
> Created: 2026-03-27
> Updated: 2026-03-27

## Overview

MoonShine Builder is a code generation package for MoonShine 4.0+ that creates Resource, Model, Migration, and Page files from various input sources: SQL tables, JSON schemas, console input, or existing Laravel models. It automates the scaffolding of MoonShine admin panel resources with correct field types and relationships.

**Requirements:** MoonShine 4.0+, Laravel 10+, PHP 8.2+

## Installation

```bash
composer require dev-lnk/moonshine-builder --dev
```

Publish config:
```bash
php artisan vendor:publish --tag=moonshine-builder
```

## Configuration

```php
// config/moonshine-builder.php
return [
    'builds_dir' => base_path('builds'),         // JSON schema directory
    'base_model_path' => 'app/Models',            // Models directory
    'is_confirm_replace_files' => true,            // Confirm file overwrites
    'is_confirm_change_provider' => false,         // Confirm provider changes
    'is_confirm_change_menu' => false,             // Confirm menu changes
];
```

## Commands

| Command | Description |
|---------|-------------|
| `php artisan moonshine:build` | Interactive — choose type and target |
| `php artisan moonshine:build {target} --type={type}` | Direct — specify target and type |
| `php artisan moonshine:build-json {target?}` | Generate from JSON schema |
| `php artisan moonshine:build-table {target?}` | Generate from SQL table |
| `php artisan moonshine:build-resource {entity?} {fields?*}` | Generate from console input |
| `php artisan moonshine:build-model {entity?} {--all}` | Generate from existing model |
| `php artisan moonshine:project-schema` | Mass import — all tables to JSON schema |
| `php artisan moonshine:build-types` | List available field types |

## Generation Methods

### 1. From SQL Table

```bash
php artisan moonshine:build-table users
```

Reads table columns and generates fields with correct types (ID, Text, Date, etc.).

### 2. From JSON Schema

Schema file format (place in `builds_dir`):

```json
{
  "resources": [
    {
      "name": "Category",
      "timestamps": true,
      "softDelete": false,
      "withResource": true,
      "withModel": true,
      "withMigration": true,
      "fields": [
        {
          "column": "id",
          "type": "id",
          "methods": ["sortable"]
        },
        {
          "column": "name",
          "type": "string",
          "name": "Name"
        }
      ]
    }
  ]
}
```

```bash
php artisan moonshine:build-json category.json
```

### 3. From Console

```bash
php artisan moonshine:build-resource Status id:Id:id name:Name:string
```

Field format: `{column}:{columnName}:{type}`

### 4. From Existing Model

```bash
php artisan moonshine:build-model Product
php artisan moonshine:build-model "App\Models\Product"
php artisan moonshine:build-model --all  # All models
```

Auto-detects: table columns, relationships (HasMany, BelongsTo, BelongsToMany, HasOne), timestamps, soft deletes.

### 5. Mass Import (Project Schema)

```bash
php artisan moonshine:project-schema
```

Interactive: select pivot tables first (for BelongsToMany), then tables to generate. Creates a JSON schema file that can be edited and used with `moonshine:build`.

## Generated Files

| File | Path | Description |
|------|------|-------------|
| Resource | `app/MoonShine/Resources/{Name}Resource.php` | CRUD resource with fields |
| IndexPage | `app/MoonShine/Pages/{Name}/IndexPage.php` | List view page |
| FormPage | `app/MoonShine/Pages/{Name}/FormPage.php` | Create/edit form page |
| DetailPage | `app/MoonShine/Pages/{Name}/DetailPage.php` | Detail view page |
| Model | `app/Models/{Name}.php` | Eloquent model |
| Migration | `database/migrations/...create_{table}.php` | Database migration |

## JSON Schema Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `name` | `string` | required | Resource/model name |
| `timestamps` | `bool` | `false` | Add created_at/updated_at |
| `softDelete` | `bool` | `false` | Add deleted_at |
| `withResource` | `bool` | `true` | Generate resource file |
| `withModel` | `bool` | `true` | Generate model file |
| `withMigration` | `bool` | `true` | Generate migration file |
| `fields[].column` | `string` | required | Database column name |
| `fields[].type` | `string` | required | Field type (use `moonshine:build-types` to list) |
| `fields[].name` | `string` | — | Display label |
| `fields[].methods` | `array` | `[]` | Method calls on the field (e.g., `sortable`) |

## Architecture

```
src/
├── Commands/           # Artisan commands (7 commands)
├── Enums/              # BuildType, ParseType, SqlTypeMap, StubValue
├── Exceptions/         # Custom exceptions
├── Providers/          # MoonShineBuilderProvider
├── Services/
│   ├── Builders/       # Code builders (Resource, Model, Migration, Pages)
│   │   ├── Factory/    # AbstractBuildFactory, MoonShineBuildFactory
│   │   └── Contracts/  # Builder contracts for each entity type
│   ├── CodeGenerator.php      # Main orchestrator
│   ├── CodePath/              # File path resolution
│   ├── CodeStructure/         # Schema parsing & structure
│   │   └── Factories/         # StructureFromJson/Mysql/Model/Console/Array/Openapi
│   └── StubBuilder.php        # Template-based file generation
├── Support/            # NameStr, TypeMap, Makeable trait
└── helpers.php
```

## Best Practices

1. Use `--dev` flag when installing — this is a development tool, not needed in production.
2. For large projects, use `moonshine:project-schema` first to generate a JSON schema, review/edit it, then generate resources.
3. The JSON schema supports multiple resources in one file — use this for related entities.
4. After generation, review and customize the generated resources — the builder provides a starting point, not a final product.

## Common Pitfalls

- **File overwrite**: By default, the builder asks before overwriting. Set `is_confirm_replace_files` to `false` in config to auto-overwrite.
- **Pivot tables**: When using `project-schema`, select pivot tables first to correctly generate BelongsToMany relationships.
- **Type mapping**: Use `php artisan moonshine:build-types` to see available field types before writing JSON schemas.
