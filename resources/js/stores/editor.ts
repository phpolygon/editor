import { defineStore } from 'pinia';
import { ref } from 'vue';

export type Workspace = 'scene' | 'ui' | 'panel';

export const useEditorStore = defineStore('editor', () => {
    const playing = ref(false);
    const theme = ref<'dark'>('dark');

    // Which editing surface is active: the ECS scene, or a UI (widget) layout.
    const workspace = ref<Workspace>('scene');

    function play() {
        playing.value = true;
    }

    function stop() {
        playing.value = false;
    }

    function setWorkspace(next: Workspace) {
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
