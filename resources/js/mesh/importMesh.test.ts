import { describe, expect, it } from 'vitest';
import * as THREE from 'three';
import { geometryToRawMesh, normalizeGeometry, importMeshFile } from './importMesh';

describe('geometryToRawMesh', () => {
    it('extracts consistent flat arrays from an indexed box', () => {
        const raw = geometryToRawMesh(new THREE.BoxGeometry(1, 1, 1));

        expect(raw.vertices.length).toBeGreaterThan(0);
        expect(raw.vertices.length % 3).toBe(0);
        expect(raw.indices.length % 3).toBe(0);
        expect(raw.normals.length).toBe(raw.vertices.length);
    });

    it('generates sequential indices for a non-indexed geometry', () => {
        const raw = geometryToRawMesh(new THREE.BoxGeometry(1, 1, 1).toNonIndexed());
        const vertCount = raw.vertices.length / 3;
        expect(raw.indices).toHaveLength(vertCount);
        expect(raw.indices[0]).toBe(0);
        expect(raw.indices[vertCount - 1]).toBe(vertCount - 1);
    });
});

describe('normalizeGeometry', () => {
    it('centers at the origin and scales the largest dimension to 1', () => {
        const geo = new THREE.BoxGeometry(4, 2, 2).translate(10, 5, 0);
        normalizeGeometry(geo);
        geo.computeBoundingBox();
        const b = geo.boundingBox!;

        expect((b.min.x + b.max.x) / 2).toBeCloseTo(0);
        expect((b.min.y + b.max.y) / 2).toBeCloseTo(0);
        expect(b.max.x - b.min.x).toBeCloseTo(1); // largest dim → 1
        expect(b.max.y - b.min.y).toBeCloseTo(0.5);
    });
});

describe('importMeshFile', () => {
    it('parses a tiny OBJ triangle through the full pipeline', async () => {
        const obj = 'v 0 0 0\nv 1 0 0\nv 0 1 0\nf 1 2 3\n';
        const file = new File([obj], 'tri.obj', { type: 'text/plain' });

        const raw = await importMeshFile(file);

        expect(raw.vertices.length).toBe(9); // 3 vertices × xyz
        expect(raw.indices).toHaveLength(3);
        expect(raw.normals.length).toBe(9);
    });

    it('rejects an unsupported extension', async () => {
        const file = new File(['x'], 'model.fbx');
        await expect(importMeshFile(file)).rejects.toThrow(/Unsupported/);
    });
});

