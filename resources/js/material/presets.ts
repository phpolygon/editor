import type { MaterialData, Color } from '@/types';

/**
 * Ready-made material presets, in the editor's MaterialData shape. These map to
 * fields the viewport can actually render (albedo/roughness/metallic/emission/
 * alpha/clearcoat), so picking one is instantly WYSIWYG. The engine Material
 * has richer fields (flakes/cloth/subsurface) that aren't in MaterialData yet;
 * add them here + in the inspector when the preview can show them.
 */

function rgb(r: number, g: number, b: number): Color {
    return { r, g, b, a: 1 };
}

export function defaultMaterial(id = 'material'): MaterialData {
    return {
        id,
        albedo: rgb(0.8, 0.8, 0.8),
        roughness: 0.5,
        metallic: 0,
        emission: rgb(0, 0, 0),
        alpha: 1,
        shader: 'default',
        albedoTexture: null,
        clearcoat: 0,
        clearcoatRoughness: 0.05,
        normalIntensity: 1,
        useEnvironmentMap: true,
        normalPattern: null,
        surfacePattern: null,
    };
}

export type MaterialPresetName =
    | 'default'
    | 'metal'
    | 'gold'
    | 'plastic'
    | 'rubber'
    | 'carpaint'
    | 'emissive'
    | 'glass';

export const MATERIAL_PRESETS: Record<MaterialPresetName, () => MaterialData> = {
    default: () => defaultMaterial('material'),
    metal: () => ({ ...defaultMaterial('metal'), albedo: rgb(0.9, 0.9, 0.92), metallic: 1, roughness: 0.25 }),
    gold: () => ({ ...defaultMaterial('gold'), albedo: rgb(1.0, 0.78, 0.34), metallic: 1, roughness: 0.3 }),
    plastic: () => ({ ...defaultMaterial('plastic'), albedo: rgb(0.15, 0.45, 0.9), metallic: 0, roughness: 0.4 }),
    rubber: () => ({ ...defaultMaterial('rubber'), albedo: rgb(0.05, 0.05, 0.06), metallic: 0, roughness: 0.9 }),
    carpaint: () => ({
        ...defaultMaterial('carpaint'),
        albedo: rgb(0.65, 0.08, 0.1),
        metallic: 0.6,
        roughness: 0.32,
        clearcoat: 0.8,
        clearcoatRoughness: 0.1,
    }),
    emissive: () => ({ ...defaultMaterial('emissive'), albedo: rgb(0.05, 0.05, 0.05), emission: rgb(0.2, 0.85, 1.0) }),
    glass: () => ({
        ...defaultMaterial('glass'),
        albedo: rgb(0.9, 0.95, 1.0),
        metallic: 0,
        roughness: 0.05,
        alpha: 0.35,
        clearcoat: 1,
    }),
};

export const MATERIAL_PRESET_NAMES = Object.keys(MATERIAL_PRESETS) as MaterialPresetName[];
