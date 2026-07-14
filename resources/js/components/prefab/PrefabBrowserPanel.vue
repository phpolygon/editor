<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { listPrefabs, spawnPrefab } from '@/bridge/commands';
import { useSceneStore } from '@/stores/scene';
import { useSelectionStore } from '@/stores/selection';

interface PrefabEntry {
    name: string;
    path: string;
}

const sceneStore = useSceneStore();
const selectionStore = useSelectionStore();

const prefabs = ref<PrefabEntry[]>([]);
const loading = ref(false);
const busy = ref<string | null>(null);

async function refresh() {
    loading.value = true;
    try {
        prefabs.value = (await listPrefabs()).prefabs;
    } catch {
        prefabs.value = [];
    } finally {
        loading.value = false;
    }
}

async function spawn(entry: PrefabEntry) {
    busy.value = entry.path;
    try {
        // Spawn under the current selection when there is one, else at scene root.
        await spawnPrefab(entry.path, selectionStore.selectedEntity);
        await sceneStore.refreshHierarchy();
    } finally {
        busy.value = null;
    }
}

onMounted(refresh);
</script>

<template>
    <div class="text-xs" data-testid="prefab-browser">
        <div class="flex items-center h-6 px-2 bg-editor-active">
            <span class="flex-1 font-medium">Prefabs</span>
            <button
                class="text-editor-muted hover:text-editor-text"
                title="Refresh"
                data-testid="prefab-refresh"
                @click="refresh"
            >⟳</button>
        </div>

        <div v-if="loading" class="p-2 text-editor-muted">Loading…</div>
        <div v-else-if="prefabs.length === 0" class="p-2 text-editor-muted" data-testid="prefab-empty">
            No prefabs yet. Select an entity and "Save as prefab".
        </div>
        <ul v-else class="p-1">
            <li
                v-for="entry in prefabs"
                :key="entry.path"
                class="flex items-center gap-1 px-1 py-0.5 rounded hover:bg-editor-hover cursor-pointer"
                :data-testid="`prefab-${entry.name}`"
                :title="`Spawn ${entry.name}`"
                @click="spawn(entry)"
            >
                <span class="flex-1 truncate">{{ entry.name }}</span>
                <span v-if="busy === entry.path" class="text-editor-muted">…</span>
            </li>
        </ul>
    </div>
</template>
