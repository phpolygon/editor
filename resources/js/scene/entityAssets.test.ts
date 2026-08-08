import { describe, expect, it } from 'vitest';
import {
    resolveEntityMaterial,
    resolveEntityMesh,
    resolveEntityShader,
    resolveEntityTerrain,
} from './entityAssets';
import type { EntityNode } from '@/types';

function entity(components: EntityNode['components']): EntityNode {
    return { name: 'Player', components, children: [] } as EntityNode;
}

describe('resolveEntityMesh', () => {
    it('returns null without a selected entity', () => {
        expect(resolveEntityMesh(null)).toBeNull();
    });

    it('returns null for an entity with no mesh component', () => {
        expect(resolveEntityMesh(entity([{ _class: 'PHPolygon\\Component\\Transform3D', properties: {} }]))).toBeNull();
    });

    it('reads a ProceduralMesh graph', () => {
        const target = resolveEntityMesh(
            entity([
                {
                    _class: 'PHPolygon\\Component\\ProceduralMesh',
                    properties: { nodes: [{ id: 'box', type: 'box' }], output: 'box', meshId: 'player_body' },
                },
            ]),
        );

        expect(target).toEqual({
            entity: 'Player',
            componentClass: 'PHPolygon\\Component\\ProceduralMesh',
            kind: 'graph',
            nodes: [{ id: 'box', type: 'box' }],
            output: 'box',
            meshId: 'player_body',
        });
    });

    it('reads a MeshRenderer as an asset reference', () => {
        const target = resolveEntityMesh(
            entity([{ _class: 'PHPolygon\\Component\\MeshRenderer', properties: { meshId: 'crate', materialId: 'wood' } }]),
        );

        expect(target).toMatchObject({ kind: 'asset', meshId: 'crate', componentClass: 'PHPolygon\\Component\\MeshRenderer' });
    });

    it('prefers the ProceduralMesh when both components are present', () => {
        const target = resolveEntityMesh(
            entity([
                { _class: 'PHPolygon\\Component\\MeshRenderer', properties: { meshId: 'crate' } },
                { _class: 'PHPolygon\\Component\\ProceduralMesh', properties: { nodes: [], output: '' } },
            ]),
        );

        expect(target?.kind).toBe('graph');
    });

    it('leaves terrain entities to the terrain workspace', () => {
        const target = resolveEntityMesh(
            entity([
                { _class: 'PHPolygon\\Component\\Terrain', properties: { heights: '' } },
                { _class: 'PHPolygon\\Component\\MeshRenderer', properties: { materialId: 'grass' } },
            ]),
        );

        expect(target).toBeNull();
    });

    it('tolerates a MeshRenderer with no mesh assigned yet', () => {
        const target = resolveEntityMesh(entity([{ _class: 'PHPolygon\\Component\\MeshRenderer', properties: {} }]));

        expect(target).toMatchObject({ kind: 'asset', meshId: '' });
    });
});

describe('resolveEntityMaterial', () => {
    it('returns null when nothing references a material', () => {
        expect(resolveEntityMaterial(entity([{ _class: 'PHPolygon\\Component\\Transform3D', properties: {} }]))).toBeNull();
    });

    it('finds the component carrying materialId', () => {
        const target = resolveEntityMaterial(
            entity([{ _class: 'PHPolygon\\Component\\MeshRenderer', properties: { meshId: 'crate', materialId: 'wood' } }]),
        );

        expect(target).toEqual({
            entity: 'Player',
            componentClass: 'PHPolygon\\Component\\MeshRenderer',
            materialId: 'wood',
        });
    });

    it('prefers a component that actually has a material assigned', () => {
        const target = resolveEntityMaterial(
            entity([
                { _class: 'PHPolygon\\Component\\MeshRenderer', properties: { materialId: '' } },
                { _class: 'PHPolygon\\Component\\Terrain', properties: { materialId: 'grass' } },
            ]),
        );

        expect(target).toMatchObject({ componentClass: 'PHPolygon\\Component\\Terrain', materialId: 'grass' });
    });

    it('still offers an unassigned holder so a material can be created', () => {
        const target = resolveEntityMaterial(
            entity([{ _class: 'PHPolygon\\Component\\MeshRenderer', properties: { materialId: '' } }]),
        );

        expect(target).toMatchObject({ materialId: '' });
    });
});

describe('resolveEntityShader', () => {
    it('needs a material to reach a shader', () => {
        expect(
            resolveEntityShader(entity([{ _class: 'PHPolygon\\Component\\MeshRenderer', properties: { materialId: '' } }])),
        ).toBeNull();
    });

    it('routes through the entity’s material', () => {
        const target = resolveEntityShader(
            entity([{ _class: 'PHPolygon\\Component\\MeshRenderer', properties: { materialId: 'wood' } }]),
        );

        expect(target).toEqual({ entity: 'Player', materialId: 'wood' });
    });
});

describe('resolveEntityTerrain', () => {
    it('returns null without a Terrain component', () => {
        expect(resolveEntityTerrain(entity([{ _class: 'PHPolygon\\Component\\MeshRenderer', properties: {} }]))).toBeNull();
    });

    it('collects the terrain and the sibling components it keeps in sync', () => {
        const target = resolveEntityTerrain(
            entity([
                { _class: 'PHPolygon\\Component\\Terrain', properties: { heights: 'abc', meshIdPrefix: 'island' } },
                { _class: 'PHPolygon\\Component\\HeightmapCollider3D', properties: { gridWidth: 129 } },
                { _class: 'PHPolygon\\Component\\TerrainScatter', properties: { sets: [{ id: 'trees' }] } },
            ]),
        );

        expect(target).toMatchObject({
            entity: 'Player',
            componentClass: 'PHPolygon\\Component\\Terrain',
            colliderComponentClass: 'PHPolygon\\Component\\HeightmapCollider3D',
            scatterComponentClass: 'PHPolygon\\Component\\TerrainScatter',
            assetName: 'island',
            scatterSets: [{ id: 'trees' }],
        });
    });

    it('does not mistake TerrainScatter for the terrain itself', () => {
        expect(
            resolveEntityTerrain(entity([{ _class: 'PHPolygon\\Component\\TerrainScatter', properties: { sets: [] } }])),
        ).toBeNull();
    });
});
