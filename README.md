# PHPolygon Editor

A desktop scene editor for the [PHPolygon](https://github.com/phpolygon/phpolygon) game engine, built with Laravel, Vue 3, and NativePHP.

## Requirements

- PHP 8.2+
- Node.js 18+
- Composer
- The `phpolygon/phpolygon` engine package

## Setup

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
```

## Development

```bash
# Run everything (Laravel server + queue + Vite HMR)
composer dev

# Run as native desktop app
composer native:dev

# Frontend only
npm run dev
```

## Testing

```bash
# Laravel tests (Unit + Feature)
./vendor/bin/phpunit

# Editor-specific tests
./vendor/bin/phpunit tests/Editor/

# Single test
./vendor/bin/phpunit --filter test_create_entity
```

## Code Style

```bash
./vendor/bin/pint
```

## Architecture

The editor is split into two layers:

**Backend** (`src/`) — Framework-agnostic editor logic under the `PHPolygon\Editor` namespace:
- **Command Bus** — All operations (create entity, add component, save scene, undo/redo, etc.) go through `EditorCommandBus` as discrete command classes.
- **SceneDocument** — In-memory scene state with entity/component CRUD and undo/redo via JSON snapshots.
- **ComponentRegistry** — Discovers ECS components via PSR-4 scanning and PHP reflection.
- **InspectorMetadataExtractor** — Extracts property schemas (type, defaults, editor hints) from component classes to drive the dynamic inspector UI.

**Frontend** (`resources/js/`) — Vue 3 SPA with TypeScript:
- **Bridge** (`bridge/`) — Typed API wrappers that send commands to `POST /api/editor/command`.
- **Stores** (`stores/`) — Pinia stores for scene, selection, project, components, and editor state.
- **Inspector Fields** (`components/inspector/fields/`) — Per-type property editors (string, int, float, bool, vec2, vec3, color, angle, slider, asset).

Data flows: **Vue Component → Pinia Store → Bridge → API → CommandBus → SceneDocument → JSON response → Store → UI**.

## Project Files

The editor opens PHPolygon game projects that contain a `phpolygon.project.json` manifest. This manifest defines the project name, PSR-4 roots (for component discovery), scene and asset paths, and the entry scene.

## Tech Stack

- **Backend**: Laravel 11, PHP 8.2+
- **Frontend**: Vue 3 (Composition API), Pinia, TypeScript, Tailwind CSS
- **Desktop**: NativePHP (Electron)
- **Build**: Vite
