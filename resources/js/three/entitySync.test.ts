import { describe, expect, it, beforeEach, vi, type Mock } from 'vitest';
import * as THREE from 'three';
import type { EntityNode } from '@/types';

vi.mock('@/bridge/commands', () => ({
    getMesh: vi.fn(),
    getMaterial: vi.fn(),
    evaluateProceduralMesh: vi.fn(),
    assetFileUrl: (p: string) => `/test-assets/${p}`,
}));

import { EntitySync } from './entitySync';
import { evaluateProceduralMesh } from '@/bridge/commands';
import { clearMeshCache } from './meshCache';
import { clearMaterialCache } from './materialCache';

function entity(name: string, components: Array<{ _class: string; properties?: Record<string, unknown> }> = [], children: EntityNode[] = []): EntityNode {
    return {
        name,
        components: components.map((c) => ({ _class: c._class, properties: c.properties ?? {} })),
        children,
    };
}

function transform3D(props: Record<string, unknown> = {}): { _class: string; properties: Record<string, unknown> } {
    return { _class: 'PHPolygon\\Component\\Transform3D', properties: props };
}

describe('EntitySync', () => {
    let root: THREE.Group;
    let sync: EntitySync;

    beforeEach(() => {
        clearMeshCache();
        clearMaterialCache();
        root = new THREE.Group();
        sync = new EntitySync(root);
    });

    it('evaluates a ProceduralMesh component into mesh geometry', async () => {
        (evaluateProceduralMesh as unknown as Mock).mockResolvedValue({
            id: '', version: 0,
            vertices: [0, 0, 0, 1, 0, 0, 0, 1, 0],
            normals: [0, 0, 1, 0, 0, 1, 0, 0, 1],
            uvs: [0, 0, 1, 0, 0, 1],
            indices: [0, 1, 2],
            vertexCount: 3, triangleCount: 1,
        });

        sync.sync([
            entity('Rock', [
                transform3D(),
                { _class: 'PHPolygon\\Component\\ProceduralMesh', properties: { nodes: [{ id: 'b', type: 'box' }], output: 'b', meshId: '' } },
            ]),
        ]);
        await new Promise((r) => setTimeout(r, 0));

        const group = root.children[0];
        const mesh = group.children.find((c) => c instanceof THREE.Mesh) as THREE.Mesh;
        expect(mesh).toBeTruthy();
        expect(mesh.geometry.getAttribute('position').count).toBe(3);
        expect(evaluateProceduralMesh).toHaveBeenCalledWith([{ id: 'b', type: 'box' }], 'b', '');
    });

    it('creates a group per entity on first sync', () => {
        sync.sync([entity('Player'), entity('Camera')]);
        expect(root.children).toHaveLength(2);
        expect(root.children[0].name).toBe('Player');
        expect(root.children[1].name).toBe('Camera');
    });

    it('applies Transform3D position/rotation/scale', () => {
        sync.sync([
            entity('Box', [transform3D({
                position: { x: 1, y: 2, z: 3 },
                rotation: { x: 0, y: 0.7071, z: 0, w: 0.7071 },
                scale: { x: 2, y: 2, z: 2 },
            })]),
        ]);

        const obj = sync.getObject('Box')!;
        expect(obj.position.toArray()).toEqual([1, 2, 3]);
        expect(obj.scale.toArray()).toEqual([2, 2, 2]);
        expect(obj.quaternion.w).toBeCloseTo(0.7071, 3);
    });

    it('falls back to defaults when Transform3D is missing', () => {
        sync.sync([entity('NoTransform')]);
        const obj = sync.getObject('NoTransform')!;
        expect(obj.position.toArray()).toEqual([0, 0, 0]);
        expect(obj.scale.toArray()).toEqual([1, 1, 1]);
        expect(obj.quaternion.w).toBe(1);
    });

    it('removes objects that disappear from the entity list', () => {
        sync.sync([entity('A'), entity('B')]);
        expect(root.children).toHaveLength(2);

        sync.sync([entity('A')]);
        expect(root.children).toHaveLength(1);
        expect(root.children[0].name).toBe('A');
        expect(sync.getObject('B')).toBeNull();
    });

    it('reuses the same Object3D when an entity persists across syncs', () => {
        sync.sync([entity('Player', [transform3D({ position: { x: 0, y: 0, z: 0 } })])]);
        const first = sync.getObject('Player');

        sync.sync([entity('Player', [transform3D({ position: { x: 5, y: 0, z: 0 } })])]);
        const second = sync.getObject('Player');

        expect(second).toBe(first);
        expect(second!.position.x).toBe(5);
    });

    it('reparents an entity when its tree position changes', () => {
        sync.sync([entity('Parent1', [], [entity('Child')]), entity('Parent2')]);
        const child = sync.getObject('Child')!;
        const parent1 = sync.getObject('Parent1')!;
        const parent2 = sync.getObject('Parent2')!;
        expect(child.parent).toBe(parent1);

        sync.sync([entity('Parent1'), entity('Parent2', [], [entity('Child')])]);
        expect(child.parent).toBe(parent2);
    });

    it('attaches AmbientLight as child of entity Group', () => {
        sync.sync([
            entity('Sun', [
                transform3D(),
                { _class: 'PHPolygon\\Component\\AmbientLight', properties: { color: { r: 0.5, g: 0.5, b: 0.5 }, intensity: 0.8 } },
            ]),
        ]);
        const obj = sync.getObject('Sun')!;
        const light = obj.children.find((c) => c instanceof THREE.AmbientLight) as THREE.AmbientLight | undefined;
        expect(light).toBeDefined();
        expect(light!.intensity).toBe(0.8);
    });

    it('attaches DirectionalLight derived from direction', () => {
        sync.sync([
            entity('Sun', [
                transform3D(),
                { _class: 'PHPolygon\\Component\\DirectionalLight', properties: { direction: { x: 0, y: -1, z: 0 }, intensity: 1.0 } },
            ]),
        ]);
        const obj = sync.getObject('Sun')!;
        const light = obj.children.find((c) => c instanceof THREE.DirectionalLight) as THREE.DirectionalLight | undefined;
        expect(light).toBeDefined();
    });

    it('attaches PointLight with radius as distance', () => {
        sync.sync([
            entity('Lamp', [
                transform3D(),
                { _class: 'PHPolygon\\Component\\PointLight', properties: { intensity: 2.0, radius: 15 } },
            ]),
        ]);
        const obj = sync.getObject('Lamp')!;
        const light = obj.children.find((c) => c instanceof THREE.PointLight) as THREE.PointLight | undefined;
        expect(light).toBeDefined();
        expect(light!.distance).toBe(15);
    });

    it('attaches SpotLight with angle', () => {
        sync.sync([
            entity('Spot', [
                transform3D(),
                { _class: 'PHPolygon\\Component\\SpotLight', properties: { angle: 0.5 } },
            ]),
        ]);
        const obj = sync.getObject('Spot')!;
        const light = obj.children.find((c) => c instanceof THREE.SpotLight) as THREE.SpotLight | undefined;
        expect(light).toBeDefined();
        expect(light!.angle).toBe(0.5);
    });

    it('adds a CameraHelper gizmo for Camera3D components', () => {
        sync.sync([
            entity('Cam', [
                transform3D(),
                { _class: 'PHPolygon\\Component\\Camera3DComponent', properties: { fov: 75 } },
            ]),
        ]);
        const obj = sync.getObject('Cam')!;
        const helper = obj.children.find((c) => c instanceof THREE.CameraHelper);
        expect(helper).toBeDefined();
    });

    it('rebuilds lights on each sync (no stale duplicates)', () => {
        const sceneWithLight = [
            entity('E', [
                transform3D(),
                { _class: 'PHPolygon\\Component\\PointLight', properties: { intensity: 1 } },
            ]),
        ];
        sync.sync(sceneWithLight);
        sync.sync(sceneWithLight);
        sync.sync(sceneWithLight);

        const obj = sync.getObject('E')!;
        const lights = obj.children.filter((c) => c instanceof THREE.Light);
        expect(lights).toHaveLength(1);
    });

    it('entityNameFor walks up parents to find the owning entity', () => {
        sync.sync([entity('Outer', [], [entity('Inner', [transform3D()])])]);
        const inner = sync.getObject('Inner')!;
        // Simulate a raycast hit on a deep descendant
        const probe = new THREE.Object3D();
        inner.add(probe);
        expect(sync.entityNameFor(probe)).toBe('Inner');
    });

    it('entityNameFor returns null when no entity ancestor exists', () => {
        const orphan = new THREE.Object3D();
        expect(sync.entityNameFor(orphan)).toBeNull();
        expect(sync.entityNameFor(null)).toBeNull();
    });

    it('dispose clears all tracked objects', () => {
        sync.sync([entity('A'), entity('B')]);
        sync.dispose();
        expect(root.children).toHaveLength(0);
        expect(sync.getObject('A')).toBeNull();
    });
});
