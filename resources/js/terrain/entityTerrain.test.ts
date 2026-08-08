import { describe, expect, it } from 'vitest';
import { colliderProperties, gridsMatch, payloadFromComponent, terrainProperties } from './entityTerrain';
import type { ComponentData } from '@/types';

function component(properties: Record<string, unknown>): ComponentData {
    return { _class: 'PHPolygon\\Component\\Terrain', properties };
}

describe('payloadFromComponent', () => {
    it('reads the terrain fields the component carries', () => {
        const payload = payloadFromComponent(
            component({
                gridWidth: 65,
                gridDepth: 33,
                sizeX: 128,
                sizeZ: 64,
                minHeight: -5,
                maxHeight: 40,
                heights: 'encoded',
                chunkSize: 16,
                materialId: 'grass',
            }),
            'island',
        );

        expect(payload).toMatchObject({
            name: 'island',
            gridWidth: 65,
            gridDepth: 33,
            sizeX: 128,
            sizeZ: 64,
            minHeight: -5,
            maxHeight: 40,
            heights: 'encoded',
            chunkSize: 16,
            materialId: 'grass',
        });
    });

    it('leaves layers and splat empty — the component cannot hold them', () => {
        const payload = payloadFromComponent(component({ heights: 'x' }), 'island');

        expect(payload.layers).toEqual([]);
        expect(payload.splat).toBe('');
    });

    it('falls back to sane defaults for missing or bogus values', () => {
        const payload = payloadFromComponent(component({ gridWidth: 'nope', sizeX: null }), 'island');

        expect(payload.gridWidth).toBe(129);
        expect(payload.sizeX).toBe(256);
        expect(payload.heights).toBe('');
    });

    it('carries the scatter sets it is handed', () => {
        const sets = [{ id: 'trees' }] as never;
        expect(payloadFromComponent(component({}), 'island', sets).scatter).toBe(sets);
    });
});

describe('terrainProperties', () => {
    it('maps the payload onto the component’s property names', () => {
        const props = terrainProperties({
            name: 'island',
            gridWidth: 65,
            gridDepth: 65,
            sizeX: 128,
            sizeZ: 128,
            minHeight: 0,
            maxHeight: 20,
            heights: 'abc',
            chunkSize: 16,
            materialId: 'grass',
            layers: [],
            splat: '',
            scatter: [],
        });

        expect(props).toEqual({
            gridWidth: 65,
            gridDepth: 65,
            sizeX: 128,
            sizeZ: 128,
            minHeight: 0,
            maxHeight: 20,
            heights: 'abc',
            chunkSize: 16,
            materialId: 'grass',
            meshIdPrefix: 'island',
        });
    });
});

describe('colliderProperties', () => {
    it('centres the collider bounds on the terrain', () => {
        const props = colliderProperties({
            name: 'island',
            gridWidth: 65,
            gridDepth: 33,
            sizeX: 128,
            sizeZ: 64,
            minHeight: 0,
            maxHeight: 20,
            heights: '',
            chunkSize: 16,
            materialId: '',
            layers: [],
            splat: '',
            scatter: [],
        });

        expect(props).toEqual({
            gridWidth: 65,
            gridDepth: 33,
            worldMinX: -64,
            worldMaxX: 64,
            worldMinZ: -32,
            worldMaxZ: 32,
        });
    });
});

describe('gridsMatch', () => {
    it('is true only for the same sample grid', () => {
        expect(gridsMatch({ gridWidth: 65, gridDepth: 65 }, { gridWidth: 65, gridDepth: 65 })).toBe(true);
        expect(gridsMatch({ gridWidth: 65, gridDepth: 65 }, { gridWidth: 129, gridDepth: 65 })).toBe(false);
    });
});
