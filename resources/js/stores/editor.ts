import { defineStore } from 'pinia';
import { ref } from 'vue';
import { get, post } from '@/bridge/api';
import type { EntityNode } from '@/types';
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

interface PlayWorld {
    available: boolean;
    changed?: boolean;
    mtime?: number;
    entities?: EntityNode[];
}

const TRANSFORM3D = 'PHPolygon\\Component\\Transform3D';

function parentIdOf(entity: EntityNode): number | null {
    const transform = entity.components.find((c) => c._class === TRANSFORM3D);
    const parent = transform?.properties?.parentEntityId;
    return typeof parent === 'number' ? parent : null;
}

/**
 * Turn the flat world snapshot into the nested tree the viewport renders.
 *
 * A live entity's Transform3D references its parent BY id, and a child's
 * transform is relative to that parent — so the link has to be rebuilt or the
 * child ends up at a plainly wrong position.
 *
 * Engines that do not send entity ids (before the exporter carried them) leave
 * nothing to resolve the reference against. Children are then dropped rather
 * than misplaced, and the count says how many: an incomplete live view beats a
 * misleading one.
 */
function nestLiveEntities(entities: EntityNode[]): { roots: EntityNode[]; omitted: number } {
    const byId = new Map<number, EntityNode>();
    for (const entity of entities) {
        if (typeof entity.id === 'number') byId.set(entity.id, entity);
    }

    if (byId.size === 0) {
        const roots = entities.filter((e) => parentIdOf(e) === null);
        return { roots, omitted: entities.length - roots.length };
    }

    const nested = new Map<EntityNode, EntityNode>();
    for (const entity of entities) {
        nested.set(entity, { ...entity, children: [] });
    }

    const roots: EntityNode[] = [];
    let omitted = 0;
    for (const entity of entities) {
        const copy = nested.get(entity)!;
        const parentId = parentIdOf(entity);
        if (parentId === null) {
            roots.push(copy);
            continue;
        }

        const parent = byId.get(parentId);
        const parentCopy = parent ? nested.get(parent) : undefined;
        if (parentCopy) {
            parentCopy.children.push(copy);
        } else {
            // Parent not in the snapshot: placing the child by a transform
            // relative to something absent would be wrong, so leave it out.
            omitted++;
        }
    }

    return { roots, omitted };
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

    /**
     * The running game's live ECS world, when it mirrors one back.
     *
     * Null while nothing is running, or when the game does not enable editor
     * sync. The viewport renders this instead of the authored scene during
     * play, so what is on screen is what the game actually has.
     */
    const liveEntities = ref<EntityNode[] | null>(null);
    /** False once a running game has been seen not to mirror its world. */
    const liveWorldAvailable = ref(true);
    /** How many child entities the live view had to drop (see isChildEntity). */
    const liveChildrenOmitted = ref(0);

    let playId = '';
    let liveMtime = 0;
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

            await pollWorld();
            pollTimer = setTimeout(poll, POLL_INTERVAL_MS);
        } catch {
            pollTimer = setTimeout(poll, POLL_RETRY_MS);
        }
    }

    /**
     * Fetch the live world, if the game mirrors one.
     *
     * `since` skips unchanged snapshots: the engine only re-exports when the
     * world structurally advances, so most ticks send nothing back. Failures
     * are swallowed — a missing live view must never interrupt the log poll
     * that tells the user why their game crashed.
     */
    async function pollWorld() {
        try {
            const world = await get<PlayWorld>(
                '/project/play-world?id=' + encodeURIComponent(playId) + '&since=' + liveMtime,
            );

            liveWorldAvailable.value = world.available;
            if (!world.available || world.changed !== true) return;

            const { roots, omitted } = nestLiveEntities(world.entities ?? []);
            liveChildrenOmitted.value = omitted;
            liveEntities.value = roots;
            liveMtime = world.mtime ?? 0;
        } catch {
            // Keep whatever the last successful poll produced.
        }
    }

    function clearLiveWorld() {
        liveEntities.value = null;
        liveWorldAvailable.value = true;
        liveChildrenOmitted.value = 0;
        liveMtime = 0;
    }

    function finish(exitCode: number | null) {
        clearPoll();
        clearLiveWorld();
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
        clearLiveWorld();

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
        clearLiveWorld();
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
        liveEntities,
        liveWorldAvailable,
        liveChildrenOmitted,
        theme,
        workspace,
        play,
        stop,
        setWorkspace,
        toggleConsole,
    };
});
