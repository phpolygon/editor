<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import * as THREE from 'three';
import { MapControls } from 'three/examples/jsm/controls/MapControls.js';
import { useSceneStore } from '@/stores/scene';
import { useSelectionStore } from '@/stores/selection';
import { SpriteSync } from '@/three/spriteSync';

const sceneStore = useSceneStore();
const selectionStore = useSelectionStore();
const container = ref<HTMLDivElement | null>(null);

let renderer: THREE.WebGLRenderer | null = null;
let scene: THREE.Scene | null = null;
let camera: THREE.OrthographicCamera | null = null;
let controls: MapControls | null = null;
let entityRoot: THREE.Group | null = null;
let outline: THREE.BoxHelper | null = null;
let sync: SpriteSync | null = null;
let resizeObserver: ResizeObserver | null = null;
let rafHandle = 0;
let pointerDownAt: { x: number; y: number } | null = null;

const VIEW_HEIGHT = 600;

function resize(): void {
    if (!renderer || !camera || !container.value) return;
    const w = container.value.clientWidth;
    const h = container.value.clientHeight;
    if (w === 0 || h === 0) return;
    renderer.setSize(w, h, false);
    const aspect = w / h;
    const halfH = VIEW_HEIGHT / 2;
    const halfW = halfH * aspect;
    camera.left = -halfW;
    camera.right = halfW;
    camera.top = halfH;
    camera.bottom = -halfH;
    camera.updateProjectionMatrix();
}

function renderFrame(): void {
    if (!renderer || !scene || !camera) return;
    controls?.update();
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
    if (dx * dx + dy * dy > 16) return;

    if (!sync || !entityRoot || !camera || !renderer) return;
    const ndc = pickerCoords(event);
    const raycaster = new THREE.Raycaster();
    raycaster.setFromCamera(new THREE.Vector2(ndc.x, ndc.y), camera);
    const hits = raycaster.intersectObjects(entityRoot.children, true);
    const hit = hits.find((h) => sync!.entityNameFor(h.object));
    selectionStore.selectEntity(hit ? sync.entityNameFor(hit.object) : null);
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

function setup(): void {
    if (!container.value) return;

    scene = new THREE.Scene();
    scene.background = new THREE.Color(0x1a1a1a);

    camera = new THREE.OrthographicCamera(-1, 1, 1, -1, -1000, 1000);
    camera.position.set(0, 0, 10);
    camera.lookAt(0, 0, 0);

    renderer = new THREE.WebGLRenderer({ antialias: true });
    renderer.setPixelRatio(window.devicePixelRatio);
    renderer.outputColorSpace = THREE.SRGBColorSpace;
    container.value.appendChild(renderer.domElement);

    controls = new MapControls(camera, renderer.domElement);
    controls.enableRotate = false;
    controls.screenSpacePanning = true;
    controls.enableDamping = true;
    controls.dampingFactor = 0.1;

    const gridSize = 1000;
    const grid = new THREE.GridHelper(gridSize, 50, 0x444444, 0x2a2a2a);
    grid.rotation.x = Math.PI / 2;
    (grid.material as THREE.Material).transparent = true;
    (grid.material as THREE.Material).opacity = 0.35;
    scene.add(grid);

    // Origin marker — distinguishes (0,0) from the rest of the grid.
    const originGeom = new THREE.RingGeometry(3, 5, 16);
    const originMat = new THREE.MeshBasicMaterial({ color: 0xfca73a });
    const origin = new THREE.Mesh(originGeom, originMat);
    scene.add(origin);

    entityRoot = new THREE.Group();
    entityRoot.name = '__entities';
    scene.add(entityRoot);

    sync = new SpriteSync(entityRoot);
    sync.sync(sceneStore.entities);

    renderer.domElement.addEventListener('pointerdown', onPointerDown);
    renderer.domElement.addEventListener('pointerup', onPointerUp);

    resize();
    resizeObserver = new ResizeObserver(resize);
    resizeObserver.observe(container.value);

    refreshOutline();
    rafHandle = requestAnimationFrame(renderFrame);
}

function cleanup(): void {
    cancelAnimationFrame(rafHandle);
    rafHandle = 0;
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
    sync?.dispose();
    sync = null;
    controls?.dispose();
    controls = null;
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
        sync?.sync(entities);
        refreshOutline();
    },
    { deep: true },
);

watch(
    () => selectionStore.selectedEntity,
    refreshOutline,
);

onMounted(setup);
onBeforeUnmount(cleanup);
</script>

<template>
    <div ref="container" class="w-full h-full overflow-hidden bg-editor-panel" />
</template>
