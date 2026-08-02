<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import * as THREE from 'three';
import { OrbitControls } from 'three/examples/jsm/controls/OrbitControls.js';
import { TransformControls } from 'three/examples/jsm/controls/TransformControls.js';
import { useSceneStore } from '@/stores/scene';
import { useSelectionStore } from '@/stores/selection';
import { useTerrainEditorStore } from '@/stores/terrainEditor';
import { EntitySync } from '@/three/entitySync';
import { applyBrush, anchorAt, type StrokeAnchor } from '@/terrain/brushes';
import { type Heightmap } from '@/terrain/heightmap';
import { raycastTerrain, updateTerrainGeometry, buildTerrainGeometry, type TerrainGeometry } from '@/terrain/terrainMesh';
import {
    TERRAIN_COMPONENT,
    heightmapFromComponent,
    heightsPropertyValue,
    sculptability,
    worldToTerrainLocal,
} from '@/terrain/sceneSculpt';

const TRANSFORM3D_CLASS = 'PHPolygon\\Component\\Transform3D';

const sceneStore = useSceneStore();
const selectionStore = useSelectionStore();
const terrainStore = useTerrainEditorStore();
const container = ref<HTMLDivElement | null>(null);

let renderer: THREE.WebGLRenderer | null = null;
let scene: THREE.Scene | null = null;
let camera: THREE.PerspectiveCamera | null = null;
let orbit: OrbitControls | null = null;
let gizmo: TransformControls | null = null;
let outline: THREE.BoxHelper | null = null;
let entityRoot: THREE.Group | null = null;
let sync: EntitySync | null = null;
let resizeObserver: ResizeObserver | null = null;
let rafHandle = 0;
let pointerDownAt: { x: number; y: number } | null = null;
let suppressSyncWhileDragging = false;
// The scene we last auto-framed the camera on, so a fresh scene load frames once
// (revealing large game worlds) without resetting the camera on every edit.
let framedScene = '';

// On-demand rendering: only redraw when something actually changed, instead of
// running the full scene (hundreds of objects + large terrain) at 60fps while
// idle. Every change source calls invalidate().
let needsRender = true;
function invalidate(): void {
    needsRender = true;
}

function resize(): void {
    if (!renderer || !camera || !container.value) return;
    const w = container.value.clientWidth;
    const h = container.value.clientHeight;
    if (w === 0 || h === 0) return;
    renderer.setSize(w, h, false);
    camera.aspect = w / h;
    camera.updateProjectionMatrix();
    invalidate();
}

function renderFrame(): void {
    if (!renderer || !scene || !camera) return;
    rafHandle = requestAnimationFrame(renderFrame);
    if (!needsRender) return;

    // Clear before update(): OrbitControls damping emits 'change' during
    // update(), which re-sets the flag and keeps us rendering until it settles.
    needsRender = false;
    orbit?.update();
    outline?.update();
    renderer.render(scene, camera);
}

function pickerCoords(event: PointerEvent): { x: number; y: number } {
    const rect = renderer!.domElement.getBoundingClientRect();
    return {
        x: ((event.clientX - rect.left) / rect.width) * 2 - 1,
        y: -((event.clientY - rect.top) / rect.height) * 2 + 1,
    };
}

// ── In-scene terrain sculpting ──────────────────────────────────────────────
//
// Sculpting the terrain where it sits, against the props already placed on it.
// The working heightmap is decoded from the selected entity's Terrain component
// and written back once per stroke, so each drag is a single undo entry in the
// scene document rather than one per frame.

let sculptMap: Heightmap | null = null;
let sculptGeometry: TerrainGeometry | null = null;
let sculptMesh: THREE.Mesh | null = null;
let sculptOrigin: [number, number, number] = [0, 0, 0];
let sculptAnchor: StrokeAnchor | null = null;
let sculpting = false;
let sculptInvert = false;
let sculptDirty = false;
let lastSculptTime = 0;

/** Whether a pointer event should sculpt rather than select/orbit. */
function sculptActive(): boolean {
    return terrainStore.sceneSculptEnabled && sculptMap !== null;
}

/**
 * Bind the sculpt session to the selected entity's terrain, or clear it.
 *
 * Rebinding on selection change keeps the session honest: sculpting always
 * targets what is selected, and an unsaved stroke cannot leak onto a different
 * entity.
 */
function bindSculptTarget(): void {
    void flushSculpt();
    sculptMap = null;
    sculptGeometry = null;
    sculptMesh = null;
    sculptAnchor = null;

    if (!terrainStore.sceneSculptEnabled || !sync) return;

    const selected = selectionStore.selectedEntity;
    if (!selected) return;

    const entity = sceneStore.viewEntities.find((e) => e.name === selected);
    const check = sculptability(entity);
    if (!check.ok) return;

    sculptMap = heightmapFromComponent(check.component);
    sculptGeometry = buildTerrainGeometry(sculptMap);

    const object = sync.getObject(selected);
    sculptOrigin = object ? [object.position.x, object.position.y, object.position.z] : [0, 0, 0];
    sculptMesh =
        (object?.children.find((c) => c instanceof THREE.Mesh) as THREE.Mesh | undefined) ?? null;
}

/** Terrain-local (x, z) under the pointer, or null when it misses. */
function sculptPointAt(event: PointerEvent): [number, number] | null {
    if (!sculptMap || !camera || !renderer) return null;

    const ndc = pickerCoords(event);
    const raycaster = new THREE.Raycaster();
    raycaster.setFromCamera(new THREE.Vector2(ndc.x, ndc.y), camera);

    // Raycast in the terrain's local space: shift the ray origin by the
    // entity's translation rather than transforming every heightmap sample.
    const origin: [number, number, number] = [
        raycaster.ray.origin.x - sculptOrigin[0],
        raycaster.ray.origin.y - sculptOrigin[1],
        raycaster.ray.origin.z - sculptOrigin[2],
    ];
    const direction: [number, number, number] = [
        raycaster.ray.direction.x,
        raycaster.ray.direction.y,
        raycaster.ray.direction.z,
    ];

    const hit = raycastTerrain(sculptMap, origin, direction);
    if (!hit) return null;

    // Already local, but go through the helper so the convention stays in one
    // place if the transform handling ever grows.
    return worldToTerrainLocal(
        [hit[0] + sculptOrigin[0], hit[1] + sculptOrigin[1], hit[2] + sculptOrigin[2]],
        sculptOrigin,
    );
}

function refreshSculptMesh(): void {
    if (!sculptMap || !sculptGeometry || !sculptMesh) return;

    updateTerrainGeometry(sculptMap, sculptGeometry);
    const attributes = sculptMesh.geometry.attributes;
    if (attributes.position && attributes.normal) {
        (attributes.position.array as Float32Array).set(sculptGeometry.positions);
        (attributes.normal.array as Float32Array).set(sculptGeometry.normals);
        attributes.position.needsUpdate = true;
        attributes.normal.needsUpdate = true;
        sculptMesh.geometry.computeBoundingSphere();
    }
    outline?.update();
    invalidate();
}

/** Persist the stroke into the scene document, once. */
async function flushSculpt(): Promise<void> {
    if (!sculptDirty || !sculptMap) return;
    const selected = selectionStore.selectedEntity;
    if (!selected) return;

    sculptDirty = false;
    const value = heightsPropertyValue(sculptMap);

    // Suppress the resync this triggers: the viewport geometry is already
    // current, and re-syncing mid-session would rebuild the mesh we hold.
    suppressSyncWhileDragging = true;
    try {
        await sceneStore.updateProperty(selected, TERRAIN_COMPONENT, 'heights', value);
    } finally {
        suppressSyncWhileDragging = false;
    }
}

function onSculptPointerDown(event: PointerEvent): boolean {
    if (!sculptActive() || event.button !== 0 || !sculptMap) return false;

    const point = sculptPointAt(event);
    if (!point) return false;

    event.preventDefault();
    sculpting = true;
    sculptInvert = event.altKey;
    lastSculptTime = performance.now();
    sculptAnchor = anchorAt(sculptMap, point[0], point[1]);
    if (orbit) orbit.enabled = false;
    renderer?.domElement.setPointerCapture(event.pointerId);

    applySculptStep(point, 1 / 60);
    return true;
}

function onSculptPointerMove(event: PointerEvent): void {
    if (!sculpting || !sculptMap) return;

    const point = sculptPointAt(event);
    if (!point) return;

    const now = performance.now();
    // Clamp the step so a stall cannot land one huge brush application.
    const dt = Math.min(0.05, (now - lastSculptTime) / 1000);
    lastSculptTime = now;

    applySculptStep(point, dt);
}

function applySculptStep(point: [number, number], dt: number): void {
    if (!sculptMap) return;

    const changed = applyBrush(sculptMap, {
        settings: terrainStore.brush,
        worldX: point[0],
        worldZ: point[1],
        dt,
        invert: sculptInvert,
        anchor: sculptAnchor ?? undefined,
    });

    if (changed) {
        sculptDirty = true;
        refreshSculptMesh();
    }
}

function endSculpt(event?: PointerEvent): boolean {
    if (!sculpting) return false;
    sculpting = false;
    sculptAnchor = null;
    if (orbit) orbit.enabled = true;
    if (event) renderer?.domElement.releasePointerCapture(event.pointerId);
    void flushSculpt();
    return true;
}

function onPointerDown(event: PointerEvent): void {
    if (onSculptPointerDown(event)) return;
    if (event.button !== 0) return;
    pointerDownAt = { x: event.clientX, y: event.clientY };
}

function onPointerUp(event: PointerEvent): void {
    // A sculpt stroke must not fall through into entity picking.
    if (endSculpt(event)) return;
    if (event.button !== 0 || !pointerDownAt) return;
    const dx = event.clientX - pointerDownAt.x;
    const dy = event.clientY - pointerDownAt.y;
    pointerDownAt = null;
    if (dx * dx + dy * dy > 16) return; // dragged, don't pick

    if (!sync || !entityRoot || !camera || !renderer) return;
    const ndc = pickerCoords(event);
    const raycaster = new THREE.Raycaster();
    raycaster.setFromCamera(new THREE.Vector2(ndc.x, ndc.y), camera);
    const hits = raycaster.intersectObjects(entityRoot.children, true);
    const hit = hits.find((h) => sync!.entityNameFor(h.object));
    if (hit) {
        selectionStore.selectEntity(sync.entityNameFor(hit.object));
    } else {
        selectionStore.selectEntity(null);
    }
}

function refreshOutline(): void {
    if (!scene || !sync) return;
    if (outline) {
        scene.remove(outline);
        outline.geometry.dispose();
        outline = null;
    }
    const selected = selectionStore.selectedEntity;
    if (!selected) return;
    const obj = sync.getObject(selected);
    if (!obj) return;
    outline = new THREE.BoxHelper(obj, 0xfca73a);
    outline.update();
    scene.add(outline);
}

function attachGizmo(): void {
    if (!gizmo || !sync) return;
    const selected = selectionStore.selectedEntity;
    if (!selected) {
        gizmo.detach();
        return;
    }
    const obj = sync.getObject(selected);
    if (obj) gizmo.attach(obj);
    else gizmo.detach();
}

async function writeSelectedTransform(): Promise<void> {
    if (!sync) return;
    const selected = selectionStore.selectedEntity;
    if (!selected) return;
    const obj = sync.getObject(selected);
    if (!obj) return;

    const p = { x: obj.position.x, y: obj.position.y, z: obj.position.z };
    const q = { x: obj.quaternion.x, y: obj.quaternion.y, z: obj.quaternion.z, w: obj.quaternion.w };
    const s = { x: obj.scale.x, y: obj.scale.y, z: obj.scale.z };

    // Sequential to avoid race conditions in the backend SceneDocument.
    // Lands as three undo entries today — batched set_transform is a
    // follow-up.
    await sceneStore.updateProperty(selected, TRANSFORM3D_CLASS, 'position', p);
    await sceneStore.updateProperty(selected, TRANSFORM3D_CLASS, 'rotation', q);
    await sceneStore.updateProperty(selected, TRANSFORM3D_CLASS, 'scale', s);
}

function focusOnSelection(): void {
    if (!sync || !camera || !orbit) return;
    const selected = selectionStore.selectedEntity;
    if (!selected) return;
    const obj = sync.getObject(selected);
    if (!obj) return;

    const box = new THREE.Box3().setFromObject(obj);
    if (box.isEmpty()) return;
    const center = box.getCenter(new THREE.Vector3());
    const size = box.getSize(new THREE.Vector3()).length() || 1;

    const offset = camera.position.clone().sub(orbit.target).normalize().multiplyScalar(size * 2);
    orbit.target.copy(center);
    camera.position.copy(center).add(offset);
    camera.lookAt(center);
    orbit.update();
}

/**
 * Frame the camera on the whole scene's bounds. Game worlds span hundreds of
 * units and are authored around a spawn point far from the origin, so the
 * default (5,5,5) camera + far=1000 would stare past everything (an apparently
 * empty viewport). Called once when a fresh scene's entities arrive. Uses the
 * entity bounds — which include every placed prefab's expanded geometry — so the
 * far-flung landmarks/terminals come into view instead of just the spawn.
 */
function frameAll(): boolean {
    if (!entityRoot || !camera || !orbit) return false;
    const box = new THREE.Box3().setFromObject(entityRoot);
    // Empty until the scene's geometry (or its async-expanded prefab preview)
    // has populated the tree — the caller retries on the next entity change.
    if (box.isEmpty()) return false;

    const center = box.getCenter(new THREE.Vector3());
    const size = box.getSize(new THREE.Vector3()).length() || 1;

    const dir = new THREE.Vector3(1, 0.7, 1).normalize();
    orbit.target.copy(center);
    camera.position.copy(center).add(dir.multiplyScalar(size * 0.6));
    // Scale the clip planes to the framed extent so nothing is culled.
    camera.near = Math.max(0.1, size / 2000);
    camera.far = Math.max(1000, size * 4);
    camera.updateProjectionMatrix();
    camera.lookAt(center);
    orbit.update();
    invalidate();
    return true;
}

function onKeyDown(event: KeyboardEvent): void {
    if (!gizmo) return;
    const tag = (event.target as HTMLElement | null)?.tagName;
    if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;

    switch (event.key.toLowerCase()) {
        case 'w': gizmo.setMode('translate'); break;
        case 'e': gizmo.setMode('rotate'); break;
        case 'r': gizmo.setMode('scale'); break;
        case 'f': focusOnSelection(); break;
        case 'escape': selectionStore.selectEntity(null); break;
    }
}

/**
 * A simple vertical gradient sky for the viewport background. The engine draws
 * the in-game sky with an atmospheric shader (no scene mesh), which the editor
 * can't mirror from entity data — so we approximate a daytime sky here instead
 * of leaving a flat void behind the scene.
 */
function makeSkyBackground(): THREE.Color | THREE.Texture {
    const canvas = document.createElement('canvas');
    canvas.width = 2;
    canvas.height = 256;
    const ctx = canvas.getContext('2d');
    if (!ctx) return new THREE.Color(0x1e1e1e);

    const gradient = ctx.createLinearGradient(0, 0, 0, canvas.height);
    gradient.addColorStop(0, '#5b8fd6'); // zenith
    gradient.addColorStop(0.55, '#9fc4e8'); // mid sky
    gradient.addColorStop(1, '#dce9f2'); // horizon haze
    ctx.fillStyle = gradient;
    ctx.fillRect(0, 0, canvas.width, canvas.height);

    const texture = new THREE.CanvasTexture(canvas);
    texture.colorSpace = THREE.SRGBColorSpace;
    return texture;
}

function setup(): void {
    if (!container.value) return;

    scene = new THREE.Scene();
    scene.background = makeSkyBackground();

    camera = new THREE.PerspectiveCamera(60, 1, 0.1, 1000);
    camera.position.set(5, 5, 5);
    camera.lookAt(0, 0, 0);

    renderer = new THREE.WebGLRenderer({ antialias: true });
    renderer.setPixelRatio(window.devicePixelRatio);
    renderer.outputColorSpace = THREE.SRGBColorSpace;
    container.value.appendChild(renderer.domElement);

    orbit = new OrbitControls(camera, renderer.domElement);
    orbit.enableDamping = true;
    orbit.dampingFactor = 0.08;
    orbit.addEventListener('change', invalidate);

    const grid = new THREE.GridHelper(20, 20, 0x444444, 0x2a2a2a);
    (grid.material as THREE.Material).transparent = true;
    (grid.material as THREE.Material).opacity = 0.6;
    scene.add(grid);
    scene.add(new THREE.AxesHelper(2));

    const editorAmbient = new THREE.AmbientLight(0xffffff, 0.35);
    editorAmbient.name = '__editor_ambient';
    scene.add(editorAmbient);

    const editorSun = new THREE.DirectionalLight(0xffffff, 0.9);
    editorSun.position.set(5, 10, 7);
    scene.add(editorSun);

    entityRoot = new THREE.Group();
    entityRoot.name = '__entities';
    scene.add(entityRoot);

    sync = new EntitySync(entityRoot, invalidate);
    sync.sync(sceneStore.viewEntities);
    if (sceneStore.name && sceneStore.viewEntities.length > 0 && frameAll()) {
        framedScene = sceneStore.name;
    }

    gizmo = new TransformControls(camera, renderer.domElement);
    gizmo.setSize(0.8);
    gizmo.addEventListener('dragging-changed', (e) => {
        const dragging = (e as unknown as { value: boolean }).value;
        if (orbit) orbit.enabled = !dragging;
        if (dragging) {
            suppressSyncWhileDragging = true;
        } else {
            void writeSelectedTransform().finally(() => {
                suppressSyncWhileDragging = false;
            });
        }
    });
    gizmo.addEventListener('objectChange', () => {
        outline?.update();
        invalidate();
    });
    const gizmoHelper = gizmo.getHelper();
    if (gizmoHelper) scene.add(gizmoHelper);

    renderer.domElement.addEventListener('pointerdown', onPointerDown);
    renderer.domElement.addEventListener('pointerup', onPointerUp);
    renderer.domElement.addEventListener('pointermove', onSculptPointerMove);
    renderer.domElement.addEventListener('pointercancel', endSculpt);
    window.addEventListener('keydown', onKeyDown);

    resize();
    resizeObserver = new ResizeObserver(resize);
    resizeObserver.observe(container.value);

    attachGizmo();
    refreshOutline();

    rafHandle = requestAnimationFrame(renderFrame);
}

function cleanup(): void {
    cancelAnimationFrame(rafHandle);
    rafHandle = 0;
    window.removeEventListener('keydown', onKeyDown);
    resizeObserver?.disconnect();
    resizeObserver = null;
    if (renderer) {
        renderer.domElement.removeEventListener('pointerdown', onPointerDown);
        renderer.domElement.removeEventListener('pointerup', onPointerUp);
        renderer.domElement.removeEventListener('pointermove', onSculptPointerMove);
        renderer.domElement.removeEventListener('pointercancel', endSculpt);
    }
    if (outline) {
        outline.geometry.dispose();
        outline = null;
    }
    gizmo?.detach();
    gizmo?.dispose();
    gizmo = null;
    sync?.dispose();
    sync = null;
    orbit?.dispose();
    orbit = null;
    if (renderer) {
        renderer.dispose();
        // Release the GL context explicitly; dispose() alone leaves it alive,
        // so contexts leak when switching between WebGL viewports (Scene ↔ Mesh).
        renderer.forceContextLoss();
        renderer.domElement.remove();
        renderer = null;
    }
    scene = null;
    camera = null;
    entityRoot = null;
}

watch(
    () => sceneStore.viewEntities,
    (entities) => {
        if (suppressSyncWhileDragging) return;
        sync?.sync(entities);
        refreshOutline();
        attachGizmo();
        // Frame the camera the first time a freshly loaded scene yields non-empty
        // bounds, so the whole world (incl. its async-expanded prefab geometry)
        // is visible. Retries across entity changes until frameAll() succeeds
        // (the authored anchors alone have no geometry to bound).
        if (sceneStore.name && sceneStore.name !== framedScene && entities.length > 0 && frameAll()) {
            framedScene = sceneStore.name;
        }
        invalidate();
    },
    { deep: true },
);

watch(
    () => selectionStore.selectedEntity,
    () => {
        attachGizmo();
        refreshOutline();
        bindSculptTarget();
        invalidate();
    },
);

// Entering or leaving sculpt mode rebinds; the gizmo is detached while
// sculpting so a drag cannot move the terrain entity instead of shaping it.
watch(
    () => terrainStore.sceneSculptEnabled,
    (enabled) => {
        bindSculptTarget();
        if (enabled) gizmo?.detach();
        else attachGizmo();
        invalidate();
    },
);

onMounted(() => {
    setup();
    bindSculptTarget();
});
onBeforeUnmount(() => {
    void flushSculpt();
    cleanup();
});
</script>

<template>
    <div ref="container" class="w-full h-full overflow-hidden bg-editor-panel" />
</template>
