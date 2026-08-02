<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use PHPolygon\Editor\Command\AddComponentCommand;
use PHPolygon\Editor\Command\AddLayoutElementCommand;
use PHPolygon\Editor\Command\AddWidgetCommand;
use PHPolygon\Editor\Command\BuildGamePackageCommand;
use PHPolygon\Editor\Command\CreateEntityCommand;
use PHPolygon\Editor\Command\CreateGameProjectCommand;
use PHPolygon\Editor\Command\CreatePanelLayoutCommand;
use PHPolygon\Editor\Command\CreatePrimitiveCommand;
use PHPolygon\Editor\Command\CreateSceneCommand;
use PHPolygon\Editor\Command\CreateSpriteCommand;
use PHPolygon\Editor\Command\CreateUiLayoutCommand;
use PHPolygon\Editor\Command\DeleteEntityCommand;
use PHPolygon\Editor\Command\BakeTerrainMeshCommand;
use PHPolygon\Editor\Command\CreateTerrainEntityCommand;
use PHPolygon\Editor\Command\DeleteMeshAssetCommand;
use PHPolygon\Editor\Command\DeleteTerrainAssetCommand;
use PHPolygon\Editor\Command\ListTerrainAssetsCommand;
use PHPolygon\Editor\Command\LoadTerrainCommand;
use PHPolygon\Editor\Command\EditorCommandBus;
use PHPolygon\Editor\Command\EvaluateProceduralMeshCommand;
use PHPolygon\Editor\Command\ExpandSceneCommand;
use PHPolygon\Editor\Command\GetComponentSchemaCommand;
use PHPolygon\Editor\Command\GetEntityHierarchyCommand;
use PHPolygon\Editor\Command\GetMaterialCommand;
use PHPolygon\Editor\Command\GetMaterialsCommand;
use PHPolygon\Editor\Command\GetMeshCommand;
use PHPolygon\Editor\Command\GetMeshesCommand;
use PHPolygon\Editor\Command\InstallProjectDependenciesCommand;
use PHPolygon\Editor\Command\ListCodePrefabsCommand;
use PHPolygon\Editor\Command\ListComponentsCommand;
use PHPolygon\Editor\Command\ListMaterialAssetsCommand;
use PHPolygon\Editor\Command\ListMaterialsCommand;
use PHPolygon\Editor\Command\ListMeshAssetsCommand;
use PHPolygon\Editor\Command\ListMeshesCommand;
use PHPolygon\Editor\Command\ListPanelLayoutsCommand;
use PHPolygon\Editor\Command\ListPrefabsCommand;
use PHPolygon\Editor\Command\ListScenesCommand;
use PHPolygon\Editor\Command\ListUiLayoutsCommand;
use PHPolygon\Editor\Command\ListWidgetTypesCommand;
use PHPolygon\Editor\Command\LoadMeshAssetCommand;
use PHPolygon\Editor\Command\LoadPanelLayoutCommand;
use PHPolygon\Editor\Command\LoadSceneCommand;
use PHPolygon\Editor\Command\LoadUiLayoutCommand;
use PHPolygon\Editor\Command\RedoCommand;
use PHPolygon\Editor\Command\RemoveComponentCommand;
use PHPolygon\Editor\Command\RemoveLayoutElementCommand;
use PHPolygon\Editor\Command\RemoveWidgetCommand;
use PHPolygon\Editor\Command\RenameEntityCommand;
use PHPolygon\Editor\Command\RenameLayoutElementCommand;
use PHPolygon\Editor\Command\RenameMeshAssetCommand;
use PHPolygon\Editor\Command\RenderUiLayoutCommand;
use PHPolygon\Editor\Command\ReparentEntityCommand;
use PHPolygon\Editor\Command\ReparentWidgetCommand;
use PHPolygon\Editor\Command\SaveAudioCommand;
use PHPolygon\Editor\Command\SaveMaterialCommand;
use PHPolygon\Editor\Command\SaveMeshCommand;
use PHPolygon\Editor\Command\SavePanelLayoutCommand;
use PHPolygon\Editor\Command\SavePrefabCommand;
use PHPolygon\Editor\Command\SaveSceneCommand;
use PHPolygon\Editor\Command\SaveShaderCommand;
use PHPolygon\Editor\Command\SaveTerrainCommand;
use PHPolygon\Editor\Command\SaveTextureCommand;
use PHPolygon\Editor\Command\SaveUiLayoutCommand;
use PHPolygon\Editor\Command\SetWidgetBindingCommand;
use PHPolygon\Editor\Command\SetWidgetEventCommand;
use PHPolygon\Editor\Command\SpawnCodePrefabCommand;
use PHPolygon\Editor\Command\SpawnPrefabCommand;
use PHPolygon\Editor\Command\TranspileUiLayoutCommand;
use PHPolygon\Editor\Command\UndoCommand;
use PHPolygon\Editor\Command\UpdateLayoutElementCommand;
use PHPolygon\Editor\Command\UpdatePropertyCommand;
use PHPolygon\Editor\Command\UpdateWidgetPropertyCommand;
use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Project\ProjectAutoloader;
use PHPolygon\Editor\Project\ProjectLoader;
use PHPolygon\Editor\Project\ProjectManifest;
use PHPolygon\Editor\Project\RecentProjects;
use PHPolygon\Editor\Registry\ComponentRegistry;
use PHPolygon\Editor\Registry\SystemRegistry;
use PHPolygon\Scene\Transpiler\SceneTranspiler;

class EditorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The MCP stdio server (`php artisan mcp:start editor`) is a long-lived
        // console process with no HTTP session. The editor's project/document
        // state is session-backed, so switch to an in-memory array store that
        // stays consistent for the lifetime of that process. HTTP requests keep
        // their configured driver.
        if ($this->app->runningInConsole() && in_array('mcp:start', $_SERVER['argv'] ?? [], true)) {
            config(['session.driver' => 'array']);
        }

        $this->app->singleton(ComponentRegistry::class);
        $this->app->singleton(SystemRegistry::class);
        $this->app->singleton(SceneTranspiler::class);
        $this->app->singleton(ProjectLoader::class);
        $this->app->singleton(ProjectAutoloader::class);
        $this->app->singleton(
            RecentProjects::class,
            fn () => new RecentProjects(storage_path('app/recent-projects.json')),
        );

        $this->app->singleton(EditorContext::class, function ($app) {
            $projectDir = session('editor_project_dir', '');
            $manifest = new ProjectManifest(
                name: 'Untitled',
                version: '0.0.0',
                engineVersion: '*',
                scenesPath: 'src/Scene',
                assetsPath: 'assets',
                psr4Roots: [],
                entryScene: '',
            );

            if ($projectDir && file_exists($projectDir.'/phpolygon.project.json')) {
                $manifest = $app->make(ProjectLoader::class)->load($projectDir);
            }

            // Make the project's own classes (scene base classes, components,
            // systems) loadable in the editor process — required before any
            // command require_once's a scene that extends a project base class.
            if ($projectDir) {
                $app->make(ProjectAutoloader::class)->register($projectDir, $manifest->psr4Roots);

                // Populate the component registry on every request, not only on
                // open. Web mode rebuilds the container per request, so without
                // this the inspector's get_component_schema / a freshly loaded
                // scene would see an empty registry and render nothing editable.
                // (NativePHP keeps one long-lived process, so its registry would
                // already persist — this simply makes web mode behave the same.)
                $registry = $app->make(ComponentRegistry::class);
                foreach ($manifest->psr4Roots as $namespace => $path) {
                    $fullPath = $projectDir.DIRECTORY_SEPARATOR.$path;
                    if (is_dir($fullPath)) {
                        $registry->scan($fullPath, $namespace);
                    }
                }
                $engineComponents = base_path('vendor/phpolygon/phpolygon/src/Component');
                if (is_dir($engineComponents)) {
                    $registry->scan($engineComponents, 'PHPolygon\\Component\\');
                }
            }

            $ctx = new EditorContext(
                manifest: $manifest,
                components: $app->make(ComponentRegistry::class),
                systems: $app->make(SystemRegistry::class),
                transpiler: $app->make(SceneTranspiler::class),
                projectDir: $projectDir,
            );

            // Session-backed persistence so the active scene survives
            // between stateless HTTP requests (NativePHP keeps the process
            // alive and would not need this).
            $ctx->loadDocumentArray = static function (): ?array {
                $stored = session('editor_active_document');

                return is_array($stored) ? $stored : null;
            };
            $ctx->persistDocumentArray = static function (?array $data): void {
                if ($data === null) {
                    session()->forget('editor_active_document');
                } else {
                    session(['editor_active_document' => $data]);
                }
            };

            // Same session-backed persistence for the active UI layout.
            $ctx->loadWidgetArray = static function (): ?array {
                $stored = session('editor_active_ui');

                return is_array($stored) ? $stored : null;
            };
            $ctx->persistWidgetArray = static function (?array $data): void {
                if ($data === null) {
                    session()->forget('editor_active_ui');
                } else {
                    session(['editor_active_ui' => $data]);
                }
            };

            // Same session-backed persistence for the active panel layout.
            $ctx->loadPanelLayoutArray = static function (): ?array {
                $stored = session('editor_active_panel_layout');

                return is_array($stored) ? $stored : null;
            };
            $ctx->persistPanelLayoutArray = static function (?array $data): void {
                if ($data === null) {
                    session()->forget('editor_active_panel_layout');
                } else {
                    session(['editor_active_panel_layout' => $data]);
                }
            };

            return $ctx;
        });

        $this->app->singleton(EditorCommandBus::class, function ($app) {
            $bus = new EditorCommandBus($app->make(EditorContext::class));

            $bus->register('list_components', ListComponentsCommand::class);
            $bus->register('get_component_schema', GetComponentSchemaCommand::class);
            $bus->register('load_scene', LoadSceneCommand::class);
            $bus->register('save_scene', SaveSceneCommand::class);
            $bus->register('list_scenes', ListScenesCommand::class);
            $bus->register('create_game_project', CreateGameProjectCommand::class);
            $bus->register('install_project_dependencies', InstallProjectDependenciesCommand::class);
            $bus->register('build_game_package', BuildGamePackageCommand::class);
            $bus->register('create_scene', CreateSceneCommand::class);
            $bus->register('create_entity', CreateEntityCommand::class);
            $bus->register('delete_entity', DeleteEntityCommand::class);
            $bus->register('add_component', AddComponentCommand::class);
            $bus->register('remove_component', RemoveComponentCommand::class);
            $bus->register('update_property', UpdatePropertyCommand::class);
            $bus->register('get_entity_hierarchy', GetEntityHierarchyCommand::class);
            $bus->register('undo', UndoCommand::class);
            $bus->register('redo', RedoCommand::class);
            $bus->register('rename_entity', RenameEntityCommand::class);
            $bus->register('reparent_entity', ReparentEntityCommand::class);
            $bus->register('list_meshes', ListMeshesCommand::class);
            $bus->register('get_mesh', GetMeshCommand::class);
            $bus->register('get_meshes', GetMeshesCommand::class);
            $bus->register('list_materials', ListMaterialsCommand::class);
            $bus->register('get_material', GetMaterialCommand::class);
            $bus->register('get_materials', GetMaterialsCommand::class);
            $bus->register('save_material', SaveMaterialCommand::class);
            $bus->register('list_material_assets', ListMaterialAssetsCommand::class);
            $bus->register('save_shader', SaveShaderCommand::class);
            $bus->register('create_primitive', CreatePrimitiveCommand::class);
            $bus->register('create_sprite', CreateSpriteCommand::class);
            $bus->register('save_prefab', SavePrefabCommand::class);
            $bus->register('spawn_prefab', SpawnPrefabCommand::class);
            $bus->register('spawn_code_prefab', SpawnCodePrefabCommand::class);
            $bus->register('list_prefabs', ListPrefabsCommand::class);
            $bus->register('list_code_prefabs', ListCodePrefabsCommand::class);
            $bus->register('expand_scene', ExpandSceneCommand::class);
            $bus->register('evaluate_procedural_mesh', EvaluateProceduralMeshCommand::class);

            // Audio (wave synthesizer)
            $bus->register('save_audio', SaveAudioCommand::class);

            // Textures (baked out during mesh import)
            $bus->register('save_texture', SaveTextureCommand::class);

            // Mesh assets (mesh editor)
            $bus->register('save_mesh', SaveMeshCommand::class);
            $bus->register('list_mesh_assets', ListMeshAssetsCommand::class);
            $bus->register('load_mesh_asset', LoadMeshAssetCommand::class);
            $bus->register('delete_mesh_asset', DeleteMeshAssetCommand::class);
            $bus->register('rename_mesh_asset', RenameMeshAssetCommand::class);

            // Terrain assets (terrain editor)
            $bus->register('save_terrain', SaveTerrainCommand::class);
            $bus->register('load_terrain', LoadTerrainCommand::class);
            $bus->register('list_terrain_assets', ListTerrainAssetsCommand::class);
            $bus->register('delete_terrain_asset', DeleteTerrainAssetCommand::class);
            $bus->register('bake_terrain_mesh', BakeTerrainMeshCommand::class);
            $bus->register('create_terrain_entity', CreateTerrainEntityCommand::class);

            // UI layout (widget-tree) editing
            $bus->register('list_ui_layouts', ListUiLayoutsCommand::class);
            $bus->register('list_widget_types', ListWidgetTypesCommand::class);
            $bus->register('create_ui_layout', CreateUiLayoutCommand::class);
            $bus->register('load_ui_layout', LoadUiLayoutCommand::class);
            $bus->register('save_ui_layout', SaveUiLayoutCommand::class);
            $bus->register('add_widget', AddWidgetCommand::class);
            $bus->register('remove_widget', RemoveWidgetCommand::class);
            $bus->register('reparent_widget', ReparentWidgetCommand::class);
            $bus->register('update_widget_property', UpdateWidgetPropertyCommand::class);
            $bus->register('set_widget_binding', SetWidgetBindingCommand::class);
            $bus->register('set_widget_event', SetWidgetEventCommand::class);
            $bus->register('transpile_ui_layout', TranspileUiLayoutCommand::class);
            $bus->register('render_ui_layout', RenderUiLayoutCommand::class);

            // Data-driven panel layouts (immediate-mode UI)
            $bus->register('list_panel_layouts', ListPanelLayoutsCommand::class);
            $bus->register('create_panel_layout', CreatePanelLayoutCommand::class);
            $bus->register('load_panel_layout', LoadPanelLayoutCommand::class);
            $bus->register('save_panel_layout', SavePanelLayoutCommand::class);
            $bus->register('add_layout_element', AddLayoutElementCommand::class);
            $bus->register('update_layout_element', UpdateLayoutElementCommand::class);
            $bus->register('rename_layout_element', RenameLayoutElementCommand::class);
            $bus->register('remove_layout_element', RemoveLayoutElementCommand::class);

            return $bus;
        });
    }

    public function boot(): void
    {
        // Editor operations on a real game project are memory-heavy: running a
        // whole-world scene build() in-process, and json_decode()-ing the large
        // per-project mesh/material asset cache on every get_mesh/get_material
        // request. Both blow the default 128M memory_limit for big scenes (e.g.
        // "Allowed memory size exhausted" in ProjectAssetCache::read). The game
        // itself runs these builds with the CLI's unbounded limit, so lift ours
        // for every editor request rather than only on the engine-boot path.
        if (ini_get('memory_limit') !== '-1') {
            ini_set('memory_limit', '-1');
        }

        // Point the project-scaffolding install step (InstallProjectDependencies
        // / composer install) at a bundled composer.phar when one ships in bin/,
        // so the packaged editor works without a system-wide Composer. In dev the
        // command falls back to the system `composer`, so this stays optional.
        if (getenv('PHPOLYGON_COMPOSER_PHAR') === false) {
            $phar = base_path('bin/composer.phar');
            if (is_file($phar)) {
                putenv('PHPOLYGON_COMPOSER_PHAR='.$phar);
            }
        }
    }
}
