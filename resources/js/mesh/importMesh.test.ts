import { describe, expect, it } from 'vitest';
import * as THREE from 'three';
import {
    geometryToRawMesh,
    normalizeGeometry,
    normalizeGeometries,
    materialDataFromThree,
    importMeshParts,
} from './importMesh';

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

describe('normalizeGeometries', () => {
    it('shares one transform so grouped geometries stay aligned', () => {
        // Two unit boxes 10 apart: their combined span is 11 along x.
        const a = new THREE.BoxGeometry(1, 1, 1).translate(0, 0, 0);
        const b = new THREE.BoxGeometry(1, 1, 1).translate(10, 0, 0);
        normalizeGeometries([a, b]);

        const boxAll = new THREE.Box3();
        for (const g of [a, b]) {
            g.computeBoundingBox();
            boxAll.union(g.boundingBox!);
        }
        // Combined bounds centre at origin and largest dim (x) scaled to 1.
        expect((boxAll.min.x + boxAll.max.x) / 2).toBeCloseTo(0);
        expect(boxAll.max.x - boxAll.min.x).toBeCloseTo(1);

        // The two boxes keep the SAME relative offset (not recentred each).
        a.computeBoundingBox();
        b.computeBoundingBox();
        const ca = (a.boundingBox!.min.x + a.boundingBox!.max.x) / 2;
        const cb = (b.boundingBox!.min.x + b.boundingBox!.max.x) / 2;
        expect(cb - ca).toBeCloseTo(10 / 11); // original 10 apart, scaled by 1/11
    });
});

describe('materialDataFromThree', () => {
    it('translates PBR fields from a standard material', () => {
        const mat = new THREE.MeshStandardMaterial({
            color: new THREE.Color(0.2, 0.4, 0.6),
            roughness: 0.3,
            metalness: 0.8,
        });
        const data = materialDataFromThree(mat, 'part');

        expect(data.id).toBe('part');
        expect(data.albedo.r).toBeCloseTo(0.2);
        expect(data.albedo.g).toBeCloseTo(0.4);
        expect(data.albedo.b).toBeCloseTo(0.6);
        expect(data.roughness).toBeCloseTo(0.3);
        expect(data.metallic).toBeCloseTo(0.8);
        expect(data.albedoTexture).toBeNull();
    });

    it('keeps alpha at 1 for opaque materials and honours transparent ones', () => {
        const opaque = materialDataFromThree(new THREE.MeshStandardMaterial({ opacity: 0.5 }), 'a');
        expect(opaque.alpha).toBe(1); // not flagged transparent → opacity ignored

        const glass = materialDataFromThree(
            new THREE.MeshStandardMaterial({ opacity: 0.5, transparent: true }),
            'b',
        );
        expect(glass.alpha).toBeCloseTo(0.5);
    });

    it('scales emission by emissive intensity', () => {
        const mat = new THREE.MeshStandardMaterial({
            emissive: new THREE.Color(0.5, 0, 0),
            emissiveIntensity: 2,
        });
        const data = materialDataFromThree(mat, 'e');
        expect(data.emission.r).toBeCloseTo(1); // 0.5 * 2, clamped to 1
    });
});

describe('importMeshParts', () => {
    it('parses a tiny OBJ triangle into one geometry-only part', async () => {
        const obj = 'v 0 0 0\nv 1 0 0\nv 0 1 0\nf 1 2 3\n';
        const file = new File([obj], 'tri.obj', { type: 'text/plain' });

        const parts = await importMeshParts(file);

        expect(parts).toHaveLength(1);
        expect(parts[0].name).toBe('tri');
        expect(parts[0].mesh.vertices.length).toBe(9); // 3 vertices × xyz
        expect(parts[0].mesh.indices).toHaveLength(3);
        expect(parts[0].mesh.normals.length).toBe(9);
        expect(parts[0].material).toBeNull();
        expect(parts[0].texture).toBeNull();
    });

    it('rejects an unsupported extension', async () => {
        const file = new File(['x'], 'model.fbx');
        await expect(importMeshParts(file)).rejects.toThrow(/Unsupported/);
    });
});
