import { describe, expect, it, beforeEach, afterEach, vi, type Mock } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';

vi.mock('@/bridge/api', () => ({
    get: vi.fn(),
    post: vi.fn(),
}));

import { useEditorStore } from './editor';
import { get, post } from '@/bridge/api';

const getMock = get as unknown as Mock;
const postMock = post as unknown as Mock;

const TRANSFORM3D = 'PHPolygon\\Component\\Transform3D';

function entity(name: string, parentEntityId: number | null = null) {
    return {
        name,
        components: [
            {
                _class: TRANSFORM3D,
                properties: { position: { x: 0, y: 0, z: 0 }, parentEntityId },
            },
        ],
        children: [],
    };
}

/**
 * Answer the two polls a running session makes: the log status, then the live
 * world. `world` is what /play-world returns.
 */
function respondWith(world: unknown, running = true) {
    getMock.mockImplementation((path: string) => {
        if (path.startsWith('/project/play-status')) {
            return Promise.resolve({ found: true, log: 'running', running, exitCode: null });
        }
        if (path.startsWith('/project/play-world')) {
            return Promise.resolve(world);
        }
        return Promise.reject(new Error('unexpected path ' + path));
    });
}

/** Let the chained poll promises settle without advancing the poll timer. */
async function settle() {
    for (let i = 0; i < 5; i++) await Promise.resolve();
}

describe('useEditorStore — live world', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.useFakeTimers();
        getMock.mockReset();
        postMock.mockReset();
        postMock.mockResolvedValue({ playId: 'abc123', command: 'php game.php' });
    });

    afterEach(() => {
        vi.clearAllTimers();
        vi.useRealTimers();
    });

    it('renders the running game world and drops child entities', async () => {
        // A child's transform is relative to a parent the snapshot gives no id
        // for, so showing it would put it at a plainly wrong position.
        respondWith({
            available: true,
            changed: true,
            mtime: 42,
            entities: [entity('Ground'), entity('Player'), entity('Player_Hand', 7)],
        });

        const store = useEditorStore();
        await store.play();
        await settle();

        expect(store.liveEntities?.map((e) => e.name)).toEqual(['Ground', 'Player']);
        expect(store.liveChildrenOmitted).toBe(1);
        expect(store.liveWorldAvailable).toBe(true);
    });

    it('reports a game that does not mirror its world', async () => {
        respondWith({ available: false });

        const store = useEditorStore();
        await store.play();
        await settle();

        expect(store.liveWorldAvailable).toBe(false);
        expect(store.liveEntities).toBeNull();
    });

    it('asks only for snapshots newer than the last one it took', async () => {
        respondWith({ available: true, changed: true, mtime: 99, entities: [entity('Ground')] });

        const store = useEditorStore();
        await store.play();
        await settle();

        // Second tick: the engine only re-exports on structural change, so the
        // poll must not re-download an unchanged world.
        respondWith({ available: true, changed: false, mtime: 99 });
        await vi.advanceTimersByTimeAsync(900);
        await settle();

        const worldCalls = getMock.mock.calls
            .map((c) => String(c[0]))
            .filter((p) => p.startsWith('/project/play-world'));
        expect(worldCalls[0]).toContain('since=0');
        expect(worldCalls[worldCalls.length - 1]).toContain('since=99');
        expect(store.liveEntities?.map((e) => e.name)).toEqual(['Ground']);
    });

    it('keeps polling the log when the world request fails', async () => {
        // The log is how the user learns why a game crashed; a failing live view
        // must never take it down with it.
        getMock.mockImplementation((path: string) => {
            if (path.startsWith('/project/play-status')) {
                return Promise.resolve({ found: true, log: 'still here', running: true, exitCode: null });
            }
            return Promise.reject(new Error('world unavailable'));
        });

        const store = useEditorStore();
        await store.play();
        await settle();

        expect(store.playLog).toBe('still here');
        expect(store.playing).toBe(true);
        expect(store.liveEntities).toBeNull();
    });

    it('clears the live world when the game exits', async () => {
        respondWith({
            available: true,
            changed: true,
            mtime: 1,
            entities: [entity('Ground')],
        });

        const store = useEditorStore();
        await store.play();
        await settle();
        expect(store.liveEntities).not.toBeNull();

        respondWith({ available: true, changed: false }, false);
        await vi.advanceTimersByTimeAsync(900);
        await settle();

        expect(store.playing).toBe(false);
        expect(store.liveEntities).toBeNull();
        expect(store.liveChildrenOmitted).toBe(0);
    });

    it('clears the live world when the user stops the game', async () => {
        respondWith({ available: true, changed: true, mtime: 1, entities: [entity('Ground')] });

        const store = useEditorStore();
        await store.play();
        await settle();

        postMock.mockResolvedValue({ stopping: true });
        await store.stop();

        expect(store.liveEntities).toBeNull();
        expect(store.playing).toBe(false);
    });
});
