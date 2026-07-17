import * as THREE from 'three';
import { OBJLoader } from 'three/examples/jsm/loaders/OBJLoader.js';
import { STLLoader } from 'three/examples/jsm/loaders/STLLoader.js';
import { GLTFLoader } from 'three/examples/jsm/loaders/GLTFLoader.js';
import { mergeGeometries } from 'three/examples/jsm/utils/BufferGeometryUtils.js';
import type { RawMeshData } from './editMesh';

/**
 * Import an external 3D file (OBJ / STL / glTF-GLB) and convert it ONCE into
 * the engine's raw MeshData form. PHPolygon has no runtime model-file loader by
 * design — an import is a one-time bake into flat vertex arrays, saved as a raw
 * mesh asset (like a vertex-edited mesh).
 */

/** Extract flat MeshData arrays from a BufferGeometry. */
export function geometryToRawMesh(geo: THREE.BufferGeometry): RawMeshData {
    const pos = geo.getAttribute('position');
    const norm = geo.getAttribute('normal');
    const uv = geo.getAttribute('uv');
    const idx = geo.getIndex();

    const vertices = Array.from(pos.array as ArrayLike<number>);
    const normals = norm ? Array.from(norm.array as ArrayLike<number>) : [];
    const uvs = uv ? Array.from(uv.array as ArrayLike<number>) : [];

    let indices: number[];
    if (idx) {
        indices = Array.from(idx.array as ArrayLike<number>);
    } else {
        indices = [];
        for (let i = 0; i < pos.count; i++) indices.push(i);
    }

    return { vertices, normals, uvs, indices };
}

/** Center a geometry at the origin and scale its largest dimension to 1. */
export function normalizeGeometry(geo: THREE.BufferGeometry): void {
    geo.computeBoundingBox();
    const box = geo.boundingBox;
    if (!box) return;

    const cx = (box.min.x + box.max.x) / 2;
    const cy = (box.min.y + box.max.y) / 2;
    const cz = (box.min.z + box.max.z) / 2;
    const maxDim = Math.max(box.max.x - box.min.x, box.max.y - box.min.y, box.max.z - box.min.z) || 1;

    geo.translate(-cx, -cy, -cz);
    const s = 1 / maxDim;
    geo.scale(s, s, s);
}

/** Reduce a geometry to just position/normal/uv, non-indexed, so multiple
 * meshes can be merged with compatible attributes. */
function toMergeable(geo: THREE.BufferGeometry): THREE.BufferGeometry {
    const src = geo.index ? geo.toNonIndexed() : geo;
    if (!src.getAttribute('normal')) src.computeVertexNormals();

    const out = new THREE.BufferGeometry();
    out.setAttribute('position', src.getAttribute('position'));
    out.setAttribute('normal', src.getAttribute('normal'));
    const count = src.getAttribute('position').count;
    out.setAttribute(
        'uv',
        src.getAttribute('uv') ?? new THREE.BufferAttribute(new Float32Array(count * 2), 2),
    );
    return out;
}

/** Merge every mesh under a loaded object (with world transforms baked). */
function collectMeshGeometry(root: THREE.Object3D): THREE.BufferGeometry {
    root.updateMatrixWorld(true);
    const geos: THREE.BufferGeometry[] = [];
    root.traverse((o) => {
        const mesh = o as THREE.Mesh;
        if (mesh.isMesh && mesh.geometry) {
            const g = toMergeable(mesh.geometry.clone());
            g.applyMatrix4(mesh.matrixWorld);
            geos.push(g);
        }
    });
    if (geos.length === 0) {
        throw new Error('No mesh geometry found in the file');
    }
    return geos.length === 1 ? geos[0] : (mergeGeometries(geos, false) ?? geos[0]);
}

/** Parse a File into normalized raw MeshData, dispatching on its extension. */
export async function importMeshFile(file: File): Promise<RawMeshData> {
    const ext = file.name.split('.').pop()?.toLowerCase() ?? '';
    let geo: THREE.BufferGeometry;

    if (ext === 'obj') {
        geo = collectMeshGeometry(new OBJLoader().parse(await file.text()));
    } else if (ext === 'stl') {
        geo = toMergeable(new STLLoader().parse(await file.arrayBuffer()));
    } else if (ext === 'gltf' || ext === 'glb') {
        const buf = await file.arrayBuffer();
        geo = await new Promise<THREE.BufferGeometry>((resolve, reject) => {
            new GLTFLoader().parse(
                buf,
                '',
                (gltf) => {
                    try {
                        resolve(collectMeshGeometry(gltf.scene));
                    } catch (e) {
                        reject(e as Error);
                    }
                },
                (err) => reject(err as unknown as Error),
            );
        });
    } else {
        throw new Error(`Unsupported format: .${ext} (use OBJ, STL or glTF/GLB)`);
    }

    normalizeGeometry(geo);
    return geometryToRawMesh(geo);
}

export const IMPORT_ACCEPT = '.obj,.stl,.gltf,.glb';
