import { describe, expect, it } from 'vitest';
import { LATHE_MAX_POINTS, LOFT_MAX_RINGS, NODE_TYPES } from './graph';

/**
 * The lathe / ellipse-loft nodes spell their lists as numbered keys plus a
 * count – the exact keys PHPolygon\Geometry\ProceduralMeshEvaluator reads.
 */
describe('lathe and ellipse-loft nodes', () => {
    it('are generators without inputs', () => {
        for (const type of ['lathe', 'ellipseLoft']) {
            expect(NODE_TYPES[type].category).toBe('generator');
            expect(NODE_TYPES[type].inputs).toEqual([]);
        }
    });

    it('offer numbered profile points for the lathe', () => {
        const keys = NODE_TYPES.lathe.params.map((p) => p.key);
        expect(keys).toContain('points');
        expect(keys).toContain('segments');
        expect(keys).toContain('p0_r');
        expect(keys).toContain(`p${LATHE_MAX_POINTS - 1}_y`);
    });

    it('offer numbered rings with four radii and a centre for the loft', () => {
        const keys = NODE_TYPES.ellipseLoft.params.map((p) => p.key);
        for (const k of ['rings', 'segments', 'capBottom', 'capTop', 'capSteps']) {
            expect(keys).toContain(k);
        }
        for (const f of ['y', 'xp', 'xn', 'zp', 'zn', 'cx', 'cz']) {
            expect(keys).toContain(`r0_${f}`);
            expect(keys).toContain(`r${LOFT_MAX_RINGS - 1}_${f}`);
        }
        expect(new Set(keys).size).toBe(keys.length);
    });

    it('lets the mirror keep or drop the original', () => {
        expect(NODE_TYPES.mirror.params.map((p) => p.key)).toContain('keep');
    });
});
