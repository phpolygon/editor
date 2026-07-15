import * as THREE from 'three';
import { getMaterial, assetFileUrl } from '@/bridge/commands';
import type { MaterialData } from '@/types';

const cache = new Map<string, THREE.MeshStandardMaterial>();
const pending = new Map<string, Promise<THREE.MeshStandardMaterial | null>>();
const textureLoader = new THREE.TextureLoader();

function colorOf(c: { r: number; g: number; b: number }): THREE.Color {
    return new THREE.Color(c.r, c.g, c.b);
}

function buildMaterial(data: MaterialData): THREE.MeshStandardMaterial {
    const mat = new THREE.MeshStandardMaterial({
        color: colorOf(data.albedo),
        roughness: data.roughness,
        metalness: data.metallic,
        emissive: colorOf(data.emission),
        transparent: data.alpha < 1.0,
        opacity: data.alpha,
        // Editor viewport renders double-sided: engine-generated meshes (e.g.
        // procedurally built terrain/water planes) may wind their faces so the
        // top would be culled when viewed from above under FrontSide.
        side: THREE.DoubleSide,
    });

    if (data.albedoTexture) {
        textureLoader.load(assetFileUrl(data.albedoTexture), (tex) => {
            tex.colorSpace = THREE.SRGBColorSpace;
            mat.map = tex;
            mat.needsUpdate = true;
        });
    }
    return mat;
}

export async function loadMaterial(id: string): Promise<THREE.MeshStandardMaterial | null> {
    const cached = cache.get(id);
    if (cached) return cached;

    const inflight = pending.get(id);
    if (inflight) return inflight;

    const promise = (async () => {
        try {
            const data = await getMaterial(id);
            const mat = buildMaterial(data);
            cache.set(id, mat);
            return mat;
        } catch {
            return null;
        } finally {
            pending.delete(id);
        }
    })();

    pending.set(id, promise);
    return promise;
}

export function placeholderMaterial(): THREE.MeshStandardMaterial {
    return new THREE.MeshStandardMaterial({ color: 0xcc4488, roughness: 0.8, wireframe: true });
}

export function clearMaterialCache(): void {
    for (const mat of cache.values()) {
        mat.dispose();
    }
    cache.clear();
}
