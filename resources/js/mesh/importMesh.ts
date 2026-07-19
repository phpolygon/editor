import * as THREE from 'three';
import { OBJLoader } from 'three/examples/jsm/loaders/OBJLoader.js';
import { STLLoader } from 'three/examples/jsm/loaders/STLLoader.js';
import { GLTFLoader } from 'three/examples/jsm/loaders/GLTFLoader.js';
import { DRACOLoader } from 'three/examples/jsm/loaders/DRACOLoader.js';
import { MeshoptDecoder } from 'three/examples/jsm/libs/meshopt_decoder.module.js';
import { mergeGeometries } from 'three/examples/jsm/utils/BufferGeometryUtils.js';
import type { RawMeshData } from './editMesh';
import { defaultMaterial } from '@/material/presets';
import type { MaterialData } from '@/types';

/**
 * Import an external 3D file (OBJ / STL / glTF-GLB) and convert it ONCE into
 * the engine's raw MeshData form. PHPolygon has no runtime model-file loader by
 * design — an import is a one-time bake into flat vertex arrays, saved as a raw
 * mesh asset (like a vertex-edited mesh).
 *
 * glTF/GLB additionally carry PBR materials and textures. Those are TRANSLATED
 * on import: the file is split per material into separate sub-meshes, each with
 * a derived {@link MaterialData} and (optionally) its base-colour texture as a
 * PNG to persist alongside — see {@link importMeshParts}. DRACO- and meshopt-
 * compressed geometry is decoded transparently.
 */

/** A base-colour texture pulled out of an imported material, ready to persist. */
export interface ImportedTexture {
    /** Suggested asset name (no extension); the backend adds `.png`. */
    suggestedName: string;
    /** `data:image/png;base64,…` — accepted as-is by the save_texture command. */
    dataUrl: string;
}

/** One material's worth of an imported model: geometry + its translated look. */
export interface ImportedMeshPart {
    /** Suggested asset id for both the sub-mesh and its material. */
    name: string;
    mesh: RawMeshData;
    /**
     * The translated material, or null for formats without usable material data
     * (OBJ/STL). `albedoTexture` is left unset here — it is filled in with the
     * saved texture's relative path once {@link texture} has been persisted.
     */
    material: MaterialData | null;
    /** Base-colour texture to save, or null when the material has none. */
    texture: ImportedTexture | null;
}

// ── loader wiring ────────────────────────────────────────────────

let gltf: GLTFLoader | null = null;

/**
 * A GLTFLoader wired for DRACO- and meshopt-compressed assets. The DRACO
 * decoder is served from `public/draco/` (copied out of the three package by
 * scripts/copy-draco.mjs) so imports decode fully offline, with no CDN call.
 * Built lazily and reused — DRACOLoader spins up web workers we don't want to
 * recreate per import.
 */
function gltfLoader(): GLTFLoader {
    if (gltf) return gltf;
    const draco = new DRACOLoader().setDecoderPath('/draco/');
    gltf = new GLTFLoader().setDRACOLoader(draco).setMeshoptDecoder(MeshoptDecoder);
    return gltf;
}

// ── geometry helpers ─────────────────────────────────────────────

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
    normalizeGeometries([geo]);
}

/**
 * Center a group of geometries about their COMBINED bounding box and scale them
 * all by the same factor (largest combined dimension → 1). Sharing one transform
 * keeps split-per-material sub-meshes aligned with each other; normalizing each
 * on its own would recentre and rescale them independently and pull them apart.
 */
export function normalizeGeometries(geos: THREE.BufferGeometry[]): void {
    const box = new THREE.Box3();
    for (const geo of geos) {
        geo.computeBoundingBox();
        if (geo.boundingBox) box.union(geo.boundingBox);
    }
    if (box.isEmpty()) return;

    const cx = (box.min.x + box.max.x) / 2;
    const cy = (box.min.y + box.max.y) / 2;
    const cz = (box.min.z + box.max.z) / 2;
    const maxDim = Math.max(box.max.x - box.min.x, box.max.y - box.min.y, box.max.z - box.min.z) || 1;
    const s = 1 / maxDim;

    for (const geo of geos) {
        geo.translate(-cx, -cy, -cz);
        geo.scale(s, s, s);
    }
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

function mergeOrSingle(geos: THREE.BufferGeometry[]): THREE.BufferGeometry {
    return geos.length === 1 ? geos[0] : (mergeGeometries(geos, false) ?? geos[0]);
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
    return mergeOrSingle(geos);
}

// ── material / texture translation (glTF) ────────────────────────

const clamp01 = (v: number): number => Math.min(1, Math.max(0, v));

/**
 * Translate a three.js standard/physical material (as produced by GLTFLoader
 * from a glTF PBR material) into the editor's MaterialData. Only the fields the
 * editor viewport can render are carried over; the base-colour texture is
 * handled separately so it can be persisted as a file first.
 */
export function materialDataFromThree(mat: THREE.Material, id: string): MaterialData {
    const out = defaultMaterial(id);
    const std = mat as THREE.MeshStandardMaterial;

    if (std.color) out.albedo = { r: std.color.r, g: std.color.g, b: std.color.b, a: 1 };
    if (typeof std.roughness === 'number') out.roughness = clamp01(std.roughness);
    if (typeof std.metalness === 'number') out.metallic = clamp01(std.metalness);
    if (std.emissive) {
        const ei = typeof std.emissiveIntensity === 'number' ? std.emissiveIntensity : 1;
        out.emission = {
            r: clamp01(std.emissive.r * ei),
            g: clamp01(std.emissive.g * ei),
            b: clamp01(std.emissive.b * ei),
            a: 1,
        };
    }
    // Only trust opacity when the material is actually flagged transparent —
    // opaque glTF materials leave opacity at 1 but it is meaningful either way.
    out.alpha = mat.transparent && typeof std.opacity === 'number' ? clamp01(std.opacity) : 1;

    const phys = mat as THREE.MeshPhysicalMaterial;
    if (typeof phys.clearcoat === 'number') out.clearcoat = clamp01(phys.clearcoat);
    if (typeof phys.clearcoatRoughness === 'number') out.clearcoatRoughness = clamp01(phys.clearcoatRoughness);

    return out;
}

/**
 * Rasterize a three.js texture's decoded image to a PNG data URL. Returns null
 * when there is no drawable image or no 2D canvas is available (e.g. a headless
 * test environment) — the import then simply keeps the flat albedo colour.
 */
export function textureToDataUrl(tex: THREE.Texture | null | undefined): string | null {
    const img = tex?.image as
        | HTMLImageElement
        | HTMLCanvasElement
        | ImageBitmap
        | { width?: number; height?: number }
        | undefined;
    const width = (img as { width?: number })?.width ?? 0;
    const height = (img as { height?: number })?.height ?? 0;
    if (!img || !width || !height || typeof document === 'undefined') return null;

    try {
        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        const ctx = canvas.getContext('2d');
        if (!ctx) return null;
        ctx.drawImage(img as CanvasImageSource, 0, 0);
        return canvas.toDataURL('image/png');
    } catch {
        return null;
    }
}

/** Filesystem-friendly fragment for an asset id: keep it short and word-safe. */
function slug(value: string): string {
    return value.trim().replace(/[^A-Za-z0-9_\-]+/g, '_').replace(/^_+|_+$/g, '');
}

/**
 * Split a loaded glTF scene into one part per material. Every mesh primitive is
 * grouped by its material; each group is merged into a single geometry, and all
 * groups share one normalization transform so they stay aligned. GLTFLoader
 * emits one material per primitive, so multi-material arrays are not expected;
 * if one appears we fall back to its first entry for the whole primitive.
 */
function gltfToParts(root: THREE.Object3D, base: string): ImportedMeshPart[] {
    root.updateMatrixWorld(true);

    const groups = new Map<string, { material: THREE.Material; geos: THREE.BufferGeometry[] }>();
    const order: string[] = [];

    root.traverse((o) => {
        const mesh = o as THREE.Mesh;
        if (!mesh.isMesh || !mesh.geometry) return;
        const material = (Array.isArray(mesh.material) ? mesh.material[0] : mesh.material) as THREE.Material;
        const key = material?.uuid ?? 'none';
        let group = groups.get(key);
        if (!group) {
            group = { material, geos: [] };
            groups.set(key, group);
            order.push(key);
        }
        const g = toMergeable(mesh.geometry.clone());
        g.applyMatrix4(mesh.matrixWorld);
        group.geos.push(g);
    });

    if (order.length === 0) throw new Error('No mesh geometry found in the file');

    // One shared transform across every group keeps the sub-meshes aligned.
    normalizeGeometries(order.flatMap((k) => groups.get(k)!.geos));

    const multi = order.length > 1;
    return order.map((key, i) => {
        const { material, geos } = groups.get(key)!;
        const matName = slug(material?.name ?? '') || `material_${i}`;
        const name = multi ? `${base}_${matName}` : base;

        const dataUrl = textureToDataUrl((material as THREE.MeshStandardMaterial)?.map);
        return {
            name,
            mesh: geometryToRawMesh(mergeOrSingle(geos)),
            material: material ? materialDataFromThree(material, name) : null,
            texture: dataUrl ? { suggestedName: `${name}_albedo`, dataUrl } : null,
        };
    });
}

// ── entry point ──────────────────────────────────────────────────

function parseGltf(buf: ArrayBuffer, base: string): Promise<ImportedMeshPart[]> {
    return new Promise((resolve, reject) => {
        gltfLoader().parse(
            buf,
            '',
            (result) => {
                try {
                    resolve(gltfToParts(result.scene, base));
                } catch (e) {
                    reject(e as Error);
                }
            },
            (err) => reject(err as unknown as Error),
        );
    });
}

/**
 * Parse a File into one or more importable parts, dispatching on its extension.
 * OBJ/STL yield a single geometry-only part; glTF/GLB yield one part per
 * material with translated {@link MaterialData} and any base-colour texture.
 */
export async function importMeshParts(file: File): Promise<ImportedMeshPart[]> {
    const ext = file.name.split('.').pop()?.toLowerCase() ?? '';
    const base = slug(file.name.replace(/\.[^.]+$/, '')) || 'imported';

    if (ext === 'obj') {
        const geo = collectMeshGeometry(new OBJLoader().parse(await file.text()));
        normalizeGeometry(geo);
        return [{ name: base, mesh: geometryToRawMesh(geo), material: null, texture: null }];
    }
    if (ext === 'stl') {
        const geo = toMergeable(new STLLoader().parse(await file.arrayBuffer()));
        normalizeGeometry(geo);
        return [{ name: base, mesh: geometryToRawMesh(geo), material: null, texture: null }];
    }
    if (ext === 'gltf' || ext === 'glb') {
        return parseGltf(await file.arrayBuffer(), base);
    }
    throw new Error(`Unsupported format: .${ext} (use OBJ, STL or glTF/GLB)`);
}

export const IMPORT_ACCEPT = '.obj,.stl,.gltf,.glb';
