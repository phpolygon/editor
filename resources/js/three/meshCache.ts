import * as THREE from 'three';
import { getMesh } from '@/bridge/commands';
import type { MeshData } from '@/types';

interface CacheEntry {
    geometry: THREE.BufferGeometry;
    version: number;
}

const cache = new Map<string, CacheEntry>();
const pending = new Map<string, Promise<THREE.BufferGeometry | null>>();

export function buildGeometry(data: MeshData): THREE.BufferGeometry {
    const geom = new THREE.BufferGeometry();
    geom.setAttribute('position', new THREE.Float32BufferAttribute(data.vertices, 3));
    if (data.normals.length === data.vertices.length) {
        geom.setAttribute('normal', new THREE.Float32BufferAttribute(data.normals, 3));
    }
    if (data.uvs.length > 0) {
        geom.setAttribute('uv', new THREE.Float32BufferAttribute(data.uvs, 2));
    }
    geom.setIndex(data.indices);
    if (data.normals.length !== data.vertices.length) {
        geom.computeVertexNormals();
    }
    geom.computeBoundingSphere();
    return geom;
}

export async function loadMesh(id: string): Promise<THREE.BufferGeometry | null> {
    const cached = cache.get(id);
    if (cached) return cached.geometry;

    const inflight = pending.get(id);
    if (inflight) return inflight;

    const promise = (async () => {
        try {
            const data = await getMesh(id);
            const geometry = buildGeometry(data);
            cache.set(id, { geometry, version: data.version });
            return geometry;
        } catch {
            return null;
        } finally {
            pending.delete(id);
        }
    })();

    pending.set(id, promise);
    return promise;
}

/**
 * Store geometry built from an already-fetched MeshData under `id`, replacing
 * (and disposing) any previous entry. Used for dynamically generated meshes —
 * e.g. a live ProceduralMesh preview — where the data arrives from an evaluate
 * call rather than a plain `get_mesh` fetch.
 */
export function setMesh(id: string, data: MeshData): THREE.BufferGeometry {
    cache.get(id)?.geometry.dispose();
    const geometry = buildGeometry(data);
    cache.set(id, { geometry, version: data.version });
    return geometry;
}

export function clearMeshCache(): void {
    for (const { geometry } of cache.values()) {
        geometry.dispose();
    }
    cache.clear();
}

export function placeholderGeometry(): THREE.BufferGeometry {
    return new THREE.BoxGeometry(1, 1, 1);
}
