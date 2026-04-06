<script setup lang="ts">
import { useToast } from '@/composables/useToast';

const { toasts, removeToast } = useToast();

function toastClasses(type: string) {
    switch (type) {
        case 'success':
            return 'bg-editor-success text-white';
        case 'error':
            return 'bg-editor-danger text-white';
        default:
            return 'bg-editor-accent text-white';
    }
}
</script>

<template>
    <div class="fixed bottom-4 right-4 z-50 flex flex-col gap-2 pointer-events-none">
        <TransitionGroup
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="opacity-0 translate-y-2"
            enter-to-class="opacity-100 translate-y-0"
            leave-active-class="transition duration-150 ease-in"
            leave-from-class="opacity-100 translate-y-0"
            leave-to-class="opacity-0 translate-y-2"
        >
            <div
                v-for="toast in toasts"
                :key="toast.id"
                class="pointer-events-auto flex items-center gap-2 px-3 py-2 rounded shadow-lg text-xs max-w-xs"
                :class="toastClasses(toast.type)"
            >
                <span class="flex-1">{{ toast.message }}</span>
                <button
                    class="opacity-70 hover:opacity-100 text-xs leading-none"
                    @click="removeToast(toast.id)"
                >
                    &times;
                </button>
            </div>
        </TransitionGroup>
    </div>
</template>
