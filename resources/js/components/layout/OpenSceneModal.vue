<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import { Film, Search, Check } from 'lucide-vue-next';
import Modal from '@/components/ui/Modal.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import { useSceneStore } from '@/stores/scene';

/**
 * Scene load dialog: lists every scene in the open project, filterable, with the
 * currently loaded one marked. Picking a scene emits `select`; the parent owns
 * the actual load (and its unsaved-changes guard). Refreshes the list each time
 * it opens so newly created/saved scenes show up.
 */
const props = defineProps<{ modelValue: boolean }>();
const emit = defineEmits<{ 'update:modelValue': [boolean]; select: [string] }>();

const sceneStore = useSceneStore();
const query = ref('');
const searchInput = ref<HTMLInputElement | null>(null);

const filtered = computed(() => {
    const q = query.value.trim().toLowerCase();
    const list = sceneStore.sceneList;
    return q ? list.filter((s) => s.toLowerCase().includes(q)) : list;
});

watch(
    () => props.modelValue,
    async (open) => {
        if (!open) return;
        query.value = '';
        await sceneStore.fetchSceneList();
        await nextTick();
        searchInput.value?.focus();
    },
);

function pick(name: string) {
    emit('update:modelValue', false);
    emit('select', name);
}
</script>

<template>
    <Modal
        :model-value="modelValue"
        title="Open Scene"
        width="max-w-md"
        @update:model-value="emit('update:modelValue', $event)"
    >
        <div v-if="sceneStore.sceneList.length > 0" class="flex flex-col gap-3 -mt-1">
            <div class="relative">
                <Search
                    :size="15"
                    :stroke-width="2"
                    class="absolute left-2.5 top-1/2 -translate-y-1/2 text-editor-muted pointer-events-none"
                />
                <input
                    ref="searchInput"
                    v-model="query"
                    type="text"
                    placeholder="Search scenes…"
                    class="w-full h-9 pl-8 pr-2 rounded-md bg-editor-input border border-editor-border text-sm
                           focus:outline-none focus:border-editor-accent"
                />
            </div>

            <div class="flex flex-col gap-0.5 max-h-[50vh] overflow-y-auto -mx-1 px-1">
                <button
                    v-for="scene in filtered"
                    :key="scene"
                    class="group flex items-center gap-2.5 px-2.5 h-9 rounded-md text-left text-sm text-editor-text
                           hover:bg-editor-hover transition-colors"
                    :class="scene === sceneStore.sourceName ? 'bg-editor-hover' : ''"
                    @click="pick(scene)"
                >
                    <Film
                        :size="16"
                        :stroke-width="2"
                        class="shrink-0"
                        :class="scene === sceneStore.sourceName ? 'text-editor-accent' : 'text-editor-muted'"
                    />
                    <span class="truncate flex-1">{{ scene }}</span>
                    <span v-if="scene === sceneStore.sourceName" class="flex items-center gap-1 text-[11px] text-editor-accent">
                        <Check :size="13" :stroke-width="2.5" /> current
                    </span>
                </button>

                <p v-if="filtered.length === 0" class="px-2.5 py-4 text-center text-xs text-editor-muted">
                    No scenes match “{{ query }}”.
                </p>
            </div>
        </div>

        <EmptyState
            v-else
            :icon="Film"
            compact
            title="No scenes yet"
            hint="Create one with “New Scene” to get started."
        />
    </Modal>
</template>
