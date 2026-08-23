import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import type { EntityNode } from '@/types';
import * as commands from '@/bridge/commands';
import { useProjectStore } from '@/stores/project';
import { preloadMeshes } from '@/three/meshCache';
import { preloadMaterials } from '@/three/materialCache';

export type SceneMode = '2d' | '3d';

// Component class-name fragments that betray a scene's dimensionality. Used
// only as a fallback when the user has no saved per-scene preference.
const THREE_D_HINTS = ['Transform3D', 'MeshRenderer', 'Camera3D', 'DirectionalLight', 'PointLight', 'CharacterController3D'];
const TWO_D_HINTS = ['Transform2D', 'SpriteRenderer', 'Camera2D', 'Collider2D', 'RigidBody2D'];

function shortName(componentClass: string): string {
    const parts = componentClass.split('\\');
    return parts[parts.length - 1] ?? componentClass;
}

/**
 * Guess a scene's mode from the components its entities use. Returns null when
 * the scene has no dimensional signal (e.g. a UI-only scene with no entities),
 * leaving the decision to the project default.
 */
function inferMode(nodes: EntityNode[]): SceneMode | null {
    let has3d = false;
    let has2d = false;

    const walk = (list: EntityNode[]) => {
        for (const node of list) {
            for (const comp of node.components) {
                const name = shortName(comp._class);
                if (THREE_D_HINTS.some((h) => name.includes(h))) has3d = true;
                if (TWO_D_HINTS.some((h) => name.includes(h))) has2d = true;
            }
            walk(node.children);
        }
    };
    walk(nodes);

    if (has3d && !has2d) return '3d';
    if (has2d && !has3d) return '2d';
    return null;
}

// Backend omits empty children arrays from serialised scenes; normalise once
// at the boundary so every consumer (hierarchy panel, Three.js sync) can rely
// on entity.children being a real array.
function normaliseEntities(nodes: EntityNode[] | undefined): EntityNode[] {
    if (!Array.isArray(nodes)) return [];
    return nodes.map((n) => ({
        ...n,
        components: Array.isArray(n.components) ? n.components : [],
        children: normaliseEntities(n.children),
    }));
}

function modeStorageKey(sceneName: string): string {
    return `phpolygon-editor:scene-mode:${sceneName}`;
}

function storedMode(sceneName: string): SceneMode | null {
    if (!sceneName) return null;
    const stored = localStorage.getItem(modeStorageKey(sceneName));
    return stored === '2d' || stored === '3d' ? stored : null;
}

/**
 * Decide which viewport mode a freshly loaded scene opens in:
 *   1. an explicit per-scene preference the user previously toggled, else
 *   2. the mode inferred from the scene's own components, else
 *   3. the project's declared default (a 2D game opens in 2D).
 */
function resolveMode(sceneName: string, nodes: EntityNode[]): SceneMode {
    return storedMode(sceneName)
        ?? inferMode(nodes)
        ?? useProjectStore().defaultMode;
}

export const useSceneStore = defineStore('scene', () => {
    const name = ref<string>('');
    const sourceName = ref<string>('');
    const entities = ref<EntityNode[]>([]);
    // When the scene contains code-prefab REFERENCES, the authored `entities`
    // carry no geometry. `previewEntities` holds the expanded geometry (from the
    // game's build()) for the viewport; the hierarchy keeps the authored refs.
    const previewEntities = ref<EntityNode[] | null>(null);
    const dirty = ref(false);
    const loading = ref(false);
    const sceneList = ref<string[]>([]);
    const mode = ref<SceneMode>('3d');

    /**
     * The prefab class currently open for editing, if any.
     *
     * While set, the document holds that prefab's contents rather than a scene:
     * saving regenerates the class, and the scene the user came from has to be
     * reopened afterwards. Null means an ordinary scene is open.
     */
    const editingPrefab = ref<string | null>(null);
    /** False when the open prefab was hand-edited and must not be regenerated. */
    const prefabWritable = ref(true);
    /** The scene to return to when prefab editing ends. */
    const prefabReturnScene = ref('');

    // The viewport renders the expanded geometry when a preview is available,
    // else the authored entities; the hierarchy always uses `entities`.
    const viewEntities = computed<EntityNode[]>(() => previewEntities.value ?? entities.value);

    const entityCount = computed(() => {
        let count = 0;
        const walk = (nodes: EntityNode[]) => {
            for (const n of nodes) {
                count++;
                walk(n.children);
            }
        };
        walk(entities.value);
        return count;
    });

    function findEntity(entityName: string, nodes?: EntityNode[]): EntityNode | null {
        for (const n of nodes ?? entities.value) {
            if (n.name === entityName) return n;
            const found = findEntity(entityName, n.children);
            if (found) return found;
        }
        return null;
    }

    async function fetchSceneList() {
        try {
            const data = await commands.listScenes();
            sceneList.value = data.scenes;
        } catch {
            sceneList.value = [];
        }
    }

    async function load(sceneName: string) {
        loading.value = true;
        try {
            const data = await commands.loadScene(sceneName);
            // Bulk-preload all geometry/materials in one request BEFORE the
            // entities land (which triggers the viewport sync), so its per-entity
            // loadMesh/loadMaterial calls hit the cache instead of firing one
            // request each — the single-threaded dev server would otherwise
            // serialise hundreds of round-trips into ~tens of seconds.
            await preloadAssets();
            clearPrefabState(); // loading a scene leaves prefab editing behind
            sourceName.value = sceneName; // the file basename we loaded from
            name.value = data.name;
            entities.value = normaliseEntities(data.entities);
            mode.value = resolveMode(data.name, entities.value);
            dirty.value = false;
            await expandPreview();
            // A code-prefab scene's expand may have produced fresh geometry.
            await preloadAssets();
        } finally {
            loading.value = false;
        }
    }

    /**
     * Fetch every mesh + material for the active project in one request each and
     * populate the viewport caches. Best-effort: on failure the viewport falls
     * back to lazy per-entity fetches.
     */
    async function preloadAssets() {
        try {
            const [meshes, materials] = await Promise.all([
                commands.getMeshes(),
                commands.getMaterials(),
            ]);
            preloadMeshes(meshes.meshes ?? []);
            preloadMaterials(materials.materials ?? []);
        } catch {
            // best-effort
        }
    }

    /**
     * Expand any code-prefab references in the active scene into real geometry
     * for the viewport (runs the game's headless expandCommand + caches assets).
     * A scene with no code prefabs clears the preview so the viewport falls back
     * to the authored entities.
     */
    async function expandPreview() {
        try {
            const data = await commands.expandScene();
            previewEntities.value = data.expanded ? normaliseEntities(data.entities) : null;
        } catch {
            previewEntities.value = null;
        }
    }

    async function spawnCodePrefab(
        prefabClass: string,
        options: {
            name?: string;
            parent?: string | null;
            components?: { _class: string; [prop: string]: unknown }[];
        } = {},
    ): Promise<string> {
        const result = await commands.spawnCodePrefab(prefabClass, options);
        await refreshHierarchy();
        dirty.value = true;
        await expandPreview();
        return result.spawned;
    }

    /**
     * Re-run the current scene's PHP build() and re-capture its meshes/materials
     * — for picking up code changes after editing the project's scene/builders.
     * Reloads by the file basename (data.name is the scene's internal getName(),
     * which isn't the file identifier the loader expects).
     */
    async function reload() {
        if (sourceName.value) {
            await load(sourceName.value);
        }
    }

    async function createScene(sceneName: string) {
        loading.value = true;
        try {
            const data = await commands.createScene(sceneName);
            clearPrefabState();
            name.value = data.name;
            entities.value = normaliseEntities(data.entities);
            mode.value = resolveMode(data.name, entities.value);
            dirty.value = false;
            await fetchSceneList();
        } finally {
            loading.value = false;
        }
    }

    function setMode(next: SceneMode) {
        mode.value = next;
        if (name.value) {
            localStorage.setItem(modeStorageKey(name.value), next);
        }
    }

    /**
     * Persist the scene. Returns the backend's warning when the saved file
     * could not carry some component values, so the caller can surface it —
     * silently losing an edited mesh graph or heightmap is worse than a save
     * that says what it dropped.
     *
     * While a prefab is open ({@link editingPrefab}) this writes the prefab
     * class instead: the document holds the prefab's contents, and saving it as
     * a scene would create a scene file nobody asked for.
     */
    async function save(): Promise<string | null> {
        const prefab = editingPrefab.value;
        if (prefab) return await savePrefabEdit(prefab);

        const result = await commands.saveScene();
        dirty.value = false;
        return result.warning ?? null;
    }

    /**
     * Export the scene with prefab references expanded into entities.
     *
     * Deliberately separate from {@link save}: the flattened file loses the
     * link back to the prefabs, so it is something you ask for, never what a
     * plain save does.
     */
    async function exportFlattened(): Promise<commands.SaveSceneResult> {
        if (editingPrefab.value) {
            throw new Error('Close the prefab before exporting a flattened scene.');
        }
        const result = await commands.saveScene({ flatten: true });
        dirty.value = false;
        return result;
    }

    async function refreshHierarchy() {
        const data = await commands.getEntityHierarchy();
        entities.value = normaliseEntities(data.entities);
    }

    async function createEntity(entityName: string, parent: string | null = null) {
        await commands.createEntity(entityName, parent);
        await refreshHierarchy();
        dirty.value = true;
    }

    async function createPrimitive(
        type: commands.PrimitiveType,
        parent: string | null = null,
    ): Promise<string> {
        const result = await commands.createPrimitive(type, parent);
        await refreshHierarchy();
        dirty.value = true;
        return result.created;
    }

    async function createSprite(parent: string | null = null): Promise<string> {
        const result = await commands.createSprite(parent);
        await refreshHierarchy();
        dirty.value = true;
        return result.created;
    }

    async function savePrefab(entityName: string, prefabName: string | null = null) {
        return await commands.savePrefab(entityName, prefabName);
    }

    /**
     * Save a subtree as a PHP prefab class the scene can reference.
     *
     * Writes into the project's source tree, not into the scene, so nothing
     * about the open document changes.
     */
    async function createPrefabClass(
        entityName: string,
        options: { className?: string; overwrite?: boolean } = {},
    ) {
        return await commands.createPrefabClass(entityName, options);
    }

    /**
     * Open a prefab's contents for editing, replacing whatever is open.
     *
     * The caller saves first — this discards the active document the same way
     * loading another scene does.
     */
    async function openPrefab(prefabClass: string) {
        loading.value = true;
        try {
            const session = await commands.loadPrefabClass(prefabClass);
            await preloadAssets();
            // Remember where to go back to before the document is replaced.
            if (!editingPrefab.value) prefabReturnScene.value = sourceName.value;
            editingPrefab.value = session.editingPrefab;
            prefabWritable.value = session.writable;
            sourceName.value = '';
            name.value = session.name;
            entities.value = normaliseEntities(session.entities);
            previewEntities.value = null;
            dirty.value = false;
        } finally {
            loading.value = false;
        }
    }

    /** Write the open prefab back out as its class. */
    async function savePrefabEdit(prefabClass: string): Promise<string | null> {
        const root = entities.value[0];
        if (!root) throw new Error('The prefab has no root entity to save.');

        const parts = prefabClass.split('\\');
        await commands.createPrefabClass(root.name, {
            className: parts[parts.length - 1],
            // The open session already established this file is ours to write;
            // refusing here would strand the user with unsaved edits.
            overwrite: true,
        });
        dirty.value = false;
        return null;
    }

    /**
     * Leave prefab editing and reopen the scene it was entered from.
     *
     * Returns false when there is nothing to go back to (the prefab was opened
     * without a scene loaded), so the caller can say so instead of leaving an
     * empty editor behind.
     */
    async function closePrefab(): Promise<boolean> {
        const back = prefabReturnScene.value;
        clearPrefabState();

        if (!back) return false;
        await load(back);

        return true;
    }

    /**
     * Forget that a prefab was open, without navigating.
     *
     * Kept separate from {@link closePrefab} because `load()` calls it: closing
     * loads a scene, and a scene load that closed by loading again would
     * recurse.
     */
    function clearPrefabState() {
        editingPrefab.value = null;
        prefabWritable.value = true;
        prefabReturnScene.value = '';
    }

    async function spawnPrefab(path: string, parent: string | null = null): Promise<string> {
        const result = await commands.spawnPrefab(path, parent);
        await refreshHierarchy();
        dirty.value = true;
        return result.spawned;
    }

    async function deleteEntity(entityName: string) {
        await commands.deleteEntity(entityName);
        await refreshHierarchy();
        dirty.value = true;
    }

    /** Copy entities next to the originals; returns the new names. */
    async function duplicateEntities(entityNames: string[]): Promise<string[]> {
        if (entityNames.length === 0) return [];
        const result = await commands.duplicateEntity(entityNames);
        await refreshHierarchy();
        dirty.value = true;
        await expandPreview();
        return result.duplicated;
    }

    /** Every entity name in the document, for pruning a stale selection. */
    function entityNames(): Set<string> {
        const names = new Set<string>();
        const walk = (nodes: EntityNode[]) => {
            for (const n of nodes) {
                names.add(n.name);
                walk(n.children);
            }
        };
        walk(entities.value);
        return names;
    }

    async function addComponent(entityName: string, component: string) {
        await commands.addComponent(entityName, component);
        await refreshHierarchy();
        dirty.value = true;
    }

    async function removeComponent(entityName: string, component: string) {
        await commands.removeComponent(entityName, component);
        await refreshHierarchy();
        dirty.value = true;
    }

    async function updateProperty(
        entityName: string,
        component: string,
        property: string,
        value: unknown,
    ) {
        await commands.updateProperty(entityName, component, property, value);
        // Optimistic: update local state
        const entity = findEntity(entityName);
        if (entity) {
            const comp = entity.components.find((c) => c._class === component);
            if (comp) {
                comp.properties[property] = value;
            }
        }
        dirty.value = true;
    }

    /**
     * Write several properties as ONE undoable step — what a gizmo drag needs,
     * since position/rotation/scale change together and per-property commands
     * would make a single ctrl+Z undo only part of the drag.
     */
    async function updateProperties(edits: commands.PropertyEdit[]) {
        if (edits.length === 0) return;
        await commands.updateProperties(edits);
        // Optimistic: mirror the same writes locally.
        for (const edit of edits) {
            const entity = findEntity(edit.entity);
            const comp = entity?.components.find((c) => c._class === edit.component);
            if (!comp) continue;
            for (const [property, value] of Object.entries(edit.properties)) {
                comp.properties[property] = value;
            }
        }
        dirty.value = true;
    }

    async function renameEntity(oldName: string, newName: string) {
        await commands.renameEntity(oldName, newName);
        await refreshHierarchy();
        dirty.value = true;
    }

    async function reparentEntity(entityName: string, newParent: string | null) {
        await commands.reparentEntity(entityName, newParent);
        await refreshHierarchy();
        dirty.value = true;
    }

    async function undoAction() {
        await commands.undo();
        await refreshHierarchy();
    }

    async function redoAction() {
        await commands.redo();
        await refreshHierarchy();
    }

    return {
        name,
        sourceName,
        entities,
        previewEntities,
        viewEntities,
        dirty,
        loading,
        sceneList,
        mode,
        editingPrefab,
        prefabWritable,
        entityCount,
        findEntity,
        expandPreview,
        spawnCodePrefab,
        fetchSceneList,
        load,
        reload,
        createScene,
        save,
        setMode,
        refreshHierarchy,
        createEntity,
        createPrimitive,
        createSprite,
        duplicateEntities,
        entityNames,
        savePrefab,
        createPrefabClass,
        openPrefab,
        closePrefab,
        exportFlattened,
        spawnPrefab,
        deleteEntity,
        addComponent,
        removeComponent,
        updateProperty,
        updateProperties,
        renameEntity,
        reparentEntity,
        undoAction,
        redoAction,
    };
});
