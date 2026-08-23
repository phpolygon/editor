<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import { Undo2 } from 'lucide-vue-next';
import type { ComponentData, ComponentSchemaDTO } from '@/types';
import { useSceneStore } from '@/stores/scene';
import { useComponentsStore } from '@/stores/components';
import FieldResolver from './fields/FieldResolver.vue';

const props = withDefaults(defineProps<{
    entityName: string;
    componentData: ComponentData;
    schema: ComponentSchemaDTO | null;
    /** Property names this instance overrides (prefab instances only). */
    overridden?: Set<string>;
    /** The component exists only because the prefab produces it. */
    inherited?: boolean;
    /** The entity is a prefab instance, so removal/revert differ. */
    fromPrefab?: boolean;
}>(), {
    overridden: () => new Set<string>(),
    inherited: false,
    fromPrefab: false,
});

const emit = defineEmits<{ revert: [property?: string] }>();

const sceneStore = useSceneStore();
const componentsStore = useComponentsStore();
const collapsed = ref(false);
const resolvedSchema = ref<ComponentSchemaDTO | null>(props.schema);

const shortName = computed(() => {
    const parts = props.componentData._class.split('\\');
    return parts[parts.length - 1];
});

const hasOverrides = computed(() => props.overridden.size > 0);

onMounted(async () => {
    if (!resolvedSchema.value) {
        resolvedSchema.value = await componentsStore.getSchema(props.componentData._class);
    }
});

async function removeComponent() {
    await sceneStore.removeComponent(props.entityName, props.componentData._class);
}
</script>

<template>
    <div class="border-b border-editor-border">
        <!-- Header -->
        <div
            class="flex items-center h-6 px-2 bg-editor-active cursor-pointer select-none"
            @click="collapsed = !collapsed"
        >
            <span class="text-xs text-editor-muted mr-1">
                {{ collapsed ? '▸' : '▾' }}
            </span>
            <!-- A marked component name answers "what did I customise here?"
                 without expanding every section. -->
            <span
                class="text-xs flex-1 truncate"
                :class="hasOverrides ? 'font-semibold text-editor-text' : 'font-medium'"
            >
                {{ shortName }}
            </span>
            <span
                v-if="inherited"
                class="mr-1 text-[10px] text-editor-muted"
                title="Comes from the prefab; edit a value to override it"
            >
                prefab
            </span>
            <button
                v-if="hasOverrides"
                class="text-editor-muted hover:text-editor-text px-1"
                title="Revert this component to the prefab"
                @click.stop="emit('revert')"
            >
                <Undo2 :size="12" />
            </button>
            <!-- Removing an inherited component would not stick: the prefab
                 recreates it on the next load. -->
            <button
                v-if="!inherited"
                class="text-xs text-editor-muted hover:text-red-400 px-1"
                title="Remove component"
                @click.stop="removeComponent"
            >
                X
            </button>
        </div>

        <!-- Properties -->
        <div v-if="!collapsed" class="p-1">
            <template v-if="resolvedSchema">
                <FieldResolver
                    v-for="prop in resolvedSchema.properties"
                    :key="prop.name"
                    :schema="prop"
                    :value="componentData.properties[prop.name]"
                    :entity-name="entityName"
                    :component-class="componentData._class"
                    :overridden="overridden.has(prop.name)"
                    :revertable="fromPrefab"
                    @revert="emit('revert', prop.name)"
                />
            </template>
            <div v-else class="text-xs text-editor-muted px-1">
                Loading schema...
            </div>
        </div>
    </div>
</template>
