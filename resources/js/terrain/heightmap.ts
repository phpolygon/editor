/**
 * Client-side heightmap model for the terrain editor.
 *
 * This is a deliberate port of the engine's `PHPolygon\Terrain\HeightmapData`,
 * not an independent design: sculpting has to run at frame rate, so the brush
 * loop cannot round-trip to the backend, which means the editor needs its own
 * copy of the height field. Everything that leaves this module — the base64
 * payload, the sample layout, the normals — is byte- and value-compatible with
 * the PHP side, so a terrain sculpted here renders identically in the engine.
 *
 * Two invariants keep the two implementations honest, and both are covered by
 * tests:
 *   - samples are normalised to [0,1] and quantised as little-endian uint16,
 *     row-major with Z as the outer axis (index = z * gridWidth + x);
 *   - normals come from central differences on the height field, never from
 *     triangle faces, which is what makes chunk borders seamless.
 *
 * Samples live in a Float32Array rather than a number[] because a 257x257
 * terrain is 66k samples that a brush rewrites on every pointer move.
 */

export interface HeightmapOptions {
    gridWidth: number;
    gridDepth: number;
    sizeX: number;
    sizeZ: number;
    minHeight: number;
    maxHeight: number;
}

export interface Heightmap extends HeightmapOptions {
    /** Normalised [0,1] samples, row-major (z-major). */
    samples: Float32Array;
}

export const DEFAULT_HEIGHTMAP: HeightmapOptions = {
    gridWidth: 129,
    gridDepth: 129,
    sizeX: 256,
    sizeZ: 256,
    minHeight: 0,
    maxHeight: 50,
};

/** Grid resolutions offered in the UI — 2^n + 1 so chunks tile evenly. */
export const RESOLUTIONS = [33, 65, 129, 257, 513] as const;

export function createHeightmap(options: Partial<HeightmapOptions> = {}): Heightmap {
    const opts = { ...DEFAULT_HEIGHTMAP, ...options };
    const gridWidth = Math.max(2, Math.floor(opts.gridWidth));
    const gridDepth = Math.max(2, Math.floor(opts.gridDepth));

    return {
        ...opts,
        gridWidth,
        gridDepth,
        samples: new Float32Array(gridWidth * gridDepth),
    };
}

/** Copy a heightmap, so an edit can be staged without mutating the original. */
export function cloneHeightmap(map: Heightmap): Heightmap {
    return { ...map, samples: Float32Array.from(map.samples) };
}

export function sampleCount(map: HeightmapOptions): number {
    return map.gridWidth * map.gridDepth;
}

function clampInt(value: number, max: number): number {
    return value < 0 ? 0 : value > max ? max : value;
}

/** Normalised sample at a grid cell; out-of-range coordinates clamp to the edge. */
export function sampleAt(map: Heightmap, x: number, z: number): number {
    const cx = clampInt(x, map.gridWidth - 1);
    const cz = clampInt(z, map.gridDepth - 1);
    return map.samples[cz * map.gridWidth + cx];
}

/** World-space Y at a grid cell. */
export function heightAt(map: Heightmap, x: number, z: number): number {
    return map.minHeight + sampleAt(map, x, z) * (map.maxHeight - map.minHeight);
}

/** World-space X of grid column x (the grid is centred on the origin). */
export function worldX(map: HeightmapOptions, x: number): number {
    return -map.sizeX * 0.5 + (x / (map.gridWidth - 1)) * map.sizeX;
}

/** World-space Z of grid row z (the grid is centred on the origin). */
export function worldZ(map: HeightmapOptions, z: number): number {
    return -map.sizeZ * 0.5 + (z / (map.gridDepth - 1)) * map.sizeZ;
}

/** Fractional grid coordinates for a world (X, Z), clamped to the grid. */
export function gridCoordsAt(map: HeightmapOptions, wx: number, wz: number): [number, number] {
    const u = (wx + map.sizeX * 0.5) / map.sizeX;
    const v = (wz + map.sizeZ * 0.5) / map.sizeZ;
    return [
        Math.min(Math.max(u * (map.gridWidth - 1), 0), map.gridWidth - 1),
        Math.min(Math.max(v * (map.gridDepth - 1), 0), map.gridDepth - 1),
    ];
}

/** World-space Y at an arbitrary world (X, Z), bilinearly interpolated. */
export function heightAtWorld(map: Heightmap, wx: number, wz: number): number {
    const [gx, gz] = gridCoordsAt(map, wx, wz);
    const x0 = Math.floor(gx);
    const z0 = Math.floor(gz);
    const fx = gx - x0;
    const fz = gz - z0;

    const h00 = heightAt(map, x0, z0);
    const h10 = heightAt(map, x0 + 1, z0);
    const h01 = heightAt(map, x0, z0 + 1);
    const h11 = heightAt(map, x0 + 1, z0 + 1);

    return (
        h00 * (1 - fx) * (1 - fz) +
        h10 * fx * (1 - fz) +
        h01 * (1 - fx) * fz +
        h11 * fx * fz
    );
}

/**
 * Surface normal at a grid cell, from central differences on the height field.
 *
 * Mirrors the engine exactly. Because the result depends only on heightmap
 * samples — never on which chunk is being built — two chunks sharing a vertex
 * compute the same normal and their shading matches across the seam.
 */
export function normalAt(map: Heightmap, x: number, z: number): [number, number, number] {
    const stepX = map.sizeX / (map.gridWidth - 1);
    const stepZ = map.sizeZ / (map.gridDepth - 1);

    const dhdx = (heightAt(map, x + 1, z) - heightAt(map, x - 1, z)) / (2 * stepX);
    const dhdz = (heightAt(map, x, z + 1) - heightAt(map, x, z - 1)) / (2 * stepZ);

    const nx = -dhdx;
    const ny = 1;
    const nz = -dhdz;
    const len = Math.sqrt(nx * nx + ny * ny + nz * nz);
    if (len < 1e-12) return [0, 1, 0];

    return [nx / len, ny / len, nz / len];
}

/** Slope at a grid cell in degrees from horizontal (0 = flat, 90 = vertical). */
export function slopeAt(map: Heightmap, x: number, z: number): number {
    const [, ny] = normalAt(map, x, z);
    return (Math.acos(Math.min(1, Math.max(-1, ny))) * 180) / Math.PI;
}

/**
 * Encode normalised samples as base64 of little-endian uint16 — the exact
 * payload the engine's `HeightmapData::decode()` expects.
 */
export function encodeHeights(samples: Float32Array): string {
    const bytes = new Uint8Array(samples.length * 2);
    const view = new DataView(bytes.buffer);
    for (let i = 0; i < samples.length; i++) {
        const clamped = samples[i] < 0 ? 0 : samples[i] > 1 ? 1 : samples[i];
        view.setUint16(i * 2, Math.round(clamped * 65535), true);
    }
    return bytesToBase64(bytes);
}

/**
 * Decode a base64 uint16 payload. Returns null when the payload is missing or
 * does not match the expected sample count, so the caller can fall back to flat
 * terrain instead of rendering garbage — the same tolerance the engine applies.
 */
export function decodeHeights(encoded: string, expectedSamples: number): Float32Array | null {
    if (!encoded) return null;

    let bytes: Uint8Array;
    try {
        bytes = base64ToBytes(encoded);
    } catch {
        return null;
    }
    if (bytes.length !== expectedSamples * 2) return null;

    const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
    const samples = new Float32Array(expectedSamples);
    for (let i = 0; i < expectedSamples; i++) {
        samples[i] = view.getUint16(i * 2, true) / 65535;
    }
    return samples;
}

/** Build a heightmap from an encoded payload, falling back to flat terrain. */
export function heightmapFromEncoded(options: HeightmapOptions, encoded: string): Heightmap {
    const map = createHeightmap(options);
    const decoded = decodeHeights(encoded, sampleCount(map));
    if (decoded) map.samples = decoded;
    return map;
}

/**
 * Rescale the height range so the current terrain keeps its world-space shape.
 *
 * Samples are normalised against min/max, so changing the range would otherwise
 * stretch or squash the sculpt. Re-projecting the samples means the range
 * becomes a headroom control — raising it makes room to sculpt higher rather
 * than inflating what is already there.
 */
export function reprojectRange(map: Heightmap, minHeight: number, maxHeight: number): Heightmap {
    if (maxHeight <= minHeight) return map;

    const oldRange = map.maxHeight - map.minHeight;
    const newRange = maxHeight - minHeight;
    const samples = new Float32Array(map.samples.length);

    for (let i = 0; i < samples.length; i++) {
        const world = map.minHeight + map.samples[i] * oldRange;
        samples[i] = Math.min(1, Math.max(0, (world - minHeight) / newRange));
    }

    return { ...map, minHeight, maxHeight, samples };
}

/**
 * Resample onto a different grid resolution, preserving the sculpted shape via
 * bilinear interpolation.
 */
export function resample(map: Heightmap, gridWidth: number, gridDepth: number): Heightmap {
    const target = createHeightmap({ ...map, gridWidth, gridDepth });
    const range = map.maxHeight - map.minHeight;

    for (let z = 0; z < target.gridDepth; z++) {
        const wz = worldZ(target, z);
        for (let x = 0; x < target.gridWidth; x++) {
            const world = heightAtWorld(map, worldX(target, x), wz);
            target.samples[z * target.gridWidth + x] =
                range === 0 ? 0 : Math.min(1, Math.max(0, (world - map.minHeight) / range));
        }
    }

    return target;
}

/** Lowest and highest normalised sample, for UI readouts. */
export function sampleExtent(map: Heightmap): [number, number] {
    let min = Infinity;
    let max = -Infinity;
    for (let i = 0; i < map.samples.length; i++) {
        if (map.samples[i] < min) min = map.samples[i];
        if (map.samples[i] > max) max = map.samples[i];
    }
    return map.samples.length === 0 ? [0, 0] : [min, max];
}

/**
 * Base64 helpers.
 *
 * Chunked so a large terrain does not blow the argument limit of
 * `String.fromCharCode` — a 513x513 grid is over half a million bytes, well
 * past what a single spread call survives.
 */
const BASE64_CHUNK = 0x8000;

export function bytesToBase64(bytes: Uint8Array): string {
    let binary = '';
    for (let i = 0; i < bytes.length; i += BASE64_CHUNK) {
        binary += String.fromCharCode(...bytes.subarray(i, i + BASE64_CHUNK));
    }
    return btoa(binary);
}

export function base64ToBytes(encoded: string): Uint8Array {
    const binary = atob(encoded);
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
    return bytes;
}
