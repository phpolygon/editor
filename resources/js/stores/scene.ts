import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import type { EntityNode } from '@/types';
import * as commands from '@/bridge/commands';
import { useProjectStore } from '@/stores/project';

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
    const entities = ref<EntityNode[]>([]);
    const dirty = ref(false);
    const loading = ref(false);
    const sceneList = ref<string[]>([]);
    const mode = ref<SceneMode>('3d');

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
            name.value = data.name;
            entities.value = normaliseEntities(data.entities);
            mode.value = resolveMode(data.name, entities.value);
            dirty.value = false;
        } finally {
            loading.value = false;
        }
    }

    async function createScene(sceneName: string) {
        loading.value = true;
        try {
            const data = await commands.createScene(sceneName);
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

    async function save() {
        await commands.saveScene();
        dirty.value = false;
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
        entities,
        dirty,
        loading,
        sceneList,
        mode,
        entityCount,
        findEntity,
        fetchSceneList,
        load,
        createScene,
        save,
        setMode,
        refreshHierarchy,
        createEntity,
        createPrimitive,
        createSprite,
        savePrefab,
        spawnPrefab,
        deleteEntity,
        addComponent,
        removeComponent,
        updateProperty,
        renameEntity,
        reparentEntity,
        undoAction,
        redoAction,
    };
});
