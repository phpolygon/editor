<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import type { ComponentSchemaDTO, PropertySchemaDTO } from '@/types';
import { useComponentsStore } from '@/stores/components';
import { resolveFieldComponent } from './resolveFieldComponent';

/**
 * Editor for an array of nested #[Serializable] objects (e.g. Project.features
 * / Project.bugs). Each item is a collapsible sub-form driven by the element
 * type's component schema. Edits are applied to a copy and the whole array is
 * emitted upward, so the parent's updateProperty persists it in one go.
 */
const props = defineProps<{
    label: string;
    modelValue: unknown;
    elementType: string;
}>();

const emit = defineEmits<{ 'update:model-value': [value: unknown[]] }>();

const componentsStore = useComponentsStore();
const elementSchema = ref<ComponentSchemaDTO | null>(null);
const collapsed = ref(false);

const items = computed<Record<string, unknown>[]>(() =>
    Array.isArray(props.modelValue) ? (props.modelValue as Record<string, unknown>[]) : [],
);

const elementShortName = computed(() => props.elementType.split('\\').pop() ?? 'Item');

onMounted(async () => {
    if (props.elementType) {
        elementSchema.value = await componentsStore.getSchema(props.elementType);
    }
});

function updateField(index: number, name: string, value: unknown) {
    const next = items.value.map((item, i) => (i === index ? { ...item, [name]: value } : item));
    emit('update:model-value', next);
}

function addItem() {
    const schema = elementSchema.value;
    if (!schema) return;
    const item: Record<string, unknown> = { _class: props.elementType };
    for (const prop of schema.properties) {
        item[prop.name] = prop.default ?? null;
    }
    emit('update:model-value', [...items.value, item]);
}

function removeItem(index: number) {
    emit('update:model-value', items.value.filter((_, i) => i !== index));
}

function fieldProps(prop: PropertySchemaDTO, item: Record<string, unknown>) {
    return {
        label: prop.name,
        modelValue: item[prop.name] ?? prop.default,
    };
}
</script>

<template>
    <div class="border border-editor-border/60 rounded my-1">
        <div
            class="flex items-center gap-1 px-1 h-5 bg-editor-active/50 cursor-pointer select-none"
            @click="collapsed = !collapsed"
        >
            <span class="text-[10px] text-editor-muted">{{ collapsed ? '▸' : '▾' }}</span>
            <span class="text-xs flex-1 truncate">{{ label }} ({{ items.length }})</span>
            <button
                class="text-[10px] text-editor-muted hover:text-editor-accent px-1"
                :disabled="!elementSchema"
                :title="`Add ${elementShortName}`"
                @click.stop="addItem"
            >
                + {{ elementShortName }}
            </button>
        </div>

        <div v-if="!collapsed" class="p-1 space-y-1">
            <div
                v-for="(item, i) in items"
                :key="i"
                class="border border-editor-border/40 rounded p-1"
            >
                <div class="flex items-center gap-1 mb-1">
                    <span class="text-[10px] text-editor-muted flex-1">{{ elementShortName }} #{{ i }}</span>
                    <button
                        class="text-[10px] text-editor-muted hover:text-red-400 px-1"
                        title="Remove"
                        @click="removeItem(i)"
                    >
                        X
                    </button>
                </div>

                <template v-if="elementSchema">
                    <component
                        :is="resolveFieldComponent(prop)"
                        v-for="prop in elementSchema.properties"
                        :key="prop.name"
                        v-bind="fieldProps(prop, item)"
                        @update:model-value="(v: unknown) => updateField(i, prop.name, v)"
                    />
                </template>
                <div v-else class="text-[10px] text-editor-muted">Loading {{ elementShortName }} schema...</div>
            </div>

            <div v-if="items.length === 0" class="text-[10px] text-editor-muted px-1">
                (empty)
            </div>
        </div>
    </div>
</template>
