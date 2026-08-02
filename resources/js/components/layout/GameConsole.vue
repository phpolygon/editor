<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import { Terminal, X, Copy } from 'lucide-vue-next';
import IconButton from '@/components/ui/IconButton.vue';
import { useEditorStore } from '@/stores/editor';
import { useToast } from '@/composables/useToast';

/**
 * Output of the game launched by Play.
 *
 * A detached game process has no console attached, so without this panel a
 * startup crash — a missing extension, a fatal in a scene — would look like
 * "Play did nothing". It therefore opens itself on a non-zero exit.
 */
const editorStore = useEditorStore();
const { addToast } = useToast();
const logEl = ref<HTMLElement | null>(null);

const status = computed(() => {
    if (editorStore.playError) return { text: editorStore.playError, tone: 'text-editor-danger' };
    if (editorStore.playing) return { text: 'Running', tone: 'text-editor-success' };
    if (editorStore.playExitCode === null) return { text: 'Not running', tone: 'text-editor-muted' };
    return editorStore.playExitCode === 0
        ? { text: 'Exited cleanly', tone: 'text-editor-muted' }
        : { text: `Exited with code ${editorStore.playExitCode}`, tone: 'text-editor-danger' };
});

// Follow the tail while output streams in, which is what you want watching a
// game boot.
watch(
    () => editorStore.playLog,
    () => {
        nextTick(() => {
            if (logEl.value) logEl.value.scrollTop = logEl.value.scrollHeight;
        });
    },
);

async function copyLog() {
    try {
        await navigator.clipboard.writeText(editorStore.playLog);
        addToast('Output copied', 'success');
    } catch {
        addToast('Could not copy the output', 'error');
    }
}
</script>

<template>
    <div v-if="editorStore.consoleOpen" class="flex flex-col border-t border-editor-border bg-editor-panel">
        <div class="flex items-center gap-2 px-2 h-7 bg-editor-active border-b border-editor-border shrink-0">
            <Terminal :size="13" :stroke-width="2" class="text-editor-muted shrink-0" />
            <span class="text-xs font-medium">Game output</span>
            <span class="text-[11px]" :class="status.tone">{{ status.text }}</span>
            <span class="flex-1" />
            <IconButton :icon="Copy" label="Copy output" @click="copyLog" />
            <IconButton :icon="X" label="Close the game output" @click="editorStore.toggleConsole()" />
        </div>

        <pre
            ref="logEl"
            class="h-40 overflow-auto px-3 py-2 text-[11px] leading-relaxed font-mono text-editor-text whitespace-pre-wrap break-words"
            >{{ editorStore.playLog || 'No output yet.' }}</pre
        >
    </div>
</template>
