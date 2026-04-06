# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

PHPolygon Editor is a desktop game scene editor for the PHPolygon engine. It's a Laravel 11 + Vue 3 SPA wrapped in NativePHP (Electron). Developers use it to visually create/edit game scenes, manage entities and ECS components, and save them as PHP scene files.

The engine dependency (`phpolygon/phpolygon`) is linked as a local path repository via Composer symlink.

## Commands

```bash
# Development (runs Laravel server + queue + Vite concurrently)
composer dev

# Native desktop app development
composer native:dev

# Frontend only
npm run dev

# Build frontend for production
npm run build

# Run all PHP tests
./vendor/bin/phpunit

# Run a specific test file
./vendor/bin/phpunit tests/Editor/Command/EditorCommandsTest.php

# Run a specific test method
./vendor/bin/phpunit --filter test_create_entity

# PHP code style (Laravel Pint)
./vendor/bin/pint
```

## Architecture

### Backend (PHP)

**Two namespaces:**
- `App\` (`app/`) — Laravel scaffolding: controllers, providers, models
- `PHPolygon\Editor\` (`src/`) — Core editor logic, framework-agnostic

**Command Bus pattern** — All editor operations go through `EditorCommandBus`. Commands implement `CommandInterface::execute(EditorContext)`. New operations require: a command class in `src/Command/`, registration in `EditorServiceProvider`, and a frontend bridge function.

**EditorContext** (`src/EditorContext.php`) — Central object holding the project manifest, component/system registries, scene transpiler, and project directory. Bound as a singleton; rebuilt when a project is opened.

**SceneDocument** (`src/SceneDocument.php`) — In-memory scene representation with entity/component CRUD. Implements undo/redo via JSON snapshots (max 100 levels). This is the mutable state that commands operate on.

**ComponentRegistry** (`src/Registry/ComponentRegistry.php`) — Discovers ECS component classes via PSR-4 namespace scanning and PHP reflection. Components must implement `PHPolygon\ECS\ComponentInterface` with the `Serializable` attribute.

**InspectorMetadataExtractor** (`src/Inspector/`) — Reflects component classes to extract property schemas (type, defaults, editor hints like min/max/slider/angle). This drives the dynamic inspector UI.

**Single API controller** (`app/Http/Controllers/EditorApiController.php`) — All routes under `/api/editor/`. The `POST /command` endpoint is the main entry point; it dispatches to the command bus.

### Frontend (TypeScript/Vue 3)

**Bridge layer** (`resources/js/bridge/`) — `api.ts` is the fetch wrapper; `commands.ts` has typed functions for each backend command. All backend communication goes through here.

**Pinia stores** (`resources/js/stores/`) — Five stores: `editor` (play state, theme), `scene` (entities, dirty flag), `selection` (selected entity/component), `project` (manifest), `components` (registry cache with category grouping).

**Inspector field editors** (`resources/js/components/inspector/fields/`) — Each property type (string, int, float, bool, vec2, vec3, color, angle, slider, asset) has a dedicated Vue component. New ECS property types need a corresponding field editor.

**Layout** — EditorLayout is a CSS grid: hierarchy panel (left), scene view + asset browser (center), inspector (right), toolbar (top).

### Data Flow

```
Vue Component → Pinia Store → Bridge Command → POST /api/editor/command
→ EditorCommandBus → Command::execute(EditorContext) → SceneDocument
→ JSON response → Store update → UI re-render
```

### Project Files

Game projects use `phpolygon.project.json` as their manifest. The editor reads PSR-4 roots from this manifest to discover project-specific components alongside engine built-ins.

## Conventions

- PHP 8.2+ with strict types. Autoloaded via PSR-4.
- TypeScript strict mode. Path alias `@` → `resources/js/`.
- Tailwind CSS with VS Code dark theme colors (defined in `tailwind.config.js`).
- Dark mode only (class-based).
- Indent: 4 spaces (2 for YAML). LF line endings.
- Tests for editor core logic live in `tests/Editor/`, mirroring `src/` structure.
