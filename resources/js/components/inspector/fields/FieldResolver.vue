<script setup lang="ts">
import { computed } from 'vue';
import type { PropertySchemaDTO } from '@/types';
import { useSceneStore } from '@/stores/scene';
import { resolveFieldComponent } from './resolveFieldComponent';
import ObjectArrayField from './ObjectArrayField.vue';

const props = defineProps<{
    schema: PropertySchemaDTO;
    value: unknown;
    entityName: string;
    componentClass: string;
}>();

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
    <component
        :is="fieldComponent"
        v-bind="fieldProps"
        @update:model-value="onUpdate"
    />
</template>
