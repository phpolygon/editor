import { describe, expect, it } from 'vitest';
import { MATERIAL_PRESETS, MATERIAL_PRESET_NAMES, defaultMaterial } from './presets';

describe('material presets', () => {
    it('every preset yields valid MaterialData', () => {
        for (const name of MATERIAL_PRESET_NAMES) {
            const m = MATERIAL_PRESETS[name]();
            expect(m.id, name).toBeTruthy();
            expect(m.roughness, name).toBeGreaterThanOrEqual(0);
            expect(m.roughness, name).toBeLessThanOrEqual(1);
            expect(m.metallic, name).toBeGreaterThanOrEqual(0);
            expect(m.metallic, name).toBeLessThanOrEqual(1);
            expect(m.albedo).toHaveProperty('r');
            expect(m.emission).toHaveProperty('r');
        }
    });

    it('carpaint and glass carry clearcoat', () => {
        expect(MATERIAL_PRESETS.carpaint().clearcoat).toBeGreaterThan(0);
        expect(MATERIAL_PRESETS.glass().clearcoat).toBeGreaterThan(0);
    });

    it('default is a neutral dielectric', () => {
        const d = defaultMaterial();
        expect(d.metallic).toBe(0);
        expect(d.clearcoat).toBe(0);
        expect(d.alpha).toBe(1);
    });
});
