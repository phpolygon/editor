<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import * as THREE from 'three';
import { OrbitControls } from 'three/examples/jsm/controls/OrbitControls.js';
import { TransformControls } from 'three/examples/jsm/controls/TransformControls.js';
import { useSceneStore } from '@/stores/scene';
import { useSelectionStore } from '@/stores/selection';
import { EntitySync } from '@/three/entitySync';

const TRANSFORM3D_CLASS = 'PHPolygon\\Component\\Transform3D';

const sceneStore = useSceneStore();
const selectionStore = useSelectionStore();
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

function resize(): void {
    if (!renderer || !camera || !container.value) return;
    const w = container.value.clientWidth;
    const h = container.value.clientHeight;
    if (w === 0 || h === 0) return;
    renderer.setSize(w, h, false);
    camera.aspect = w / h;
    camera.updateProjectionMatrix();
}

function renderFrame(): void {
    if (!renderer || !scene || !camera) return;
    orbit?.update();
    outline?.update();
    renderer.render(scene, camera);
    rafHandle = requestAnimationFrame(renderFrame);
}

function pickerCoords(event: PointerEvent): { x: number; y: number } {
    const rect = renderer!.domElement.getBoundingClientRect();
    return {
        x: ((event.clientX - rect.left) / rect.width) * 2 - 1,
        y: -((event.clientY - rect.top) / rect.height) * 2 + 1,
    };
}

function onPointerDown(event: PointerEvent): void {
    if (event.button !== 0) return;
    pointerDownAt = { x: event.clientX, y: event.clientY };
}

function onPointerUp(event: PointerEvent): void {
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

function setup(): void {
    if (!container.value) return;

    scene = new THREE.Scene();
    scene.background = new THREE.Color(0x1e1e1e);

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

    sync = new EntitySync(entityRoot);
    sync.sync(sceneStore.entities);

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
    });
    const gizmoHelper = gizmo.getHelper();
    if (gizmoHelper) scene.add(gizmoHelper);

    renderer.domElement.addEventListener('pointerdown', onPointerDown);
    renderer.domElement.addEventListener('pointerup', onPointerUp);
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
        renderer.domElement.remove();
        renderer = null;
    }
    scene = null;
    camera = null;
    entityRoot = null;
}

watch(
    () => sceneStore.entities,
    (entities) => {
        if (suppressSyncWhileDragging) return;
        sync?.sync(entities);
        refreshOutline();
        attachGizmo();
    },
    { deep: true },
);

watch(
    () => selectionStore.selectedEntity,
    () => {
        attachGizmo();
        refreshOutline();
    },
);

onMounted(setup);
onBeforeUnmount(cleanup);
</script>

<template>
    <div ref="container" class="w-full h-full overflow-hidden bg-editor-panel" />
</template>
