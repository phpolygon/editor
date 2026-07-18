<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { listCodePrefabs, type CodePrefabEntry } from '@/bridge/commands';
import { useSceneStore } from '@/stores/scene';
import { useSelectionStore } from '@/stores/selection';

const sceneStore = useSceneStore();
const selectionStore = useSelectionStore();

const prefabs = ref<CodePrefabEntry[]>([]);
const loading = ref(false);
const busy = ref<string | null>(null);
// Chosen design variant per prefab class.
const selectedVariant = ref<Record<string, string>>({});

async function refresh() {
    loading.value = true;
    try {
        prefabs.value = (await listCodePrefabs()).prefabs;
        for (const entry of prefabs.value) {
            if (entry.variants?.length && !(entry.class in selectedVariant.value)) {
                selectedVariant.value[entry.class] = entry.variants[0];
            }
        }
    } catch {
        prefabs.value = [];
    } finally {
        loading.value = false;
    }
}

async function spawn(entry: CodePrefabEntry) {
    busy.value = entry.class;
    try {
        const components: { _class: string; [prop: string]: unknown }[] = [];

        // Author the chosen design variant on the prefab's variant component.
        const variant = selectedVariant.value[entry.class];
        if (entry.variantComponent && variant) {
            components.push({
                _class: entry.variantComponent,
                [entry.variantProperty ?? 'variant']: variant,
            });
        }
        // A placement transform at the origin (the user moves it afterwards).
        components.push({
            _class: 'PHPolygon\\Component\\Transform3D',
            position: { x: 0, y: 0, z: 0 },
        });

        // Spawns a prefab reference + authored overrides, then expands the
        // geometry preview (sceneStore.spawnCodePrefab).
        await sceneStore.spawnCodePrefab(entry.class, {
            name: entry.name,
            parent: selectionStore.selectedEntity,
            components,
        });
    } finally {
        busy.value = null;
    }
}

onMounted(refresh);
</script>

<template>
    <div class="text-xs" data-testid="code-prefab-browser">
        <div class="flex items-center h-6 px-2 bg-editor-active">
            <span class="flex-1 font-medium">Game Prefabs</span>
            <button
                class="text-editor-muted hover:text-editor-text"
                title="Refresh"
                data-testid="code-prefab-refresh"
                @click="refresh"
            >⟳</button>
        </div>

        <div v-if="loading" class="p-2 text-editor-muted">Loading…</div>
        <div
            v-else-if="prefabs.length === 0"
            class="p-2 text-editor-muted"
            data-testid="code-prefab-empty"
        >
            No code prefabs. The game exposes them via its prefabsCommand.
        </div>
        <ul v-else class="p-1">
            <li
                v-for="entry in prefabs"
                :key="entry.class"
                class="flex items-center gap-1 px-1 py-0.5 rounded hover:bg-editor-hover"
                :data-testid="`code-prefab-${entry.name}`"
            >
                <span class="flex-1 truncate" :title="entry.class">{{ entry.name }}</span>

                <select
                    v-if="entry.variants?.length"
                    v-model="selectedVariant[entry.class]"
                    class="bg-editor-input text-editor-text rounded px-1 py-0.5 max-w-[7rem]"
                    :data-testid="`code-prefab-variant-${entry.name}`"
                    @click.stop
                >
                    <option v-for="variant in entry.variants" :key="variant" :value="variant">
                        {{ variant }}
                    </option>
                </select>

                <button
                    class="text-editor-muted hover:text-editor-text px-1"
                    :title="`Place ${entry.name}`"
                    :data-testid="`code-prefab-spawn-${entry.name}`"
                    :disabled="busy === entry.class"
                    @click="spawn(entry)"
                >{{ busy === entry.class ? '…' : '＋' }}</button>
            </li>
        </ul>
    </div>
</template>
