import { describe, it, expect } from 'vitest';
import {
    TERRAIN_COMPONENT,
    findTerrainComponent,
    heightmapFromComponent,
    heightsPropertyValue,
    sculptability,
    worldToTerrainLocal,
} from './sceneSculpt';
import { createHeightmap, encodeHeights, heightAt } from './heightmap';
import type { ComponentData, EntityNode } from '@/types';

function terrainComponent(overrides: Record<string, unknown> = {}): ComponentData {
    const map = createHeightmap({
        gridWidth: 5,
        gridDepth: 5,
        sizeX: 40,
        sizeZ: 40,
        minHeight: -10,
        maxHeight: 30,
    });
    map.samples.fill(0.5);

    return {
        _class: TERRAIN_COMPONENT,
        properties: {
            gridWidth: 5,
            gridDepth: 5,
            sizeX: 40,
            sizeZ: 40,
            minHeight: -10,
            maxHeight: 30,
            heights: encodeHeights(map.samples),
            ...overrides,
        },
    } as ComponentData;
}

function entity(components: ComponentData[]): EntityNode {
    return { name: 'Island', components, children: [] } as unknown as EntityNode;
}

function transform(rotation?: { x: number; y: number; z: number; w: number }): ComponentData {
    return {
        _class: 'PHPolygon\\Component\\Transform3D',
        properties: {
            position: { x: 0, y: 0, z: 0 },
            rotation: rotation ?? { x: 0, y: 0, z: 0, w: 1 },
            scale: { x: 1, y: 1, z: 1 },
        },
    } as ComponentData;
}

describe('scene terrain sculpting', () => {
    it('finds a Terrain component by fully qualified or short class name', () => {
        expect(findTerrainComponent(entity([terrainComponent()]))).not.toBeNull();
        expect(
            findTerrainComponent(
                entity([{ _class: 'Game\\Component\\Terrain', properties: {} } as ComponentData]),
            ),
        ).not.toBeNull();
        expect(findTerrainComponent(entity([transform()]))).toBeNull();
        expect(findTerrainComponent(null)).toBeNull();
    });

    it('decodes the component heightmap', () => {
        const map = heightmapFromComponent(terrainComponent());

        expect(map.gridWidth).toBe(5);
        expect(map.sizeX).toBe(40);
        // Normalised 0.5 over [-10, 30] is world Y 10.
        expect(heightAt(map, 2, 2)).toBeCloseTo(10, 3);
    });

    it('falls back to flat terrain for a missing payload', () => {
        const map = heightmapFromComponent(terrainComponent({ heights: '' }));

        expect(map.samples).toHaveLength(25);
        expect(heightAt(map, 0, 0)).toBe(-10);
    });

    it('round-trips the payload it writes back', () => {
        const component = terrainComponent();
        const map = heightmapFromComponent(component);

        expect(heightsPropertyValue(map)).toBe(component.properties.heights);
    });

    it('offsets world coordinates into terrain-local space', () => {
        expect(worldToTerrainLocal([105, 3, -45], [100, 20, -50])).toEqual([5, 5]);
        expect(worldToTerrainLocal([0, 0, 0], [0, 0, 0])).toEqual([0, 0]);
    });

    it('allows sculpting an unrotated terrain entity', () => {
        const result = sculptability(entity([transform(), terrainComponent()]));

        expect(result.ok).toBe(true);
    });

    it('allows sculpting a terrain with no transform at all', () => {
        expect(sculptability(entity([terrainComponent()])).ok).toBe(true);
    });

    it('refuses an entity without a Terrain component', () => {
        const result = sculptability(entity([transform()]));

        expect(result.ok).toBe(false);
        if (!result.ok) expect(result.reason).toMatch(/Terrain component/);
    });

    it('refuses a rotated terrain rather than sculpting it wrongly', () => {
        // The brush indexes an axis-aligned grid, so a rotated terrain would be
        // sculpted in the wrong place.
        const rotated = transform({ x: 0, y: 0.383, z: 0, w: 0.924 });

        const result = sculptability(entity([rotated, terrainComponent()]));

        expect(result.ok).toBe(false);
        if (!result.ok) expect(result.reason).toMatch(/rotated/);
    });

    it('refuses when nothing is selected', () => {
        expect(sculptability(null).ok).toBe(false);
        expect(sculptability(undefined).ok).toBe(false);
    });
});
