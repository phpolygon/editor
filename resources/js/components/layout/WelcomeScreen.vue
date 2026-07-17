<script setup lang="ts">
import { onMounted } from 'vue';
import { Boxes, FolderOpen, FolderInput, Clock, ArrowRight } from 'lucide-vue-next';
import Button from '@/components/ui/Button.vue';
import { useProjectStore } from '@/stores/project';
import { useToast } from '@/composables/useToast';

/**
 * Full-screen landing shown when no project is open. Replaces the three empty
 * grey panels a first-time user used to face with a clear "here's how to start"
 * surface: open, import, or pick a recent project.
 */
const projectStore = useProjectStore();
const { addToast } = useToast();

onMounted(() => {
    projectStore.fetchRecent().catch(() => {});
});

async function open() {
    try {
        await projectStore.openProjectWithDialog();
        addToast(`Project "${projectStore.name}" opened`, 'success');
    } catch (e: any) {
        if (e?.message !== 'No directory selected') addToast(e?.message ?? 'Failed to open project', 'error');
    }
}

async function importProject() {
    try {
        const { created } = await projectStore.importProjectWithDialog();
        addToast(`Project "${projectStore.name}" ${created ? 'imported' : 'opened'}`, 'success');
    } catch (e: any) {
        if (e?.message !== 'No directory selected') addToast(e?.message ?? 'Failed to import project', 'error');
    }
}

async function openRecent(dir: string, name: string) {
    try {
        await projectStore.openRecent(dir);
        addToast(`Project "${projectStore.name}" opened`, 'success');
    } catch (e: any) {
        addToast(e?.message ?? `Failed to open "${name}"`, 'error');
    }
}
</script>

<template>
    <div class="flex-1 flex items-center justify-center overflow-auto p-8 bg-editor-bg">
        <div class="w-full max-w-lg flex flex-col items-center text-center gap-6">
            <!-- Brand -->
            <div class="flex flex-col items-center gap-3">
                <div class="flex items-center justify-center h-16 w-16 rounded-2xl bg-editor-accent/15 text-editor-accent">
                    <Boxes :size="34" :stroke-width="1.75" />
                </div>
                <div>
                    <h1 class="text-xl font-semibold text-editor-text">PHPolygon Editor</h1>
                    <p class="text-sm text-editor-muted mt-1">
                        Visually build game scenes, UI layouts and assets for the PHPolygon engine.
                    </p>
                </div>
            </div>

            <!-- Primary actions -->
            <div class="flex items-center gap-2">
                <Button :icon="FolderOpen" variant="primary" size="md" @click="open">Open Project</Button>
                <Button :icon="FolderInput" size="md" @click="importProject">Import Project</Button>
            </div>

            <!-- Recent projects -->
            <div v-if="projectStore.recent.length > 0" class="w-full mt-2">
                <div class="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wider text-editor-muted mb-2">
                    <Clock :size="13" :stroke-width="2" />
                    Recent projects
                </div>
                <div class="flex flex-col gap-1">
                    <button
                        v-for="r in projectStore.recent"
                        :key="r.dir"
                        class="group flex items-center gap-3 w-full text-left px-3 py-2 rounded-lg border border-editor-border
                               bg-editor-panel hover:border-editor-accent hover:bg-editor-hover transition-colors"
                        @click="openRecent(r.dir, r.name)"
                    >
                        <FolderOpen :size="16" class="shrink-0 text-editor-muted group-hover:text-editor-accent" />
                        <div class="min-w-0 flex-1">
                            <div class="text-sm text-editor-text truncate">{{ r.name }}</div>
                            <div class="text-[11px] text-editor-muted truncate" dir="rtl">{{ r.dir }}</div>
                        </div>
                        <ArrowRight :size="15" class="shrink-0 text-editor-muted opacity-0 group-hover:opacity-100 transition-opacity" />
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
