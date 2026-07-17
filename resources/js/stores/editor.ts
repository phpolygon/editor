import { defineStore } from 'pinia';
import { ref } from 'vue';
import type { WorkspaceId } from '@/workspaces';

// Kept as an alias so existing imports of `Workspace` still resolve; the set of
// valid ids now lives in the workspace registry (`@/workspaces`).
export type Workspace = WorkspaceId;

export const useEditorStore = defineStore('editor', () => {
    const playing = ref(false);
    const theme = ref<'dark'>('dark');

    // Which editing surface is active (scene / ui / panel / future tools).
    const workspace = ref<WorkspaceId>('scene');

    function play() {
        playing.value = true;
    }

    function stop() {
        playing.value = false;
    }

    function setWorkspace(next: WorkspaceId) {
        workspace.value = next;
    }

    return {
        playing,
        theme,
        workspace,
        play,
        stop,
        setWorkspace,
    };
});
