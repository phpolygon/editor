/**
 * Bakes painted splat layers into a single terrain albedo texture.
 *
 * **Why baking rather than a runtime splat shader.** Real-time multi-layer
 * blending needs the fragment shader to sample a splat map plus one albedo per
 * layer, which means new texture bindings and shader variants in every backend
 * the engine supports (OpenGL, Vulkan, D3D11/12, Metal, vio). Baking instead
 * produces one ordinary texture that the existing `Material.albedoTexture` path
 * already renders everywhere, with no renderer changes at all.
 *
 * **What that costs.** The baked texture is terrain-resolution, so layers do not
 * tile with per-layer detail up close, and per-layer normal/roughness maps are
 * not represented — only colour. For terrain viewed at a distance, or stylised
 * art, that is usually the right trade; for close-up ground detail it is not,
 * and a runtime splat shader remains the upgrade path.
 *
 * The output resolution is independent of the heightmap grid: coverage is
 * smooth data, so it can be sampled at a higher resolution than the sculpt grid
 * to avoid a blocky look on large terrains.
 */

import type { TerrainLayerPayload } from '@/bridge/commands';
import { type Heightmap } from './heightmap';
import { layerTint } from './layerTints';
import { sampleCountOf, type SplatMap } from './splat';

export interface LayerColour {
    /** 0–255 RGB the layer contributes where it has coverage. */
    rgb: [number, number, number];
}

/**
 * Resolve each layer's bake colour.
 *
 * A layer that names a material gets that material's albedo when the caller can
 * supply it; otherwise it falls back to a distinguishable tint so an unassigned
 * layer still bakes to something readable rather than black.
 */
export function resolveLayerColours(
    layers: readonly TerrainLayerPayload[],
    albedoFor?: (materialId: string) => [number, number, number] | null,
): LayerColour[] {
    return layers.map((layer, index) => {
        const resolved = layer.materialId && albedoFor ? albedoFor(layer.materialId) : null;
        return { rgb: resolved ?? layerTint(index) };
    });
}

/**
 * Raw RGBA pixels, 4 bytes per pixel.
 *
 * A plain buffer rather than an `ImageData`, so baking stays pure logic that
 * runs (and is testable) without a DOM. The caller wraps it for the canvas when
 * it needs to encode a PNG.
 */
export interface BakedImage {
    width: number;
    height: number;
    /**
     * Explicitly `ArrayBuffer`-backed rather than the default
     * `ArrayBufferLike`, so the buffer can be handed straight to `ImageData`
     * without a copy — `ImageData` rejects a possibly-`SharedArrayBuffer` view.
     */
    data: Uint8ClampedArray<ArrayBuffer>;
}

/**
 * Bounds on the baked texture's edge length.
 *
 * The floor keeps a mistyped value from producing something unusably coarse;
 * the ceiling keeps one from allocating an image the GPU would reject anyway —
 * 4096² is already 67 MB of RGBA.
 */
export const MIN_BAKE_RESOLUTION = 64;
export const MAX_BAKE_RESOLUTION = 4096;

export function clampBakeResolution(value: number): number {
    if (!Number.isFinite(value)) return 1024;
    return Math.max(MIN_BAKE_RESOLUTION, Math.min(MAX_BAKE_RESOLUTION, Math.round(value)));
}

export interface BakeOptions {
    /** Output edge length in pixels. Clamped to [64, 4096]. */
    resolution?: number;
    /**
     * Darken by slope so relief reads even on flat-coloured layers. 0 disables
     * it; the default is subtle on purpose, since real lighting is applied by
     * the renderer on top and doubling it up looks muddy.
     */
    slopeShading?: number;
    albedoFor?: (materialId: string) => [number, number, number] | null;
}

/**
 * Render the blended layers into RGBA pixels.
 *
 * Weights are sampled bilinearly from the splat grid so the result is smooth
 * rather than showing the sculpt grid as visible squares.
 */
export function bakeSplatToImage(
    heightmap: Heightmap,
    splat: SplatMap,
    layers: readonly TerrainLayerPayload[],
    options: BakeOptions = {},
): BakedImage {
    const resolution = clampBakeResolution(options.resolution ?? 1024);
    const slopeShading = Math.max(0, Math.min(1, options.slopeShading ?? 0.25));
    const colours = resolveLayerColours(layers, options.albedoFor);

    const image: BakedImage = {
        width: resolution,
        height: resolution,
        data: new Uint8ClampedArray(resolution * resolution * 4),
    };
    const samples = sampleCountOf(splat);
    const layerCount = Math.min(layers.length, splat.layerCount);

    for (let py = 0; py < resolution; py++) {
        // Pixel centres, so the first and last pixel are not half a texel off.
        const v = (py + 0.5) / resolution;
        for (let px = 0; px < resolution; px++) {
            const u = (px + 0.5) / resolution;

            let r = 0;
            let g = 0;
            let b = 0;

            if (layerCount === 0) {
                // No layers: a neutral ground tone, so the bake is still usable
                // as a plain albedo rather than transparent black.
                [r, g, b] = [138, 149, 133];
            } else {
                for (let layer = 0; layer < layerCount; layer++) {
                    const weight = sampleWeight(splat, samples, layer, u, v);
                    if (weight <= 0) continue;
                    const tint = colours[layer].rgb;
                    r += tint[0] * weight;
                    g += tint[1] * weight;
                    b += tint[2] * weight;
                }
            }

            if (slopeShading > 0) {
                const shade = 1 - slopeShading * slopeFactor(heightmap, u, v);
                r *= shade;
                g *= shade;
                b *= shade;
            }

            const offset = (py * resolution + px) * 4;
            image.data[offset] = clampByte(r);
            image.data[offset + 1] = clampByte(g);
            image.data[offset + 2] = clampByte(b);
            image.data[offset + 3] = 255;
        }
    }

    return image;
}

function clampByte(value: number): number {
    return value < 0 ? 0 : value > 255 ? 255 : Math.round(value);
}

/** Bilinear layer weight at normalised texture coordinates, in 0..1. */
function sampleWeight(
    splat: SplatMap,
    samples: number,
    layer: number,
    u: number,
    v: number,
): number {
    const gx = Math.min(Math.max(u * (splat.gridWidth - 1), 0), splat.gridWidth - 1);
    const gz = Math.min(Math.max(v * (splat.gridDepth - 1), 0), splat.gridDepth - 1);
    const x0 = Math.floor(gx);
    const z0 = Math.floor(gz);
    const x1 = Math.min(x0 + 1, splat.gridWidth - 1);
    const z1 = Math.min(z0 + 1, splat.gridDepth - 1);
    const fx = gx - x0;
    const fz = gz - z0;

    const base = layer * samples;
    const at = (x: number, z: number) => splat.weights[base + z * splat.gridWidth + x] / 255;

    return (
        at(x0, z0) * (1 - fx) * (1 - fz) +
        at(x1, z0) * fx * (1 - fz) +
        at(x0, z1) * (1 - fx) * fz +
        at(x1, z1) * fx * fz
    );
}

/** 0 on flat ground, approaching 1 on a cliff. */
function slopeFactor(heightmap: Heightmap, u: number, v: number): number {
    const x = Math.min(
        heightmap.gridWidth - 1,
        Math.max(0, Math.round(u * (heightmap.gridWidth - 1))),
    );
    const z = Math.min(
        heightmap.gridDepth - 1,
        Math.max(0, Math.round(v * (heightmap.gridDepth - 1))),
    );

    const stepX = heightmap.sizeX / (heightmap.gridWidth - 1);
    const stepZ = heightmap.sizeZ / (heightmap.gridDepth - 1);
    const range = heightmap.maxHeight - heightmap.minHeight;

    const sample = (sx: number, sz: number) => {
        const cx = Math.min(heightmap.gridWidth - 1, Math.max(0, sx));
        const cz = Math.min(heightmap.gridDepth - 1, Math.max(0, sz));
        return heightmap.samples[cz * heightmap.gridWidth + cx] * range;
    };

    const dhdx = (sample(x + 1, z) - sample(x - 1, z)) / (2 * stepX);
    const dhdz = (sample(x, z + 1) - sample(x, z - 1)) / (2 * stepZ);
    const ny = 1 / Math.sqrt(dhdx * dhdx + 1 + dhdz * dhdz);

    return 1 - ny;
}
