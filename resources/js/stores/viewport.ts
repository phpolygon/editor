import { defineStore } from 'pinia';
import { ref, watch } from 'vue';

/** Gizmo axes follow the entity's own orientation, or the world axes. */
export type GizmoSpace = 'world' | 'local';

/** Snap increments offered in the viewport bar, in metres / degrees / factor. */
export const TRANSLATE_STEPS = [0.1, 0.25, 0.5, 1, 5] as const;
export const ROTATE_STEPS = [5, 15, 45, 90] as const;
export const SCALE_STEPS = [0.1, 0.25, 0.5, 1] as const;

const STORAGE_KEY = 'phpolygon-editor:viewport';

interface Persisted {
    snapEnabled: boolean;
    translateStep: number;
    rotateStep: number;
    scaleStep: number;
    gizmoSpace: GizmoSpace;
    showGrid: boolean;
}

const DEFAULTS: Persisted = {
    snapEnabled: false,
    translateStep: 1,
    rotateStep: 15,
    scaleStep: 0.25,
    gizmoSpace: 'world',
    showGrid: true,
};

function load(): Persisted {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);
        if (!raw) return { ...DEFAULTS };
        const parsed = JSON.parse(raw) as Partial<Persisted>;
        return {
            snapEnabled: typeof parsed.snapEnabled === 'boolean' ? parsed.snapEnabled : DEFAULTS.snapEnabled,
            // Only accept steps the UI actually offers — a hand-edited or stale
            // value would otherwise put the bar in a state it cannot show.
            translateStep: pick(parsed.translateStep, TRANSLATE_STEPS, DEFAULTS.translateStep),
            rotateStep: pick(parsed.rotateStep, ROTATE_STEPS, DEFAULTS.rotateStep),
            scaleStep: pick(parsed.scaleStep, SCALE_STEPS, DEFAULTS.scaleStep),
            gizmoSpace: parsed.gizmoSpace === 'local' ? 'local' : DEFAULTS.gizmoSpace,
            showGrid: typeof parsed.showGrid === 'boolean' ? parsed.showGrid : DEFAULTS.showGrid,
        };
    } catch {
        // Private-mode localStorage, corrupt JSON — defaults are always usable.
        return { ...DEFAULTS };
    }
}

function pick(value: unknown, allowed: readonly number[], fallback: number): number {
    return typeof value === 'number' && allowed.includes(value) ? value : fallback;
}

/**
 * Viewport interaction settings — snapping, gizmo space, grid.
 *
 * They belong to the *view*, not the scene: switching scenes keeps them, and
 * they persist across sessions the way a DCC tool's snap settings do.
 */
export const useViewportStore = defineStore('viewport', () => {
    const initial = load();

    const snapEnabled = ref(initial.snapEnabled);
    const translateStep = ref(initial.translateStep);
    const rotateStep = ref(initial.rotateStep);
    const scaleStep = ref(initial.scaleStep);
    const gizmoSpace = ref<GizmoSpace>(initial.gizmoSpace);
    const showGrid = ref(initial.showGrid);

    watch([snapEnabled, translateStep, rotateStep, scaleStep, gizmoSpace, showGrid], () => {
        try {
            const state: Persisted = {
                snapEnabled: snapEnabled.value,
                translateStep: translateStep.value,
                rotateStep: rotateStep.value,
                scaleStep: scaleStep.value,
                gizmoSpace: gizmoSpace.value,
                showGrid: showGrid.value,
            };
            localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
        } catch {
            // Persisting is a convenience; never let it break an edit.
        }
    });

    function toggleSnap() {
        snapEnabled.value = !snapEnabled.value;
    }

    function toggleGizmoSpace() {
        gizmoSpace.value = gizmoSpace.value === 'world' ? 'local' : 'world';
    }

    return {
        snapEnabled,
        translateStep,
        rotateStep,
        scaleStep,
        gizmoSpace,
        showGrid,
        toggleSnap,
        toggleGizmoSpace,
    };
});
