/**
 * Sculpting brushes for the terrain editor.
 *
 * Brushes mutate a {@link Heightmap} in place. That is deliberate: a stroke
 * rewrites thousands of samples per pointer move, and allocating a fresh
 * Float32Array each time would make sculpting stutter on larger grids. Undo is
 * handled a level up by snapshotting before a stroke starts, not per sample.
 *
 * Every brush is bounded to the grid cells its radius actually covers, so cost
 * scales with brush size rather than terrain size — a small brush on a 513x513
 * terrain touches a few hundred samples, not a quarter million.
 *
 * Strength is scaled by frame delta so a stroke feels the same regardless of
 * frame rate, and all writes are clamped to [0,1] because that is the range the
 * quantised payload can represent.
 */

import {
    type Heightmap,
    gridCoordsAt,
    heightAtWorld,
    sampleAt,
    worldX,
    worldZ,
} from './heightmap';

export type BrushType = 'raise' | 'smooth' | 'flatten' | 'noise' | 'ramp';

export interface BrushDef {
    type: BrushType;
    label: string;
    /** One-line explanation shown under the brush list. */
    hint: string;
}

export const BRUSHES: readonly BrushDef[] = [
    { type: 'raise', label: 'Raise / Lower', hint: 'Drag to raise, hold Alt to lower.' },
    { type: 'smooth', label: 'Smooth', hint: 'Averages neighbouring heights to soften detail.' },
    { type: 'flatten', label: 'Flatten', hint: 'Pulls terrain towards a target height.' },
    { type: 'noise', label: 'Roughen', hint: 'Adds random detail; hold Alt to smooth it back.' },
    { type: 'ramp', label: 'Ramp', hint: 'Drag from a start point to blend a straight slope.' },
];

export interface BrushSettings {
    type: BrushType;
    /** Brush radius in world units. */
    radius: number;
    /** Effect per second at the brush centre, 0..1. */
    strength: number;
    /** 0 = hard edge, 1 = fully feathered. */
    falloff: number;
    /**
     * Target world height for `flatten`. When undefined the brush samples the
     * terrain height where the stroke began, which is what makes "flatten"
     * behave like levelling to the spot you clicked.
     */
    targetHeight?: number;
}

export const DEFAULT_BRUSH: BrushSettings = {
    type: 'raise',
    radius: 20,
    strength: 0.5,
    falloff: 0.6,
};

/** Where a stroke began — needed by `ramp`, and by `flatten`'s implicit target. */
export interface StrokeAnchor {
    worldX: number;
    worldZ: number;
    /** World height under the anchor when the stroke started. */
    height: number;
}

export interface BrushApplication {
    settings: BrushSettings;
    /** Brush centre in terrain-local world space. */
    worldX: number;
    worldZ: number;
    /** Seconds since the previous application. */
    dt: number;
    /** Alt/right-drag: invert the brush (lower instead of raise, etc.). */
    invert: boolean;
    anchor?: StrokeAnchor;
}

/**
 * Smooth falloff from the brush centre.
 *
 * `falloff` interpolates between a hard disc and a fully feathered bell. The
 * inner plateau is what lets an artist build a flat-topped mesa; a pure
 * smoothstep from the centre never gives one.
 */
function weightAt(distance: number, radius: number, falloff: number): number {
    if (distance >= radius) return 0;

    const normalized = distance / radius;
    const inner = 1 - Math.min(1, Math.max(0, falloff));
    if (normalized <= inner) return 1;

    const t = (normalized - inner) / (1 - inner || 1);
    // smoothstep, inverted so the weight decays to zero at the rim.
    return 1 - t * t * (3 - 2 * t);
}

/** Grid cell range a brush of `radius` around (wx, wz) can touch. */
function affectedRange(
    map: Heightmap,
    wx: number,
    wz: number,
    radius: number,
): { x0: number; x1: number; z0: number; z1: number } {
    const [gx, gz] = gridCoordsAt(map, wx, wz);
    const cellsX = (radius / map.sizeX) * (map.gridWidth - 1);
    const cellsZ = (radius / map.sizeZ) * (map.gridDepth - 1);

    return {
        x0: Math.max(0, Math.floor(gx - cellsX)),
        x1: Math.min(map.gridWidth - 1, Math.ceil(gx + cellsX)),
        z0: Math.max(0, Math.floor(gz - cellsZ)),
        z1: Math.min(map.gridDepth - 1, Math.ceil(gz + cellsZ)),
    };
}

function clamp01(value: number): number {
    return value < 0 ? 0 : value > 1 ? 1 : value;
}

/**
 * Apply one brush step. Returns true when any sample changed, so the caller can
 * skip rebuilding the preview mesh for a no-op (a stroke outside the terrain,
 * or a brush that has already converged).
 */
export function applyBrush(map: Heightmap, application: BrushApplication): boolean {
    const { settings } = application;
    if (settings.radius <= 0 || settings.strength <= 0) return false;

    switch (settings.type) {
        case 'raise':
            return applyRaise(map, application);
        case 'smooth':
            return applySmooth(map, application);
        case 'flatten':
            return applyFlatten(map, application);
        case 'noise':
            return applyNoise(map, application);
        case 'ramp':
            return applyRamp(map, application);
        default:
            return false;
    }
}

/** Shared iteration: walk the affected cells, handing each its falloff weight. */
function forEachAffected(
    map: Heightmap,
    wx: number,
    wz: number,
    settings: BrushSettings,
    visit: (index: number, weight: number, x: number, z: number) => void,
): boolean {
    const { x0, x1, z0, z1 } = affectedRange(map, wx, wz, settings.radius);
    let touched = false;

    for (let z = z0; z <= z1; z++) {
        const cellZ = worldZ(map, z);
        for (let x = x0; x <= x1; x++) {
            const cellX = worldX(map, x);
            const dx = cellX - wx;
            const dz = cellZ - wz;
            const weight = weightAt(Math.sqrt(dx * dx + dz * dz), settings.radius, settings.falloff);
            if (weight <= 0) continue;

            visit(z * map.gridWidth + x, weight, x, z);
            touched = true;
        }
    }

    return touched;
}

function applyRaise(map: Heightmap, { settings, worldX: wx, worldZ: wz, dt, invert }: BrushApplication): boolean {
    // Height range varies per terrain, so a fixed normalised delta would feel
    // very different at 10 m and 500 m of range. Working in normalised space
    // keeps the brush consistent: full strength moves 25% of the range per
    // second at the brush centre.
    const delta = settings.strength * dt * 0.25 * (invert ? -1 : 1);

    return forEachAffected(map, wx, wz, settings, (index, weight) => {
        map.samples[index] = clamp01(map.samples[index] + delta * weight);
    });
}

function applySmooth(map: Heightmap, { settings, worldX: wx, worldZ: wz, dt }: BrushApplication): boolean {
    const rate = Math.min(1, settings.strength * dt * 4);

    // Read neighbours from a snapshot so the blur does not smear in the
    // iteration direction — sampling already-smoothed neighbours would bias the
    // result towards +X/+Z. Only the affected window (plus the one-cell border
    // the kernel reaches into) is copied, so the cost stays proportional to
    // brush size rather than terrain size.
    const { x0, x1, z0, z1 } = affectedRange(map, wx, wz, settings.radius);
    const window = new BrushWindow(map, x0, x1, z0, z1);

    let touched = false;
    for (let z = z0; z <= z1; z++) {
        const cellZ = worldZ(map, z);
        for (let x = x0; x <= x1; x++) {
            const dx = worldX(map, x) - wx;
            const dz = cellZ - wz;
            const weight = weightAt(Math.sqrt(dx * dx + dz * dz), settings.radius, settings.falloff);
            if (weight <= 0) continue;

            const average =
                (window.get(x - 1, z) +
                    window.get(x + 1, z) +
                    window.get(x, z - 1) +
                    window.get(x, z + 1) +
                    window.get(x, z)) /
                5;

            const index = z * map.gridWidth + x;
            map.samples[index] = clamp01(
                map.samples[index] + (average - map.samples[index]) * rate * weight,
            );
            touched = true;
        }
    }

    return touched;
}

/**
 * Read-only copy of the grid cells a brush step needs, expanded by one cell so
 * a 5-tap kernel on the window's edge still reads unmodified neighbours.
 * Coordinates outside the terrain clamp to its border, matching `sampleAt`.
 */
class BrushWindow {
    private readonly data: Float32Array;

    private readonly minX: number;

    private readonly minZ: number;

    private readonly width: number;

    private readonly height: number;

    constructor(
        private readonly map: Heightmap,
        x0: number,
        x1: number,
        z0: number,
        z1: number,
    ) {
        this.minX = Math.max(0, x0 - 1);
        this.minZ = Math.max(0, z0 - 1);
        const maxX = Math.min(map.gridWidth - 1, x1 + 1);
        const maxZ = Math.min(map.gridDepth - 1, z1 + 1);
        this.width = maxX - this.minX + 1;
        this.height = maxZ - this.minZ + 1;

        this.data = new Float32Array(this.width * this.height);
        for (let z = 0; z < this.height; z++) {
            const sourceStart = (this.minZ + z) * map.gridWidth + this.minX;
            this.data.set(
                map.samples.subarray(sourceStart, sourceStart + this.width),
                z * this.width,
            );
        }
    }

    get(x: number, z: number): number {
        const cx = x < 0 ? 0 : x > this.map.gridWidth - 1 ? this.map.gridWidth - 1 : x;
        const cz = z < 0 ? 0 : z > this.map.gridDepth - 1 ? this.map.gridDepth - 1 : z;

        return this.data[(cz - this.minZ) * this.width + (cx - this.minX)];
    }
}

function applyFlatten(map: Heightmap, application: BrushApplication): boolean {
    const { settings, worldX: wx, worldZ: wz, dt, anchor } = application;
    const range = map.maxHeight - map.minHeight;
    if (range === 0) return false;

    const targetWorld =
        settings.targetHeight ?? anchor?.height ?? heightAtWorld(map, wx, wz);
    const target = clamp01((targetWorld - map.minHeight) / range);
    const rate = Math.min(1, settings.strength * dt * 4);

    return forEachAffected(map, wx, wz, settings, (index, weight) => {
        map.samples[index] = clamp01(
            map.samples[index] + (target - map.samples[index]) * rate * weight,
        );
    });
}

function applyNoise(map: Heightmap, application: BrushApplication): boolean {
    const { settings, worldX: wx, worldZ: wz, dt, invert } = application;

    // Inverting a random brush cannot mean "subtract the same noise" (it was
    // never stored), so it smooths instead — which is what an artist reaching
    // for Alt on a roughen brush actually wants.
    if (invert) return applySmooth(map, application);

    const amplitude = settings.strength * dt * 0.15;

    return forEachAffected(map, wx, wz, settings, (index, weight) => {
        map.samples[index] = clamp01(
            map.samples[index] + (Math.random() * 2 - 1) * amplitude * weight,
        );
    });
}

function applyRamp(map: Heightmap, application: BrushApplication): boolean {
    const { settings, worldX: wx, worldZ: wz, dt, anchor } = application;
    if (!anchor) return false;

    const range = map.maxHeight - map.minHeight;
    if (range === 0) return false;

    const axisX = wx - anchor.worldX;
    const axisZ = wz - anchor.worldZ;
    const axisLengthSq = axisX * axisX + axisZ * axisZ;
    // Before the pointer has travelled, there is no direction to ramp along.
    if (axisLengthSq < 1e-6) return false;

    const startSample = clamp01((anchor.height - map.minHeight) / range);
    const endSample = clamp01((heightAtWorld(map, wx, wz) - map.minHeight) / range);
    const rate = Math.min(1, settings.strength * dt * 4);

    // Ramp along the whole stroke axis, not just around the cursor: the brush
    // covers a corridor from the anchor to the current point.
    const { x0, x1, z0, z1 } = rampRange(map, anchor, wx, wz, settings.radius);
    let touched = false;

    for (let z = z0; z <= z1; z++) {
        const cellZ = worldZ(map, z);
        for (let x = x0; x <= x1; x++) {
            const cellX = worldX(map, x);
            const dx = cellX - anchor.worldX;
            const dz = cellZ - anchor.worldZ;

            // Projection onto the stroke axis gives position along the ramp;
            // the perpendicular distance gives the falloff across it.
            const t = Math.min(1, Math.max(0, (dx * axisX + dz * axisZ) / axisLengthSq));
            const perpX = dx - axisX * t;
            const perpZ = dz - axisZ * t;
            const weight = weightAt(
                Math.sqrt(perpX * perpX + perpZ * perpZ),
                settings.radius,
                settings.falloff,
            );
            if (weight <= 0) continue;

            const target = startSample + (endSample - startSample) * t;
            const index = z * map.gridWidth + x;
            map.samples[index] = clamp01(
                map.samples[index] + (target - map.samples[index]) * rate * weight,
            );
            touched = true;
        }
    }

    return touched;
}

function rampRange(
    map: Heightmap,
    anchor: StrokeAnchor,
    wx: number,
    wz: number,
    radius: number,
): { x0: number; x1: number; z0: number; z1: number } {
    const a = affectedRange(map, anchor.worldX, anchor.worldZ, radius);
    const b = affectedRange(map, wx, wz, radius);

    return {
        x0: Math.min(a.x0, b.x0),
        x1: Math.max(a.x1, b.x1),
        z0: Math.min(a.z0, b.z0),
        z1: Math.max(a.z1, b.z1),
    };
}

/** World height under a point, for capturing a stroke anchor. */
export function anchorAt(map: Heightmap, wx: number, wz: number): StrokeAnchor {
    return { worldX: wx, worldZ: wz, height: heightAtWorld(map, wx, wz) };
}

/** Re-export for callers that only need a single sample read. */
export { sampleAt };
