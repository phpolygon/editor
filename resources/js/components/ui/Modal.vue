<script setup lang="ts">
import { watch, onUnmounted } from 'vue';
import { X } from 'lucide-vue-next';

/**
 * Generic modal shell: teleported to <body>, dim backdrop, centered panel.
 * Closes on Escape and backdrop click (unless `persistent`). Use `v-model` for
 * open state; slots: default (body), `#header`, `#footer`.
 */
const props = withDefaults(
    defineProps<{
        modelValue: boolean;
        title?: string;
        width?: string;
        persistent?: boolean;
    }>(),
    { width: 'max-w-md', persistent: false },
);

const emit = defineEmits<{ 'update:modelValue': [value: boolean] }>();

function close() {
    if (!props.persistent) emit('update:modelValue', false);
}

function onKey(e: KeyboardEvent) {
    if (e.key === 'Escape') close();
}

watch(
    () => props.modelValue,
    (open) => {
        if (open) window.addEventListener('keydown', onKey);
        else window.removeEventListener('keydown', onKey);
    },
);

onUnmounted(() => window.removeEventListener('keydown', onKey));
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition duration-150 ease-out"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition duration-100 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="modelValue"
                class="fixed inset-0 z-[100] flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm"
                @mousedown.self="close"
            >
                <div
                    class="w-full rounded-xl border border-editor-border bg-editor-panel shadow-2xl shadow-black/50
                           flex flex-col max-h-[85vh]"
                    :class="width"
                    role="dialog"
                    aria-modal="true"
                >
                    <div
                        v-if="title || $slots.header"
                        class="flex items-center justify-between gap-3 px-4 py-3 border-b border-editor-border"
                    >
                        <slot name="header">
                            <h2 class="text-sm font-semibold text-editor-text">{{ title }}</h2>
                        </slot>
                        <button
                            v-if="!persistent"
                            type="button"
                            class="text-editor-muted hover:text-editor-text transition-colors -mr-1"
                            aria-label="Close"
                            @click="close"
                        >
                            <X :size="18" :stroke-width="2" />
                        </button>
                    </div>

                    <div class="px-4 py-4 overflow-y-auto text-sm text-editor-text">
                        <slot />
                    </div>

                    <div
                        v-if="$slots.footer"
                        class="flex items-center justify-end gap-2 px-4 py-3 border-t border-editor-border"
                    >
                        <slot name="footer" />
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
