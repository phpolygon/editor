/**
 * The colours terrain layers are shown in.
 *
 * One list, three consumers: the viewport's vertex-colour preview, the baked
 * albedo texture, and the layer swatches in the tool panel. They must agree —
 * a layer that looks olive in the panel and grey in the viewport is actively
 * misleading while painting — so the palette lives here rather than being
 * repeated at each call site in a different numeric range.
 *
 * These are *fallbacks*: a layer that names a material takes that material's
 * albedo instead. They exist so an unassigned layer is still distinguishable
 * while an artist blocks out coverage.
 */

/** RGB 0–255, ordered so adjacent layers stay easy to tell apart. */
export const LAYER_TINTS: readonly [number, number, number][] = [
    [107, 140, 82], // grass
    [140, 133, 122], // rock
    [199, 184, 140], // sand
    [217, 222, 230], // snow
    [92, 107, 128], // slate
    [128, 92, 71], // dirt
];

/** Tint for a layer index, wrapping when there are more layers than tints. */
export function layerTint(index: number): [number, number, number] {
    return LAYER_TINTS[((index % LAYER_TINTS.length) + LAYER_TINTS.length) % LAYER_TINTS.length];
}

/** Same tint normalised to 0–1, for shader and vertex-colour use. */
export function layerTintNormalised(index: number): [number, number, number] {
    const [r, g, b] = layerTint(index);
    return [r / 255, g / 255, b / 255];
}

/** Same tint as a CSS colour, for swatches in the UI. */
export function layerTintCss(index: number): string {
    const [r, g, b] = layerTint(index);
    return `rgb(${r} ${g} ${b})`;
}
