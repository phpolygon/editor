<script setup lang="ts">
import { onBeforeUnmount, watch } from 'vue';
import * as THREE from 'three';
import { OrbitControls } from 'three/examples/jsm/controls/OrbitControls.js';
import { RoomEnvironment } from 'three/examples/jsm/environments/RoomEnvironment.js';
import { buildMaterial } from '@/three/materialCache';
import type { MaterialData } from '@/types';

/** Live material preview: a sphere lit by a neutral studio environment (so
 * metallic/clearcoat read correctly), rebuilding the material whenever the
 * MaterialData changes. On-demand rendering. */
const props = defineProps<{ material: MaterialData }>();

let container: HTMLDivElement | null = null;
let renderer: THREE.WebGLRenderer | null = null;
let scene: THREE.Scene | null = null;
let camera: THREE.PerspectiveCamera | null = null;
let orbit: OrbitControls | null = null;
let sphere: THREE.Mesh | null = null;
let ro: ResizeObserver | null = null;
let raf = 0;
let needsRender = true;

function invalidate() {
    needsRender = true;
}

function applyMaterial() {
    if (!sphere) return;
    const old = sphere.material as THREE.Material;
    sphere.material = buildMaterial(props.material);
    old.dispose();
    invalidate();
}

function onResize() {
    if (!container || !renderer || !camera) return;
    camera.aspect = container.clientWidth / Math.max(1, container.clientHeight);
    camera.updateProjectionMatrix();
    renderer.setSize(container.clientWidth, container.clientHeight);
    invalidate();
}

function animate() {
    raf = requestAnimationFrame(animate);
    orbit?.update();
    if (needsRender && renderer && scene && camera) {
        renderer.render(scene, camera);
        needsRender = false;
    }
}

function init(el: HTMLDivElement) {
    container = el;
    scene = new THREE.Scene();
    scene.background = new THREE.Color(0x17181c);

    camera = new THREE.PerspectiveCamera(45, el.clientWidth / Math.max(1, el.clientHeight), 0.1, 100);
    camera.position.set(0, 0, 3.4);

    renderer = new THREE.WebGLRenderer({ antialias: true });
    renderer.setPixelRatio(window.devicePixelRatio);
    renderer.setSize(el.clientWidth, el.clientHeight);
    el.appendChild(renderer.domElement);

    // Neutral studio IBL so metallic/clearcoat have something to reflect.
    const pmrem = new THREE.PMREMGenerator(renderer);
    scene.environment = pmrem.fromScene(new RoomEnvironment(), 0.04).texture;

    orbit = new OrbitControls(camera, renderer.domElement);
    orbit.enableDamping = true;
    orbit.addEventListener('change', invalidate);

    sphere = new THREE.Mesh(new THREE.SphereGeometry(1, 64, 48), buildMaterial(props.material));
    scene.add(sphere);

    ro = new ResizeObserver(onResize);
    ro.observe(el);
    animate();
}

watch(() => props.material, applyMaterial, { deep: true });

onBeforeUnmount(() => {
    cancelAnimationFrame(raf);
    ro?.disconnect();
    orbit?.dispose();
    (sphere?.material as THREE.Material | undefined)?.dispose();
    sphere?.geometry.dispose();
    if (renderer) {
        renderer.dispose();
        renderer.forceContextLoss();
        renderer.domElement.remove();
    }
    renderer = null;
    scene = null;
});

function mount(el: unknown) {
    if (el instanceof HTMLElement && !renderer) init(el as HTMLDivElement);
}
</script>

<template>
    <div :ref="mount" class="w-full h-full" />
</template>
