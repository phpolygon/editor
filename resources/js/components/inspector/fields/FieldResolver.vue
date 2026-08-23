<script setup lang="ts">
import { computed } from 'vue';
import { Undo2 } from 'lucide-vue-next';
import type { PropertySchemaDTO } from '@/types';
import { useSceneStore } from '@/stores/scene';
import { resolveFieldComponent } from './resolveFieldComponent';
import ObjectArrayField from './ObjectArrayField.vue';

const props = withDefaults(defineProps<{
    schema: PropertySchemaDTO;
    value: unknown;
    entityName: string;
    componentClass: string;
    /** This instance sets its own value here instead of the prefab's. */
    overridden?: boolean;
    /** The entity is a prefab instance, so reverting is possible at all. */
    revertable?: boolean;
}>(), {
    overridden: false,
    revertable: false,
});

const emit = defineEmits<{ revert: [] }>();

const sceneStore = useSceneStore();

// Arrays of nested #[Serializable] objects (element type declared via
// #[Property(type: ...)]) get the dedicated nested editor; everything else
// resolves to a leaf field.
const isObjectArray = computed(() => props.schema.type === 'array' && !!props.schema.elementType);

const fieldComponent = computed(() =>
    isObjectArray.value ? ObjectArrayField : resolveFieldComponent(props.schema),
);

const fieldProps = computed(() => {
    if (isObjectArray.value) {
        return {
            label: props.schema.name,
            modelValue: props.value ?? props.schema.default ?? [],
            elementType: props.schema.elementType,
        };
    }

    const base: Record<string, unknown> = {
        label: props.schema.name,
        modelValue: props.value ?? props.schema.default,
    };

    // The node-graph editor spans more than its own property (it also sets the
    // sibling `output`), so it needs the owning entity/component to reach them.
    if (props.schema.editorHint === 'nodegraph') {
        base.entityName = props.entityName;
        base.componentClass = props.componentClass;
    }

    if (props.schema.min !== undefined) base.min = props.schema.min;
    if (props.schema.max !== undefined) base.max = props.schema.max;
    if (props.schema.step !== undefined) base.step = props.schema.step;

    return base;
});

async function onUpdate(newValue: unknown) {
    await sceneStore.updateProperty(
        props.entityName,
        props.componentClass,
        props.schema.name,
        newValue,
    );
}
</script>

<template>
    <!-- On a prefab instance the revert slot is always reserved, visible only
         where there is something to revert — otherwise field widths would jump
         between overridden and inherited rows. -->
    <div v-if="revertable" class="flex items-start gap-1">
        <div
            class="flex-1 min-w-0"
            :class="overridden ? 'border-l-2 border-editor-accent pl-1.5 -ml-0.5' : 'pl-2'"
            :title="overridden ? 'Overridden on this instance' : 'Value comes from the prefab'"
        >
            <component
                :is="fieldComponent"
                v-bind="fieldProps"
                @update:model-value="onUpdate"
            />
        </div>
        <button
            class="mt-1 shrink-0 w-4 text-editor-muted hover:text-editor-text disabled:opacity-0"
            :disabled="!overridden"
            title="Revert to the prefab's value"
            @click="emit('revert')"
        >
            <Undo2 :size="11" />
        </button>
    </div>

    <component
        v-else
        :is="fieldComponent"
        v-bind="fieldProps"
        @update:model-value="onUpdate"
    />
</template>
