import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import type { EntityNode } from '@/types';
import * as commands from '@/bridge/commands';

export type SceneMode = '2d' | '3d';

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

function loadStoredMode(sceneName: string): SceneMode {
    if (!sceneName) return '3d';
    const stored = localStorage.getItem(modeStorageKey(sceneName));
    return stored === '2d' || stored === '3d' ? stored : '3d';
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
            mode.value = loadStoredMode(data.name);
            dirty.value = false;
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
        save,
        setMode,
        refreshHierarchy,
        createEntity,
        createPrimitive,
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
