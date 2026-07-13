import * as THREE from 'three';
import { assetFileUrl } from '@/bridge/commands';
import type { ComponentData, EntityNode } from '@/types';

const textureLoader = new THREE.TextureLoader();
const textureCache = new Map<string, THREE.Texture>();
const textureFailed = new Set<string>();
const sharedGeometry = new THREE.PlaneGeometry(1, 1);

function findComponent(entity: EntityNode, suffix: string): ComponentData | undefined {
    return entity.components.find((c) => c._class.endsWith('\\' + suffix) || c._class === suffix);
}

function readVec2(value: unknown, fallback: [number, number] = [0, 0]): [number, number] {
    if (value && typeof value === 'object') {
        const v = value as { x?: number; y?: number };
        return [v.x ?? fallback[0], v.y ?? fallback[1]];
    }
    return fallback;
}

/**
 * Assign a sprite texture to a material, resolving the id as an asset file.
 * When the id does not resolve to a file — e.g. it is an engine texture-registry
 * id like 'desk' rather than a path — the load fails and we fall back to the
 * sprite's flat color instead of leaving a broken (black) texture on the quad.
 */
function applyTexture(material: THREE.MeshBasicMaterial, path: string): void {
    if (textureFailed.has(path)) {
        clearTexture(material);
        return;
    }
    const cached = textureCache.get(path);
    if (cached) {
        material.map = cached;
        material.needsUpdate = true;
        return;
    }
    const tex = textureLoader.load(
        assetFileUrl(path),
        (loaded) => {
            textureCache.set(path, loaded); // cache for reuse once it resolves
        },
        undefined,
        () => {
            // Unresolved id (e.g. an engine texture-registry id, not a file):
            // drop the broken texture so the sprite shows its flat color.
            textureFailed.add(path);
            if (material.map === tex) clearTexture(material);
        },
    );
    tex.colorSpace = THREE.SRGBColorSpace;
    tex.magFilter = THREE.NearestFilter;
    material.map = tex;
    material.needsUpdate = true;
}

function clearTexture(material: THREE.MeshBasicMaterial): void {
    if (material.map) {
        material.map = null;
        material.needsUpdate = true;
    }
}

export interface SyncedSprite {
    name: string;
    object: THREE.Group;
    mesh: THREE.Mesh<THREE.PlaneGeometry, THREE.MeshBasicMaterial>;
}

export class SpriteSync {
    private readonly root: THREE.Object3D;
    private readonly entities = new Map<string, SyncedSprite>();

    constructor(root: THREE.Object3D) {
        this.root = root;
    }

    sync(entities: EntityNode[]): void {
        const seen = new Set<string>();
        this.walk(entities, this.root, seen);

        for (const name of [...this.entities.keys()]) {
            if (!seen.has(name)) {
                const synced = this.entities.get(name)!;
                synced.object.parent?.remove(synced.object);
                synced.mesh.material.dispose();
                this.entities.delete(name);
            }
        }
    }

    getObject(name: string): THREE.Object3D | null {
        return this.entities.get(name)?.object ?? null;
    }

    entityNameFor(object: THREE.Object3D | null): string | null {
        let cursor: THREE.Object3D | null = object;
        while (cursor) {
            const entityName = cursor.userData?.entityName;
            if (typeof entityName === 'string') return entityName;
            cursor = cursor.parent;
        }
        return null;
    }

    dispose(): void {
        for (const synced of this.entities.values()) {
            synced.object.parent?.remove(synced.object);
            synced.mesh.material.dispose();
        }
        this.entities.clear();
    }

    private walk(nodes: EntityNode[], parent: THREE.Object3D, seen: Set<string>): void {
        for (const node of nodes) {
            seen.add(node.name);
            const synced = this.ensureEntity(node, parent);
            this.applyComponents(node, synced);
            this.walk(node.children ?? [], synced.object, seen);
        }
    }

    private ensureEntity(node: EntityNode, parent: THREE.Object3D): SyncedSprite {
        let synced = this.entities.get(node.name);
        if (!synced) {
            const group = new THREE.Group();
            group.name = node.name;
            group.userData.entityName = node.name;
            const mat = new THREE.MeshBasicMaterial({
                color: 0xffffff,
                transparent: true,
                side: THREE.DoubleSide,
            });
            const mesh = new THREE.Mesh(sharedGeometry, mat);
            mesh.userData.entityName = node.name;
            mesh.visible = false;
            group.add(mesh);
            parent.add(group);
            synced = { name: node.name, object: group, mesh };
            this.entities.set(node.name, synced);
        } else if (synced.object.parent !== parent) {
            synced.object.parent?.remove(synced.object);
            parent.add(synced.object);
        }
        return synced;
    }

    private applyComponents(node: EntityNode, synced: SyncedSprite): void {
        const t2d = findComponent(node, 'Transform2D');
        const [px, py] = readVec2(t2d?.properties.position);
        const [sx, sy] = readVec2(t2d?.properties.scale, [1, 1]);
        const rotDeg = typeof t2d?.properties.rotation === 'number' ? t2d.properties.rotation : 0;
        synced.object.position.set(px, py, 0);
        synced.object.scale.set(sx, sy, 1);
        synced.object.rotation.z = (rotDeg * Math.PI) / 180;

        const sprite = findComponent(node, 'SpriteRenderer');
        if (!sprite) {
            synced.mesh.visible = false;
            return;
        }

        const width = typeof sprite.properties.width === 'number' && sprite.properties.width > 0
            ? sprite.properties.width : 64;
        const height = typeof sprite.properties.height === 'number' && sprite.properties.height > 0
            ? sprite.properties.height : 64;
        const opacity = typeof sprite.properties.opacity === 'number' ? sprite.properties.opacity : 1;
        const layer = typeof sprite.properties.layer === 'number' ? sprite.properties.layer : 0;
        const flipX = sprite.properties.flipX === true;
        const flipY = sprite.properties.flipY === true;

        synced.mesh.visible = true;
        synced.mesh.scale.set(width * (flipX ? -1 : 1), height * (flipY ? -1 : 1), 1);
        synced.mesh.renderOrder = layer;

        const color = sprite.properties.color as { r?: number; g?: number; b?: number } | undefined;
        synced.mesh.material.color.setRGB(color?.r ?? 1, color?.g ?? 1, color?.b ?? 1);
        synced.mesh.material.opacity = opacity;

        const textureId = typeof sprite.properties.textureId === 'string' ? sprite.properties.textureId : '';
        if (textureId) {
            applyTexture(synced.mesh.material, textureId);
        } else {
            clearTexture(synced.mesh.material);
        }
    }
}
