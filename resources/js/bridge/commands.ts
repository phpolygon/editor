import { post } from './api';
import type {
    ComponentListResponse,
    SceneData,
    HierarchyResponse,
    ComponentSchemaDTO,
    EntityNode,
    MeshListResponse,
    MeshData,
    MaterialData,
} from '@/types';

function cmd<T = unknown>(command: string, args: Record<string, unknown> = {}): Promise<T> {
    return post<T>('/command', { command, args });
}

export function listComponents(grouped = true): Promise<ComponentListResponse> {
    return cmd<ComponentListResponse>('list_components', { grouped });
}

export function getComponentSchema(className: string): Promise<ComponentSchemaDTO> {
    return cmd<ComponentSchemaDTO>('get_component_schema', { className });
}

export function loadScene(sceneName: string): Promise<SceneData> {
    return cmd<SceneData>('load_scene', { sceneName });
}

/**
 * Expand the active scene's code-prefab references into real geometry for the
 * viewport (runs the game's headless expandCommand, caches the meshes/materials).
 * The document keeps the authored references; call this after loading or editing
 * a code-prefab scene to refresh the preview.
 */
export function expandScene(): Promise<SceneData & { expanded?: boolean }> {
    return cmd<SceneData & { expanded?: boolean }>('expand_scene', {});
}

export function saveScene(): Promise<{ saved: boolean }> {
    return cmd<{ saved: boolean }>('save_scene');
}

export function listScenes(): Promise<{ scenes: string[] }> {
    return cmd<{ scenes: string[] }>('list_scenes');
}

export function createScene(name: string): Promise<SceneData> {
    return cmd<SceneData>('create_scene', { name });
}

export interface CreatedProject {
    created: boolean;
    projectDir: string;
    name: string;
    namespace: string;
    slug: string;
    identifier: string;
    version: string;
    entryScene: string;
    bootClass: string;
    steam: { enabled: boolean; appId?: string };
    needsComposerInstall: boolean;
}

export interface SteamConfig {
    enabled: boolean;
    appId?: string;
    steamUser?: string;
    uploadTarget?: string;
    setLive?: string;
    depots?: { windows?: string; linux?: string; macos?: string };
}

export interface NewProjectOptions {
    namespace?: string;
    mode?: '2d' | '3d';
    engineConstraint?: string;
    identifier?: string;
    version?: string;
    sceneName?: string;
    width?: number;
    height?: number;
    extensions?: string[];
    threading?: boolean;
    steam?: SteamConfig;
}

/**
 * Scaffold a complete, buildable + runnable PHPolygon game project (manifest,
 * composer.json, build.json, game.php + boot class, starter scene, optional
 * Steam files) inside `parentDir`. Not opened and dependencies not installed —
 * follow up with `composer install` + openProject().
 */
export function createGameProject(
    parentDir: string,
    name: string,
    options: NewProjectOptions = {},
): Promise<CreatedProject> {
    return cmd<CreatedProject>('create_game_project', { parentDir, name, ...options });
}

/**
 * Run `composer install` inside a project directory to pull its dependencies.
 * Long-running + network-bound; editing does not require it.
 */
export function installProjectDependencies(
    projectDir: string,
): Promise<{ installed: boolean; exitCode: number; output: string }> {
    return cmd('install_project_dependencies', { projectDir });
}

/**
 * Build a game project into a standalone executable via the engine builder.
 * Long-running + network-bound (composer + runtime download + packaging).
 * `platform` empty = current host; variant 'base' | 'steam'.
 */
export function buildGamePackage(
    projectDir: string,
    options: { platform?: string; variant?: 'base' | 'steam'; phpVersion?: '8.4' | '8.5' } = {},
): Promise<{ built: boolean; exitCode: number; output: string; outputDir: string; variant: string; phpVersion: string }> {
    return cmd('build_game_package', { projectDir, ...options });
}

export function createEntity(
    name: string,
    parent: string | null = null,
): Promise<EntityNode> {
    return cmd<EntityNode>('create_entity', { name, parent });
}

export function deleteEntity(name: string): Promise<{ deleted: boolean }> {
    return cmd<{ deleted: boolean }>('delete_entity', { name });
}

export function addComponent(
    entity: string,
    component: string,
): Promise<{ added: boolean }> {
    return cmd<{ added: boolean }>('add_component', { entity, component });
}

export function removeComponent(
    entity: string,
    component: string,
): Promise<{ removed: boolean }> {
    return cmd<{ removed: boolean }>('remove_component', { entity, component });
}

export function updateProperty(
    entity: string,
    component: string,
    property: string,
    value: unknown,
): Promise<{ updated: boolean }> {
    return cmd<{ updated: boolean }>('update_property', {
        entity,
        component,
        property,
        value,
    });
}

export function getEntityHierarchy(): Promise<HierarchyResponse> {
    return cmd<HierarchyResponse>('get_entity_hierarchy');
}

export function undo(): Promise<{ undone: boolean }> {
    return cmd<{ undone: boolean }>('undo');
}

export function redo(): Promise<{ redone: boolean }> {
    return cmd<{ redone: boolean }>('redo');
}

export function renameEntity(
    oldName: string,
    newName: string,
): Promise<{ renamed: boolean }> {
    return cmd<{ renamed: boolean }>('rename_entity', { oldName, newName });
}

export function reparentEntity(
    entity: string,
    newParent: string | null,
): Promise<{ reparented: boolean }> {
    return cmd<{ reparented: boolean }>('reparent_entity', { entity, newParent });
}

export function listMeshes(): Promise<MeshListResponse> {
    return cmd<MeshListResponse>('list_meshes');
}

export function getMesh(id: string): Promise<MeshData> {
    return cmd<MeshData>('get_mesh', { id });
}

/** Every mesh in one response — preload the whole viewport with one request
 * instead of one get_mesh per unique mesh (the single-threaded dev server
 * serialises per-mesh round-trips into seconds for a big scene). */
export function getMeshes(): Promise<{ meshes: MeshData[] }> {
    return cmd<{ meshes: MeshData[] }>('get_meshes');
}

export function listMaterials(): Promise<{ materials: string[] }> {
    return cmd<{ materials: string[] }>('list_materials');
}

export function getMaterial(id: string): Promise<MaterialData> {
    return cmd<MaterialData>('get_material', { id });
}

/** Every material in one response — see {@see getMeshes}. */
export function getMaterials(): Promise<{ materials: MaterialData[] }> {
    return cmd<{ materials: MaterialData[] }>('get_materials');
}

/** Save an authored material (MaterialData) under assets/materials/. */
export function saveMaterial(
    material: MaterialData,
): Promise<{ saved: boolean; id: string; path: string; relativePath: string }> {
    return cmd('save_material', { material });
}

/** List materials saved to disk (assets/materials/), by id. */
export function listMaterialAssets(): Promise<{ materials: { id: string; path: string }[] }> {
    return cmd('list_material_assets', {});
}

/** Save a generated engine shader (vertex + fragment GLSL + authoring graph)
 * under assets/shaders/ as <name>.vert.glsl / <name>.frag.glsl. */
export function saveShader(
    name: string,
    vertex: string,
    fragment: string,
    graph: unknown,
): Promise<{ saved: boolean; name: string; vertexPath: string; fragmentPath: string; relativePath: string }> {
    return cmd('save_shader', { name, vertex, fragment, graph });
}

export function assetFileUrl(relativePath: string): string {
    return `/api/editor/assets/file?path=${encodeURIComponent(relativePath)}`;
}

// ── UI layout (widget-tree) editing ──────────────────────────────

export interface WidgetNode {
    _id: string;
    _widget: string;
    children?: WidgetNode[];
    [prop: string]: unknown;
}

export interface UiLayoutData {
    name: string;
    root: WidgetNode;
}

export type WidgetFieldKind =
    | 'string'
    | 'int'
    | 'float'
    | 'bool'
    | 'color'
    | 'edgeinsets'
    | 'sizing'
    | 'vec2'
    | 'rect'
    | 'other';

export interface WidgetField {
    name: string;
    kind: WidgetFieldKind;
    default: unknown;
}

export interface WidgetType {
    type: string;
    class: string;
    container: boolean;
    schema: WidgetField[];
}

export function listUiLayouts(): Promise<{ layouts: string[] }> {
    return cmd('list_ui_layouts');
}

export function listWidgetTypes(): Promise<{ types: WidgetType[] }> {
    return cmd('list_widget_types');
}

export function createUiLayout(name: string, rootType = 'VBox'): Promise<UiLayoutData> {
    return cmd('create_ui_layout', { name, rootType });
}

export function loadUiLayout(name: string): Promise<UiLayoutData> {
    return cmd('load_ui_layout', { name });
}

export function saveUiLayout(): Promise<{ saved: string; name: string }> {
    return cmd('save_ui_layout');
}

export function addWidget(parentId: string, type: string): Promise<UiLayoutData & { added: string }> {
    return cmd('add_widget', { parentId, type });
}

export function removeWidget(id: string): Promise<UiLayoutData> {
    return cmd('remove_widget', { id });
}

export function reparentWidget(id: string, newParentId: string, index: number | null = null): Promise<UiLayoutData> {
    return cmd('reparent_widget', { id, newParentId, index });
}

export function updateWidgetProperty(id: string, property: string, value: unknown): Promise<UiLayoutData> {
    return cmd('update_widget_property', { id, property, value });
}

export function transpileUiLayout(): Promise<{ path: string; className: string; php: string }> {
    return cmd('transpile_ui_layout');
}

/** Bind a widget property to a context path, or clear it with `path = null`. */
export function setWidgetBinding(id: string, property: string, path: string | null): Promise<UiLayoutData> {
    return cmd('set_widget_binding', { id, property, path });
}

/** Map a widget event to a context action, or remove it with `action = null`. */
export function setWidgetEvent(id: string, event: string, action: string | null): Promise<UiLayoutData> {
    return cmd('set_widget_event', { id, event, action });
}

/** One recorded draw call from the engine's real widget rendering. */
export interface WidgetPrimitive {
    op: string;
    [key: string]: unknown;
}

export interface WidgetRenderResult {
    width: number;
    height: number;
    primitives: WidgetPrimitive[];
}

/**
 * Render the active widget tree to a flat draw list via the engine's real
 * layout + draw, bound to placeholders (the WYSIWYG "unfilled panel" preview).
 */
export function renderUiLayout(width = 1280, height = 720): Promise<WidgetRenderResult> {
    return cmd('render_ui_layout', { width, height });
}

// ── Data-driven panel layouts (immediate-mode UI) ────────────────

export interface PanelElement {
    x?: number;
    y?: number;
    width?: number;
    height?: number;
    [prop: string]: unknown;
}

export interface PanelLayoutData {
    name: string;
    elements: Record<string, PanelElement>;
}

export function listPanelLayouts(): Promise<{ layouts: string[] }> {
    return cmd('list_panel_layouts');
}

export function createPanelLayout(name: string): Promise<PanelLayoutData> {
    return cmd('create_panel_layout', { name });
}

export function loadPanelLayout(name: string): Promise<PanelLayoutData> {
    return cmd('load_panel_layout', { name });
}

export function savePanelLayout(): Promise<{ saved: string; name: string }> {
    return cmd('save_panel_layout');
}

export function addLayoutElement(id: string, rect?: { x: number; y: number; width: number; height: number }): Promise<PanelLayoutData & { added: string }> {
    return cmd('add_layout_element', { id, ...(rect ?? {}) });
}

export function updateLayoutElement(id: string, props: Record<string, unknown>): Promise<PanelLayoutData> {
    return cmd('update_layout_element', { id, props });
}

export function renameLayoutElement(oldId: string, newId: string): Promise<PanelLayoutData> {
    return cmd('rename_layout_element', { oldId, newId });
}

export function removeLayoutElement(id: string): Promise<PanelLayoutData> {
    return cmd('remove_layout_element', { id });
}

export type PrimitiveType = 'box' | 'sphere' | 'cylinder' | 'plane';

export function createPrimitive(
    type: PrimitiveType,
    parent: string | null = null,
    name: string | null = null,
): Promise<{ created: string; parent: string | null; meshId: string; materialId: string }> {
    return cmd('create_primitive', { type, parent, name });
}

export function createSprite(
    parent: string | null = null,
    name: string | null = null,
): Promise<{ created: string; parent: string | null }> {
    return cmd('create_sprite', { parent, name });
}

export function savePrefab(
    entityName: string,
    prefabName: string | null = null,
): Promise<{ saved: boolean; name: string; path: string; relativePath: string }> {
    return cmd('save_prefab', { entityName, prefabName });
}

/** Save a generated WAV (base64, no data-URL prefix needed) to assets/audio/. */
export function saveAudio(
    name: string,
    data: string,
): Promise<{ saved: boolean; name: string; path: string; relativePath: string }> {
    return cmd('save_audio', { name, data });
}

/** Save an imported texture (PNG base64 or data URL) to assets/textures/. The
 * returned relativePath is what a material's `albedoTexture` references. */
export function saveTexture(
    name: string,
    data: string,
): Promise<{ saved: boolean; name: string; path: string; relativePath: string }> {
    return cmd('save_texture', { name, data });
}

export function spawnPrefab(
    path: string,
    parent: string | null = null,
): Promise<{ spawned: string; parent: string | null }> {
    return cmd('spawn_prefab', { path, parent });
}

/**
 * Place a CODE prefab as a REFERENCE: one entity carrying `prefab` (class) plus
 * authored override components (e.g. a design-variant component + Transform3D)
 * rather than inlined geometry. The game regenerates the geometry from the
 * prefab's build() on load.
 */
export function spawnCodePrefab(
    prefabClass: string,
    options: {
        name?: string;
        parent?: string | null;
        components?: { _class: string; [prop: string]: unknown }[];
    } = {},
): Promise<{ spawned: string; prefab: string; parent: string | null }> {
    return cmd('spawn_code_prefab', {
        class: prefabClass,
        name: options.name,
        parent: options.parent ?? null,
        components: options.components ?? [],
    });
}

export function listPrefabs(): Promise<{ prefabs: { name: string; path: string }[] }> {
    return cmd('list_prefabs', {});
}

/**
 * List the game's editor-placeable CODE prefabs (engine Prefab classes), as
 * opposed to file-based `assets/prefabs/*.prefab.json`. Sourced from the game's
 * `prefabsCommand` (project manifest); placing one spawns a prefab reference +
 * variant rather than inlined geometry.
 */
export interface CodePrefabEntry {
    name: string;
    class: string;
    variants?: string[];
    variantComponent?: string;
    variantProperty?: string;
}

export function listCodePrefabs(): Promise<{ prefabs: CodePrefabEntry[] }> {
    return cmd('list_code_prefabs', {});
}

export function evaluateProceduralMesh(
    nodes: unknown[],
    output: string,
    meshId = '',
): Promise<MeshData> {
    return cmd<MeshData>('evaluate_procedural_mesh', { nodes, output, meshId });
}

/** Raw baked geometry (flat arrays) — the storage form for edited/imported meshes. */
export interface RawMeshPayload {
    vertices: number[];
    normals: number[];
    uvs: number[];
    indices: number[];
}

/** Save a procedural-mesh graph as a reusable asset under assets/meshes/. */
export function saveMesh(
    name: string,
    nodes: unknown[],
    output: string,
): Promise<{ saved: boolean; name: string; path: string; relativePath: string }> {
    return cmd('save_mesh', { name, nodes, output });
}

/** Save baked raw geometry (vertex-edited or imported) as a mesh asset. */
export function saveRawMesh(
    name: string,
    raw: RawMeshPayload,
): Promise<{ saved: boolean; name: string; path: string; relativePath: string }> {
    return cmd('save_mesh', { name, raw });
}

export function listMeshAssets(): Promise<{ meshes: { name: string; path: string }[] }> {
    return cmd('list_mesh_assets', {});
}

export function loadMeshAsset(
    name: string,
): Promise<{ name: string; nodes: unknown[]; output: string; raw: RawMeshPayload | null }> {
    return cmd('load_mesh_asset', { name });
}

export function deleteMeshAsset(name: string): Promise<{ deleted: boolean; name: string }> {
    return cmd('delete_mesh_asset', { name });
}

export function renameMeshAsset(
    name: string,
    newName: string,
): Promise<{ renamed: boolean; name: string; path: string }> {
    return cmd('rename_mesh_asset', { name, newName });
}
