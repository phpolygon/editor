<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { listMaterials, listMaterialAssets } from '@/bridge/commands';

/**
 * Picker for a `materialId` property (editorHint `asset:material`). Offers every
 * material the editor knows about — runtime/snapshot ids (`list_materials`) plus
 * ones saved to disk (`list_material_assets`) — so a MeshRenderer can point at a
 * material authored in the material workspace instead of typing its id.
 */
const props = defineProps<{ label: string; modelValue: string }>();
const emit = defineEmits<{ 'update:modelValue': [value: string] }>();

const ids = ref<string[]>([]);

onMounted(async () => {
    const set = new Set<string>();
    try {
        (await listMaterials()).materials.forEach((m) => set.add(m));
    } catch {
        // ignore — offer whatever else we can
    }
    try {
        (await listMaterialAssets()).materials.forEach((m) => set.add(m.id));
    } catch {
        // ignore
    }
    if (props.modelValue) set.add(props.modelValue);
    ids.value = [...set].sort();
});
</script>

<template>
    <div class="flex items-center gap-1 px-1 py-0.5">
        <label class="text-xs text-editor-muted w-20 shrink-0 truncate">{{ label }}</label>
        <select
            :value="modelValue"
            class="flex-1 bg-editor-input border border-editor-border text-editor-text text-sm px-1 py-0.5 rounded
                   focus:border-editor-accent focus:outline-none w-0 min-w-0"
            @change="emit('update:modelValue', ($event.target as HTMLSelectElement).value)"
        >
            <option value="">— none —</option>
            <option v-for="id in ids" :key="id" :value="id">{{ id }}</option>
        </select>
    </div>
</template>
