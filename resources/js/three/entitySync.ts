import * as THREE from 'three';
import { loadMesh, placeholderGeometry } from './meshCache';
import { loadMaterial, placeholderMaterial } from './materialCache';
import { previewProceduralMesh } from './proceduralPreview';
import { heightmapFromEncoded } from '@/terrain/heightmap';
import { buildTerrainGeometry } from '@/terrain/terrainMesh';
import type { GraphNode } from '@/prefab/graph';
import type { ComponentData, EntityNode } from '@/types';

/**
 * Default look for a terrain that names no material — a matte earth tone rather
 * than the magenta wireframe placeholder, because an unassigned terrain
 * material is a normal authoring state, not an error.
 */
function terrainMaterial(): THREE.MeshStandardMaterial {
    return new THREE.MeshStandardMaterial({ color: 0x8a9585, roughness: 0.9, metalness: 0 });
}

const LIGHT_CLASSES = new Set([
    'PHPolygon\\Component\\AmbientLight',
    'PHPolygon\\Component\\DirectionalLight',
    'PHPolygon\\Component\\PointLight',
    'PHPolygon\\Component\\SpotLight',
]);

/**
 * Three's forward renderer bakes EVERY scene light into each material's shader,
 * so a scene with hundreds of gameplay lights (a whole city's point lights) blows
 * past the GPU's uniform budget and every material fails to compile — "shader
 * error" en masse, and a black viewport. The editor viewport is a structural
 * preview lit by its own ambient + sun rig (see SceneViewport3D::setup), so it
 * caps how many game lights it actually instantiates. Enough to convey a small
 * hand-built scene's lighting; bounded so a huge game scene still renders.
 */
const MAX_SCENE_LIGHTS = 8;

function findComponent(entity: EntityNode, suffix: string): ComponentData | undefined {
    return entity.components.find((c) => c._class.endsWith('\\' + suffix) || c._class === suffix);
}

function readVec3(value: unknown, fallback: [number, number, number] = [0, 0, 0]): THREE.Vector3 {
    if (value && typeof value === 'object') {
        const v = value as { x?: number; y?: number; z?: number };
        return new THREE.Vector3(v.x ?? fallback[0], v.y ?? fallback[1], v.z ?? fallback[2]);
    }
    return new THREE.Vector3(...fallback);
}

function readQuaternion(value: unknown): THREE.Quaternion {
    if (value && typeof value === 'object') {
        const q = value as { x?: number; y?: number; z?: number; w?: number };
        return new THREE.Quaternion(q.x ?? 0, q.y ?? 0, q.z ?? 0, q.w ?? 1);
    }
    return new THREE.Quaternion();
}

function readColor(value: unknown, fallback = 0xffffff): THREE.Color {
    if (value && typeof value === 'object') {
        const c = value as { r?: number; g?: number; b?: number };
        return new THREE.Color(c.r ?? 1, c.g ?? 1, c.b ?? 1);
    }
    return new THREE.Color(fallback);
}

function applyTransform(obj: THREE.Object3D, transform: ComponentData | undefined): void {
    if (!transform) {
        obj.position.set(0, 0, 0);
        obj.quaternion.identity();
        obj.scale.set(1, 1, 1);
        return;
    }
    obj.position.copy(readVec3(transform.properties.position));
    obj.quaternion.copy(readQuaternion(transform.properties.rotation));
    obj.scale.copy(readVec3(transform.properties.scale, [1, 1, 1]));
}

function buildLight(comp: ComponentData): THREE.Light | null {
    const intensity = typeof comp.properties.intensity === 'number' ? comp.properties.intensity : 1.0;
    const color = readColor(comp.properties.color);

    if (comp._class.endsWith('AmbientLight')) {
        return new THREE.AmbientLight(color, intensity);
    }
    if (comp._class.endsWith('DirectionalLight')) {
        const light = new THREE.DirectionalLight(color, intensity);
        const dir = readVec3(comp.properties.direction, [0, -1, 0]);
        light.position.copy(dir.clone().negate().normalize().multiplyScalar(10));
        light.target.position.set(0, 0, 0);
        return light;
    }
    if (comp._class.endsWith('PointLight')) {
        const radius = typeof comp.properties.radius === 'number' ? comp.properties.radius : 10;
        return new THREE.PointLight(color, intensity, radius);
    }
    if (comp._class.endsWith('SpotLight')) {
        const radius = typeof comp.properties.radius === 'number' ? comp.properties.radius : 10;
        const angle = typeof comp.properties.angle === 'number' ? comp.properties.angle : Math.PI / 6;
        return new THREE.SpotLight(color, intensity, radius, angle);
    }
    return null;
}

function buildCameraGizmo(comp: ComponentData): THREE.Object3D {
    const fov = typeof comp.properties.fov === 'number' ? comp.properties.fov : 60;
    const near = typeof comp.properties.near === 'number' ? comp.properties.near : 0.1;
    const far = Math.min(typeof comp.properties.far === 'number' ? comp.properties.far : 100, 5);
    const cam = new THREE.PerspectiveCamera(fov, 16 / 9, near, far);
    return new THREE.CameraHelper(cam);
}

export interface SyncedEntity {
    name: string;
    object: THREE.Object3D;
    meshChild?: THREE.Mesh;
}

export class EntitySync {
    private readonly root: THREE.Object3D;
    private readonly entities = new Map<string, SyncedEntity>();
    private readonly meshRequests = new Map<string, number>();
    /** Game lights instantiated so far this sync(); capped at MAX_SCENE_LIGHTS. */
    private lightCount = 0;

    /**
     * @param onChanged Called whenever an async mesh/material load applies a
     *   change, so an on-demand renderer can schedule a redraw.
     */
    constructor(root: THREE.Object3D, private readonly onChanged: () => void = () => {}) {
        this.root = root;
    }

    sync(entities: EntityNode[]): void {
        const seen = new Set<string>();
        this.lightCount = 0;
        this.walk(entities, this.root, seen);

        for (const name of [...this.entities.keys()]) {
            if (!seen.has(name)) {
                const synced = this.entities.get(name)!;
                synced.object.parent?.remove(synced.object);
                this.disposeObject(synced.object);
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

    private walk(nodes: EntityNode[], parent: THREE.Object3D, seen: Set<string>): void {
        for (const node of nodes) {
            seen.add(node.name);
            const synced = this.ensureEntity(node, parent);
            this.applyComponents(node, synced);
            this.walk(node.children ?? [], synced.object, seen);
        }
    }

    private ensureEntity(node: EntityNode, parent: THREE.Object3D): SyncedEntity {
        let synced = this.entities.get(node.name);
        if (!synced) {
            const group = new THREE.Group();
            group.name = node.name;
            group.userData.entityName = node.name;
            parent.add(group);
            synced = { name: node.name, object: group };
            this.entities.set(node.name, synced);
        } else if (synced.object.parent !== parent) {
            synced.object.parent?.remove(synced.object);
            parent.add(synced.object);
        }
        return synced;
    }

    private applyComponents(node: EntityNode, synced: SyncedEntity): void {
        const transform3d = findComponent(node, 'Transform3D');
        applyTransform(synced.object, transform3d);

        this.clearAuxChildren(synced);

        const meshRenderer = findComponent(node, 'MeshRenderer');
        const proceduralMesh = findComponent(node, 'ProceduralMesh');
        const terrain = findComponent(node, 'Terrain');
        if (terrain) {
            // At runtime a Terrain builds chunk child entities, which do not
            // exist in the scene document. The viewport therefore renders the
            // terrain itself, straight from the component's heightmap.
            this.applyTerrain(synced, terrain, meshRenderer);
        } else if (proceduralMesh) {
            // Procedural geometry drives the mesh; a MeshRenderer, if present,
            // still supplies material + visibility.
            this.applyProceduralMesh(synced, proceduralMesh, meshRenderer);
        } else if (meshRenderer) {
            this.applyMeshRenderer(synced, meshRenderer);
        } else if (synced.meshChild) {
            synced.object.remove(synced.meshChild);
            synced.meshChild.geometry.dispose();
            synced.meshChild = undefined;
        }

        for (const comp of node.components) {
            if (LIGHT_CLASSES.has(comp._class)) {
                // Cap real lights so a city's worth of point lights doesn't blow
                // the shader's light budget (black viewport); the viewport's own
                // ambient + sun still illuminate everything past the cap.
                if (this.lightCount >= MAX_SCENE_LIGHTS) continue;
                const light = buildLight(comp);
                if (light) {
                    synced.object.add(light);
                    this.lightCount++;
                }
            } else if (comp._class.endsWith('Camera3DComponent') || comp._class.endsWith('Camera3D')) {
                synced.object.add(buildCameraGizmo(comp));
            }
        }
    }

    private clearAuxChildren(synced: SyncedEntity): void {
        const toRemove: THREE.Object3D[] = [];
        for (const child of synced.object.children) {
            if (child instanceof THREE.Light || child instanceof THREE.CameraHelper) {
                toRemove.push(child);
            }
        }
        for (const c of toRemove) {
            synced.object.remove(c);
            this.disposeObject(c);
        }
    }

    private applyMeshRenderer(synced: SyncedEntity, comp: ComponentData): void {
        const meshId = typeof comp.properties.meshId === 'string' ? comp.properties.meshId : '';
        const materialId = typeof comp.properties.materialId === 'string' ? comp.properties.materialId : '';
        const visible = comp.properties.visible !== false;

        this.ensureMeshChild(synced);
        synced.meshChild!.visible = visible;

        const reqId = (this.meshRequests.get(synced.name) ?? 0) + 1;
        this.meshRequests.set(synced.name, reqId);

        if (meshId) {
            void loadMesh(meshId).then((geom) => {
                if (this.meshRequests.get(synced.name) !== reqId) return;
                if (!synced.meshChild) return;
                synced.meshChild.geometry = geom ?? placeholderGeometry();
                this.onChanged();
            });
        }

        if (materialId) {
            void loadMaterial(materialId).then((mat) => {
                if (this.meshRequests.get(synced.name) !== reqId) return;
                if (!synced.meshChild) return;
                synced.meshChild.material = mat ?? placeholderMaterial();
                this.onChanged();
            });
        }
    }

    /**
     * Render a Terrain component's heightmap directly.
     *
     * Unlike meshes, this needs no backend round trip: the heightmap travels in
     * the component itself, and the editor shares the engine's geometry
     * algorithm, so the terrain can be built synchronously and stays correct
     * while it is being sculpted in the viewport.
     *
     * Built unchunked — chunking exists for runtime culling, which a single
     * always-visible preview does not benefit from.
     */
    private applyTerrain(
        synced: SyncedEntity,
        comp: ComponentData,
        meshRenderer: ComponentData | undefined,
    ): void {
        const props = comp.properties;
        const numberOr = (value: unknown, fallback: number): number =>
            typeof value === 'number' && Number.isFinite(value) ? value : fallback;

        const options = {
            gridWidth: Math.max(2, Math.round(numberOr(props.gridWidth, 129))),
            gridDepth: Math.max(2, Math.round(numberOr(props.gridDepth, 129))),
            sizeX: numberOr(props.sizeX, 256),
            sizeZ: numberOr(props.sizeZ, 256),
            minHeight: numberOr(props.minHeight, 0),
            maxHeight: numberOr(props.maxHeight, 50),
        };
        const heights = typeof props.heights === 'string' ? props.heights : '';
        const heightmap = heightmapFromEncoded(options, heights);

        const mesh = this.ensureMeshChild(synced);
        mesh.visible = meshRenderer ? meshRenderer.properties.visible !== false : true;
        mesh.userData.terrain = heightmap;

        const geometry = buildTerrainGeometry(heightmap);
        const buffer = new THREE.BufferGeometry();
        buffer.setAttribute('position', new THREE.BufferAttribute(geometry.positions, 3));
        buffer.setAttribute('normal', new THREE.BufferAttribute(geometry.normals, 3));
        buffer.setAttribute('uv', new THREE.BufferAttribute(geometry.uvs, 2));
        buffer.setIndex(new THREE.BufferAttribute(geometry.indices, 1));
        buffer.computeBoundingSphere();

        mesh.geometry.dispose();
        mesh.geometry = buffer;

        const materialId = typeof props.materialId === 'string' && props.materialId !== ''
            ? props.materialId
            : meshRenderer && typeof meshRenderer.properties.materialId === 'string'
              ? meshRenderer.properties.materialId
              : '';

        const reqId = (this.meshRequests.get(synced.name) ?? 0) + 1;
        this.meshRequests.set(synced.name, reqId);

        if (materialId) {
            void loadMaterial(materialId).then((mat) => {
                if (this.meshRequests.get(synced.name) !== reqId || !synced.meshChild) return;
                synced.meshChild.material = mat ?? placeholderMaterial();
                this.onChanged();
            });
        } else {
            mesh.material = terrainMaterial();
        }
    }

    private ensureMeshChild(synced: SyncedEntity): THREE.Mesh {
        if (!synced.meshChild) {
            const mesh = new THREE.Mesh(placeholderGeometry(), placeholderMaterial());
            mesh.name = `${synced.name}__mesh`;
            mesh.userData.entityName = synced.name;
            synced.meshChild = mesh;
            synced.object.add(mesh);
        }
        return synced.meshChild;
    }

    private applyProceduralMesh(
        synced: SyncedEntity,
        comp: ComponentData,
        meshRenderer: ComponentData | undefined,
    ): void {
        const nodes = Array.isArray(comp.properties.nodes) ? (comp.properties.nodes as GraphNode[]) : [];
        const output = typeof comp.properties.output === 'string' ? comp.properties.output : '';
        const meshId = typeof comp.properties.meshId === 'string' ? comp.properties.meshId : '';
        const visible = meshRenderer ? meshRenderer.properties.visible !== false : true;

        const mesh = this.ensureMeshChild(synced);
        mesh.visible = visible;

        const reqId = (this.meshRequests.get(synced.name) ?? 0) + 1;
        this.meshRequests.set(synced.name, reqId);

        void previewProceduralMesh(nodes, output, meshId).then((geom) => {
            if (this.meshRequests.get(synced.name) !== reqId || !synced.meshChild) return;
            synced.meshChild.geometry = geom ?? placeholderGeometry();
            this.onChanged();
        });

        const materialId = meshRenderer && typeof meshRenderer.properties.materialId === 'string'
            ? meshRenderer.properties.materialId
            : '';
        if (materialId) {
            void loadMaterial(materialId).then((mat) => {
                if (this.meshRequests.get(synced.name) !== reqId || !synced.meshChild) return;
                synced.meshChild.material = mat ?? placeholderMaterial();
                this.onChanged();
            });
        }
    }

    private disposeObject(obj: THREE.Object3D): void {
        obj.traverse((child) => {
            if (child instanceof THREE.Mesh) {
                child.geometry?.dispose?.();
                const mat = child.material;
                if (Array.isArray(mat)) {
                    mat.forEach((m) => m.dispose());
                } else {
                    mat?.dispose?.();
                }
            }
        });
    }

    dispose(): void {
        for (const synced of this.entities.values()) {
            synced.object.parent?.remove(synced.object);
            this.disposeObject(synced.object);
        }
        this.entities.clear();
    }
}
