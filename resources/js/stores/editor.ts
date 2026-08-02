import { defineStore } from 'pinia';
import { ref } from 'vue';
import { get, post } from '@/bridge/api';
import type { WorkspaceId } from '@/workspaces';

// Kept as an alias so existing imports of `Workspace` still resolve; the set of
// valid ids now lives in the workspace registry (`@/workspaces`).
export type Workspace = WorkspaceId;

interface PlayStatus {
    found: boolean;
    log: string;
    running: boolean;
    exitCode: number | null;
}

/** How often the editor polls a running game for new output. */
const POLL_INTERVAL_MS = 800;

/** Slower retry after a failed poll, so a hiccup does not hammer the backend. */
const POLL_RETRY_MS = 2000;

export const useEditorStore = defineStore('editor', () => {
    const playing = ref(false);
    const theme = ref<'dark'>('dark');

    // Which editing surface is active (scene / ui / panel / future tools).
    const workspace = ref<WorkspaceId>('scene');

    /**
     * Live output of the running game.
     *
     * A game launched from the editor is a detached process with no console
     * attached, so without this its output — including the stack trace when it
     * fails to boot — would be invisible.
     */
    const playLog = ref('');
    const playError = ref<string | null>(null);
    const playExitCode = ref<number | null>(null);
    /** True between hitting Play and the launch request coming back. */
    const playStarting = ref(false);
    /** Whether the output console is expanded. */
    const consoleOpen = ref(false);

    let playId = '';
    let pollTimer: ReturnType<typeof setTimeout> | null = null;

    function clearPoll() {
        if (pollTimer !== null) {
            clearTimeout(pollTimer);
            pollTimer = null;
        }
    }

    async function poll() {
        if (playId === '') return;

        try {
            const status = await get<PlayStatus>(
                '/project/play-status?id=' + encodeURIComponent(playId),
            );
            playLog.value = status.log;

            if (status.found && !status.running) {
                finish(status.exitCode);
                return;
            }

            pollTimer = setTimeout(poll, POLL_INTERVAL_MS);
        } catch {
            pollTimer = setTimeout(poll, POLL_RETRY_MS);
        }
    }

    function finish(exitCode: number | null) {
        clearPoll();
        playing.value = false;
        playStarting.value = false;
        playExitCode.value = exitCode;
        playId = '';

        // A non-zero exit means the game crashed or never booted, and the reason
        // is in the log — so surface the console rather than letting the button
        // quietly flip back to "Play" with no explanation.
        if (exitCode !== null && exitCode !== 0) {
            consoleOpen.value = true;
        }
    }

    /**
     * Launch the open project.
     *
     * The caller saves the scene first: the game reads scene files from disk, so
     * unsaved edits would simply not be in what runs.
     */
    async function play(): Promise<boolean> {
        if (playing.value) return false;

        playStarting.value = true;
        playError.value = null;
        playExitCode.value = null;
        playLog.value = '';

        try {
            const res = await post<{ playId: string; command: string }>('/project/play-start');
            playId = res.playId;
            playing.value = true;
            playStarting.value = false;
            clearPoll();
            void poll();
            return true;
        } catch (e: unknown) {
            playStarting.value = false;
            playError.value = e instanceof Error ? e.message : 'Failed to start the game';
            consoleOpen.value = true;
            return false;
        }
    }

    /**
     * Ask the game to stop.
     *
     * Flips the button back without waiting for the process to actually die: the
     * supervising process notices the request within its poll interval, and a
     * button stuck on "Stop" for a second reads as broken.
     */
    async function stop(): Promise<void> {
        if (!playing.value && playId === '') return;

        const id = playId;
        playing.value = false;
        clearPoll();
        playId = '';

        try {
            await post('/project/play-stop', { id });
        } catch (e: unknown) {
            playError.value = e instanceof Error ? e.message : 'Failed to stop the game';
        }
    }

    function setWorkspace(next: WorkspaceId) {
        workspace.value = next;
    }

    function toggleConsole() {
        consoleOpen.value = !consoleOpen.value;
    }

    return {
        playing,
        playStarting,
        playLog,
        playError,
        playExitCode,
        consoleOpen,
        theme,
        workspace,
        play,
        stop,
        setWorkspace,
        toggleConsole,
    };
});
