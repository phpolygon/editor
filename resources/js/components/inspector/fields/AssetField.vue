<script setup lang="ts">
import { post } from '@/bridge/api';
import { useToast } from '@/composables/useToast';

defineProps<{
    label: string;
    modelValue: string;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: string];
}>();

const { addToast } = useToast();

function onInput(e: Event) {
    emit('update:modelValue', (e.target as HTMLInputElement).value);
}

async function browse() {
    try {
        const data = await post<{ path: string }>('/assets/browse');
        emit('update:modelValue', data.path);
    } catch (e: any) {
        if (e?.message !== 'No file selected') {
            addToast(e?.message ?? 'Browse failed', 'error');
        }
    }
}
</script>

<template>
    <div class="flex items-center gap-1 px-1 py-0.5">
        <label class="text-xs text-editor-muted w-20 shrink-0 truncate">{{ label }}</label>
        <input
            type="text"
            :value="modelValue"
            class="flex-1 bg-editor-input border border-editor-border text-editor-text text-sm px-1 py-0.5 rounded focus:border-editor-accent focus:outline-none w-0 min-w-0"
            @input="onInput"
        />
        <button
            class="text-xs px-1.5 py-0.5 rounded border border-editor-border hover:bg-editor-hover active:bg-editor-active shrink-0"
            @click="browse"
        >
            Browse
        </button>
    </div>
</template>
