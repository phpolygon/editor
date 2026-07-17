<script setup lang="ts">
import { nextTick, ref, watch } from 'vue';
import Modal from './Modal.vue';
import Button from './Button.vue';
import { useDialogHost } from '@/composables/useDialog';

/**
 * Single global renderer for `useDialog()` prompts/confirms. Mount once near
 * the app root (alongside ToastContainer). Focuses the input on open and
 * submits on Enter.
 */
const { active, accept, dismiss } = useDialogHost();
const inputEl = ref<HTMLInputElement | null>(null);

watch(active, async (state) => {
    if (state?.kind === 'prompt') {
        await nextTick();
        inputEl.value?.focus();
        inputEl.value?.select();
    }
});

// Modal's v-model expects a boolean; closing it (Escape/backdrop) = dismiss.
function onModalUpdate(open: boolean) {
    if (!open) dismiss();
}
</script>

<template>
    <Modal
        :model-value="active !== null"
        :title="active?.title"
        width="max-w-sm"
        @update:model-value="onModalUpdate"
    >
        <template v-if="active">
            <p v-if="active.message" class="text-editor-muted mb-3">{{ active.message }}</p>
            <input
                v-if="active.kind === 'prompt'"
                ref="inputEl"
                v-model="active.value"
                type="text"
                :placeholder="active.placeholder"
                class="w-full h-9 px-3 rounded-md bg-editor-input border border-editor-border text-editor-text text-sm
                       focus:outline-none focus:border-editor-accent focus:ring-2 focus:ring-editor-accent/40"
                @keydown.enter.prevent="accept"
            />
        </template>

        <template #footer>
            <Button variant="ghost" size="md" @click="dismiss">
                {{ active?.cancelLabel ?? 'Cancel' }}
            </Button>
            <Button
                :variant="active?.danger ? 'danger' : 'primary'"
                size="md"
                @click="accept"
            >
                {{ active?.confirmLabel ?? 'OK' }}
            </Button>
        </template>
    </Modal>
</template>
