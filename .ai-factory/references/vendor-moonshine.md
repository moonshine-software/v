# Vendor MoonShine Framework Reference

> Source: vendor/moonshine/moonshine/src/
> Created: 2026-03-27
> Updated: 2026-03-27
> Status: snapshot taken before the MoonShine 5 migration. Check the installed sources in `vendor/moonshine/moonshine/src/` before relying on a class or method listed here.

## Overview

MoonShine is a modular Laravel admin panel framework. The vendor source is organized into 9 modules: Core, Crud, Laravel, UI, Support, Contracts, MenuManager, AssetManager, and ColorManager. Each module is a separate package with its own namespace.

## Module Architecture

```
vendor/moonshine/moonshine/src/
├── Core/           # 31 files — Foundation: Core container, Router, Request, Page, Resource base
├── Crud/           # 82 files — CRUD operations: CrudResource, Pages, Handlers, Buttons, Filters
├── Laravel/        # 159 files — Laravel integration: ModelResource, Controllers, Middleware, Fields
├── UI/             # 1492 files — Components: Fields, Layout, Table, Form, Modals, Metrics
├── Support/        # 45 files — Utilities: Enums, DTOs, Traits, Helpers
├── Contracts/      # 94 files — Interfaces for all modules
├── MenuManager/    # 13 files — Menu system: MenuGroup, MenuItem, MenuDivider, Autoloader
├── AssetManager/   # 11 files — CSS/JS asset management
└── ColorManager/   # 20 files — Color palettes and theming (18 built-in palettes)
```

## Core Module

**Namespace:** `MoonShine\Core`

### Core.php — Central Container
- Implements: `CoreContract`, `StatefulContract`
- Access: `getRenderer()`, `getRequest()`, `getCrudRequest()`, `getRouter()`, `getConfig()`, `getAssets()`, `getColorManager()`, `getMenuManager()`

### AbstractRouter
- Implements: `RouterContract`, `Stringable`
- Methods: `to(name, params)` (abstract), `withName()`, `withParams()`, `withPage()`, `withResource()`, `withResourceItem()`

### AbstractRequest
- Implements: `RequestContract`
- Methods: `get()`, `getScalar()`, `has()`, `getAll()`, `getOnly()`, `getExcept()`, `isAjax()`, `isWantsJson()`

### Page (Core)
- Implements: `PageContract`
- Properties: `$title`, `$subtitle`, `$layout`, `$components`, `$breadcrumbs`, `$pageType`
- Hooks: `loaded()`, `booted()`, `onLoad()`
- Methods: `getComponents()`, `addComponent()`, `hasComponent()`

### Resource (Core)
- Implements: `ResourceContract`
- Methods: `getPages()`, `getPage(PageType)`, `getUriKey()`, `getTitle()`

### TypeCasts
- `MixedDataCaster` implements `DataCasterContract` — generic data casting
- `MixedDataWrapper` implements `DataWrapperContract` — wraps raw data with key access

### Paginator
- `Paginator` implements `PaginatorContract` — core pagination with links

## Crud Module

**Namespace:** `MoonShine\Crud`

### CrudResource — Main CRUD Resource
- Generic: `CrudResource<TCore, TData, TIndexPage, TFormPage, TDetailPage, TException, TFields>`
- Extends: `Resource`
- Key Traits: `HasFilters`, `HasHandlers`, `HasQueryTags`, `HasCrudResponseModifiers`, `HasListComponent`, `ResourceWithButtons`, `ResourceActions`, `ResourceWithAuthorization`, `ResourceWithFields`, `ResourceCrudRouter`, `ResourceEvents`, `ResourceQuery`

**Abstract Methods (must implement):**
```php
findItem(bool $orFail = false): ?DataWrapperContract
getItems(): iterable|Collection|LazyCollection|CursorPaginator|Paginator
massDelete(array $ids): void
delete(DataWrapperContract $item, ?FieldsContract $fields): bool
save(DataWrapperContract $item, ?FieldsContract $fields): DataWrapperContract
```

**Properties:**
- `$column` (default: `'id'`), `$deleteRelationships`, `$casterKeyName`
- `$isAsync` (default: `true`), `$createInModal`, `$editInModal`, `$detailInModal`

### CRUD Pages
- `IndexPage` implements `IndexPageContract` — list view with table
- `FormPage` implements `FormPageContract` — create/edit form
- `DetailPage` implements `DetailPageContract` — item detail view

### Page Components
- `DefaultListComponent` — table for IndexPage
- `DefaultForm` — form builder for FormPage
- `DefaultDetailComponent` — detail view component

### Handlers & Attributes
- `Handler<TData>` — base handler
- `#[SaveHandler(service, method)]` — custom save logic
- `#[DestroyHandler(service, method)]` — custom delete logic
- `#[MassDestroyHandler(service, method)]` — custom bulk delete

### Buttons
- `CreateButton`, `EditButton`, `DetailButton`, `DeleteButton`, `MassDeleteButton`, `FiltersButton`

### QueryTag
- Named filter presets applied via URL query

### Layouts
- `AbstractLayout extends AbstractWithComponents`

## Laravel Module

**Namespace:** `MoonShine\Laravel`

### ModelResource — Eloquent CRUD
- Generic: `ModelResource<TData, TIndexPage, TFormPage, TDetailPage>`
- Extends: `CrudResource<MoonShine, TData, ...>`
- Implements: `WithQueryBuilderContract<Builder>`
- Properties: `$model` (class-string), `$column`
- Key Methods: `getModel()`, `getColumn()`, `getQuery()`, `setQueryBuilder()`

### Relationship Fields
All extend `ModelRelationField`:
- `BelongsTo` — many-to-one
- `HasOne` — one-to-one
- `HasMany` — one-to-many
- `BelongsToMany` — many-to-many
- `HasOneThrough`, `HasManyThrough` — through relations
- `MorphOne`, `MorphMany`, `MorphTo`, `MorphToMany` — polymorphic
- `RelationRepeater` — repeatable relation fields
- `Slug` — URL slug field

### Controllers
- `CrudController` — main CRUD operations (index, show, store, update, destroy)
- `HomeController` — dashboard
- `AuthenticateController` — login/logout
- `ProfileController` — user profile
- `HandlerController` — custom handler execution
- `ComponentController` — component rendering
- `MethodController` — method calls
- `ReactiveController` — AJAX field reactivity
- `UpdateFieldController` — inline field updates
- `HasManyController`, `BelongsToManyPivotController` — relation operations

### Form Requests
- `MoonShineFormRequest` — base
- Resource requests: `CreateFormRequest`, `StoreFormRequest`, `EditFormRequest`, `UpdateFormRequest`, `DeleteFormRequest`, `MassDeleteFormRequest`, `ViewAnyFormRequest`, `ViewFormRequest`

### Layouts
- `AppLayout` — main application layout (extends `AbstractLayout`)
- `BaseLayout` — base structure
- `BlankLayout` — minimal
- `LoginLayout` — authentication page

### Type Casting
- `ModelCaster` implements `DataCasterContract` — Eloquent model casting
- `ModelDataWrapper` implements `DataWrapperContract` — model wrapping

### Relation Field Traits
- `WithAsyncSearch` — async search for relations
- `WithRelatedValues` — reactive field dependencies
- `WithRelatedLink` — links to related resources
- `BelongsToOrManyCreatable` — inline relation creation
- `HasTreeMode` — tree structure display
- `HasHorizontalMode` — horizontal form layout

### Filter Applies
Database filter implementations: `BelongsToModelApply`, `BelongsToManyModelApply`, `SelectModelApply`, `TextModelApply`, `CheckboxModelApply`, `DateModelApply`, `DateRangeModelApply`, `RangeModelApply`, `JsonModelApply`, `MorphToModelApply`

## UI Module

**Namespace:** `MoonShine\UI`

### Field Hierarchy
```
FormElement (base — column, value, label, showWhen, applies)
  └── Field (display modes: default/preview/raw, sort, hint, link, badge, reactivity)
      ├── Text, Textarea, Email, Password, PasswordRepeat
      ├── Number, Range, RangeSlider
      ├── Select, Checkbox, Switcher, Enum
      ├── Date, DateRange
      ├── File, Image
      ├── Color, Phone, Url
      ├── Hidden, HiddenIds, ID
      ├── Json, Position, Preview, Template
      └── Fieldset, FieldContainer
```

### FormElement Properties & Methods
- `$column`, `$virtualColumn`, `$value`, `$formattedValue`, `$rawValue`, `$formName`
- `setColumn()`, `setLabel()`, `fill()`, `formattedValue()`

### Field Properties & Methods
- `$defaultMode`, `$previewMode`, `$rawMode`, `$withWrapper`, `$nullable`
- `$columnSelection`, `$columnHideOnInit`, `$stickyColumn`
- `defaultMode()`, `previewMode()`, `rawMode()`, `changePreview()`, `columnSelection()`, `sticky()`, `onChangeUrl()`

### Layout Components
- `Layout`, `Html`, `Head`, `Body`, `Wrapper`
- `TopBar`, `Header`, `Sidebar`, `Content`, `Footer`, `BottomBar`, `SecondBar`, `MobileBar`
- `Grid`, `Column`, `Flex`, `Box`, `Div`
- `Burger`, `Logo`, `Menu`, `Assets`, `Meta`, `Favicon`, `ThemeSwitcher`
- `Locales`, `Search`, `Notifications` (from Crud)

### Form & Table
- `FormBuilder` implements `FormBuilderContract` — `getFields()`, `addField()`, `getField()`, `hasField()`, `removeField()`
- `TableBuilder` implements `TableBuilderContract` — `getRows()`, `getColumns()`, `addColumn()`
- `TableRow`, `TableCell`

### Interactive Components
- `Modal` implements `ModalContract`
- `OffCanvas` implements `OffCanvasContract`
- `Tabs` — tab navigation
- `Collapse` — collapsible section
- `Dropdown` — dropdown menu
- `Popover`, `Tooltip`

### Content Components
- `Card`, `CardsBuilder` — card displays
- `Alert`, `Badge`, `Breadcrumbs`, `Heading`, `Icon`
- `Link`, `Img`, `Carousel`
- `ProgressBar`, `Loader`, `Skeleton`
- `ActionButton` implements `ActionButtonContract`
- `ActionGroup` — button groups
- `FlexibleRender`, `FieldsGroup`, `Files`
- `IterableComponent` — repeater

### Input Extensions
- `Icon`, `Prefix`, `Suffix`, `Eye` (password toggle), `Lock`, `Copy`, `Up/Down` spinner

## Contracts Module

**Namespace:** `MoonShine\Contracts`

### Core Contracts
- `PageContract<TCore, TResource>`, `ResourceContract`, `CrudPageContract`, `CrudResourceContract`
- `ComponentContract`, `HasComponentsContract`, `HasListComponentContract`
- `HasResourceContract<TResource>`, `HasCoreContract`, `HasUriKeyContract`
- `HasCanSeeContract`, `HasStructureContract`, `StatefulContract`

### DI Contracts
- `CoreContract`, `RouterContract`, `RequestContract`, `CrudRequestContract`
- `FieldsContract`, `ViewRendererContract`, `StorageContract`
- `ConfiguratorContract`, `TranslatorContract`, `EndpointsContract`
- `AppliesRegisterContract`, `OptimizerCollectionContract`

### UI Contracts
- `FieldContract`, `FormElementContract`, `FormContract`, `FormBuilderContract`
- `LayoutContract`, `ModalContract`, `OffCanvasContract`
- `TableBuilderContract`, `TableRowContract`, `TableCellContract`
- `ActionButtonContract`, `HasFieldsContract`, `HasButtonsContract`
- `RelationFieldContract`, `SortableFieldContract`, `ApplyContract`
- `HasAsyncContract`, `HasReactivityContract`, `HasShowWhenContract`

### TypeCast Contracts
- `DataCasterContract<TInput, TOutput>`, `DataWrapperContract<TData>`

## Support Module

**Namespace:** `MoonShine\Support`

### Enums
- `Ability` — create, read, update, delete, view
- `Action` — CRUD action types
- `PageType` — Index, Form, Detail, Custom
- `Layer` — Top, Main, Bottom, Extra
- `Color` — palette definitions
- `FlashType` / `ToastType` — notification types
- `FormMethod`, `HttpMethod` — HTTP verbs
- `SortDirection` — ASC, DESC
- `JsEvent` — JavaScript events
- `ClickAction` — button click actions

### DTOs
- `AsyncCallback`, `FileItem`, `FileItemExtra`, `ShowWhenCondition`
- `Select\Option`, `Select\OptionGroup`, `Select\Options`, `Select\Settings`, `Select\AsyncSettings`

### Key Traits
- `Makeable` — static `make()` factory method
- `WithComponentAttributes` — custom HTML attributes
- `WithQueue` — queue support

## MenuManager Module

**Namespace:** `MoonShine\MenuManager`

- `MenuManager` — core menu system (`getElements()`, `addElement()`, `removeElement()`)
- `MenuElement` — base item (`$label`, `$icon`, `$badge`, `$url`, `$order`, `$canSee`)
- `MenuGroup` — grouped items container
- `MenuItem` — single menu link
- `MenuDivider` — visual separator
- `MenuAutoloader` — auto-discovers menu items

### Menu Attributes
- `#[Order(int)]` — menu item order
- `#[Badge(string|Closure)]` — badge on menu item
- `#[Group(string)]` — group assignment
- `#[CanSee(Closure)]` — conditional visibility
- `#[SkipMenu]` — exclude from menu

## AssetManager Module

**Namespace:** `MoonShine\AssetManager`

- `AssetManager` — `addCss()`, `addJs()`, `addInlineJs()`, `addInlineCss()`, `getCss()`, `getJs()`
- `Css`, `Js` — external asset links with versioning
- `InlineCss`, `InlineJs` — inline code
- `Raw` — raw HTML asset

## ColorManager Module

**Namespace:** `MoonShine\ColorManager`

- `ColorManager` — `getPalette()`, `setPalette()`, `getCurrentPalette()`
- 18 built-in palettes: Cyan, Gray, Green, Halloween, Lime, Neutral, Orange, Pink, Purple, Retro, Rose, Sky, Spring, Teal, Valentine, Winter, Yellow + default

## Key Architectural Patterns

### Resource Hierarchy
```
Core\Resources\Resource (base)
  └── Crud\Resources\CrudResource (CRUD operations — abstract)
      └── Laravel\Resources\ModelResource (Eloquent integration)
```

### Page Hierarchy
```
Core\Pages\Page (abstract base)
  └── Crud\Pages\CrudPage (CRUD behavior)
      ├── IndexPage, FormPage, DetailPage
      └── Laravel\Pages\Crud\* (Laravel overrides)
```

### Request → Controller → Resource Pipeline
```
FormRequest → CrudController → ModelResource → Eloquent → Response
             ↕                ↕
         Middleware        Handlers/Events
```

### Field Apply Pipeline
```
field.beforeApply(item) → field.apply(closure, item) → field.afterApply(item)
```

### Authorization Flow
- Policy-based via `ResourceWithAuthorization` trait
- `HasCanSeeContract` for component visibility
- `Ability` enum: create, read, update, delete, view
