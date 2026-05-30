import { describe, expect, it, beforeEach, vi } from 'vitest';
import * as THREE from 'three';
import type { EntityNode } from '@/types';

vi.mock('@/bridge/commands', () => ({
    assetFileUrl: (p: string) => `/test-assets/${p}`,
}));

import { SpriteSync } from './spriteSync';

function entity(name: string, components: Array<{ _class: string; properties?: Record<string, unknown> }> = [], children: EntityNode[] = []): EntityNode {
    return {
        name,
        components: components.map((c) => ({ _class: c._class, properties: c.properties ?? {} })),
        children,
    };
}

function transform2D(props: Record<string, unknown> = {}): { _class: string; properties: Record<string, unknown> } {
    return { _class: 'PHPolygon\\Component\\Transform2D', properties: props };
}

function sprite(props: Record<string, unknown> = {}): { _class: string; properties: Record<string, unknown> } {
    return { _class: 'PHPolygon\\Component\\SpriteRenderer', properties: props };
}

describe('SpriteSync', () => {
    let root: THREE.Group;
    let sync: SpriteSync;

    beforeEach(() => {
        root = new THREE.Group();
        sync = new SpriteSync(root);
    });

    it('positions entities from Transform2D in the XY plane (Z=0)', () => {
        sync.sync([
            entity('Hero', [
                transform2D({ position: { x: 100, y: -50 }, scale: { x: 2, y: 2 }, rotation: 90 }),
            ]),
        ]);

        const obj = sync.getObject('Hero')!;
        expect(obj.position.x).toBe(100);
        expect(obj.position.y).toBe(-50);
        expect(obj.position.z).toBe(0);
        expect(obj.scale.x).toBe(2);
        expect(obj.rotation.z).toBeCloseTo(Math.PI / 2, 5);
    });

    it('hides the quad when no SpriteRenderer is present', () => {
        sync.sync([entity('Invisible', [transform2D()])]);
        const obj = sync.getObject('Invisible')!;
        const mesh = obj.children[0] as THREE.Mesh;
        expect(mesh.visible).toBe(false);
    });

    it('sizes the quad mesh from SpriteRenderer width/height', () => {
        sync.sync([
            entity('S', [
                transform2D(),
                sprite({ width: 64, height: 128 }),
            ]),
        ]);
        const obj = sync.getObject('S')!;
        const mesh = obj.children[0] as THREE.Mesh;
        expect(mesh.visible).toBe(true);
        expect(mesh.scale.x).toBe(64);
        expect(mesh.scale.y).toBe(128);
    });

    it('flips the quad on flipX/flipY', () => {
        sync.sync([
            entity('S', [
                transform2D(),
                sprite({ width: 32, height: 32, flipX: true, flipY: true }),
            ]),
        ]);
        const obj = sync.getObject('S')!;
        const mesh = obj.children[0] as THREE.Mesh;
        expect(mesh.scale.x).toBe(-32);
        expect(mesh.scale.y).toBe(-32);
    });

    it('uses layer as renderOrder for sprite stacking', () => {
        sync.sync([
            entity('Back', [transform2D(), sprite({ layer: 1 })]),
            entity('Front', [transform2D(), sprite({ layer: 5 })]),
        ]);
        const back = sync.getObject('Back')!.children[0] as THREE.Mesh;
        const front = sync.getObject('Front')!.children[0] as THREE.Mesh;
        expect(back.renderOrder).toBe(1);
        expect(front.renderOrder).toBe(5);
    });

    it('applies SpriteRenderer color tint to material', () => {
        sync.sync([
            entity('S', [
                transform2D(),
                sprite({ color: { r: 1, g: 0, b: 0 } }),
            ]),
        ]);
        const mesh = sync.getObject('S')!.children[0] as THREE.Mesh;
        const mat = mesh.material as THREE.MeshBasicMaterial;
        expect(mat.color.r).toBe(1);
        expect(mat.color.g).toBe(0);
        expect(mat.color.b).toBe(0);
    });

    it('applies opacity to material', () => {
        sync.sync([
            entity('S', [
                transform2D(),
                sprite({ opacity: 0.5 }),
            ]),
        ]);
        const mesh = sync.getObject('S')!.children[0] as THREE.Mesh;
        const mat = mesh.material as THREE.MeshBasicMaterial;
        expect(mat.opacity).toBe(0.5);
    });

    it('removes texture when textureId is cleared in a later sync', () => {
        sync.sync([entity('S', [transform2D(), sprite({ textureId: 'hero.png', width: 32, height: 32 })])]);
        const mesh = sync.getObject('S')!.children[0] as THREE.Mesh;
        const mat = mesh.material as THREE.MeshBasicMaterial;
        expect(mat.map).not.toBeNull();

        sync.sync([entity('S', [transform2D(), sprite({ width: 32, height: 32 })])]);
        expect(mat.map).toBeNull();
    });

    it('removes sprite quads when entity disappears', () => {
        sync.sync([entity('A', [transform2D(), sprite()]), entity('B', [transform2D(), sprite()])]);
        sync.sync([entity('A', [transform2D(), sprite()])]);
        expect(sync.getObject('B')).toBeNull();
        expect(root.children).toHaveLength(1);
    });

    it('entityNameFor walks up parents like 3D variant', () => {
        sync.sync([entity('Player', [transform2D(), sprite()])]);
        const playerObj = sync.getObject('Player')!;
        const meshChild = playerObj.children[0];
        expect(sync.entityNameFor(meshChild)).toBe('Player');
    });
});
