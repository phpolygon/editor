<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import * as THREE from 'three';
import { OrbitControls } from 'three/examples/jsm/controls/OrbitControls.js';
import { buildGeometry } from '@/three/meshCache';
import type { MeshData } from '@/types';

/** Focused single-mesh preview: renders one MeshData with orbit controls and a
 * neutral studio material. On-demand rendering keeps it idle when static. */
const props = defineProps<{ mesh: MeshData | null }>();

const container = ref<HTMLDivElement | null>(null);

let renderer: THREE.WebGLRenderer | null = null;
let scene: THREE.Scene | null = null;
let camera: THREE.PerspectiveCamera | null = null;
let orbit: OrbitControls | null = null;
let material: THREE.MeshStandardMaterial | null = null;
let meshObj: THREE.Mesh | null = null;
let ro: ResizeObserver | null = null;
let raf = 0;
let needsRender = true;

function invalidate() {
    needsRender = true;
}

function updateMesh() {
    if (!scene || !material) return;
    if (meshObj) {
        scene.remove(meshObj);
        meshObj.geometry.dispose();
        meshObj = null;
    }
    if (props.mesh) {
        meshObj = new THREE.Mesh(buildGeometry(props.mesh), material);
        scene.add(meshObj);
    }
    invalidate();
}

function onResize() {
    const el = container.value;
    if (!el || !renderer || !camera) return;
    camera.aspect = el.clientWidth / Math.max(1, el.clientHeight);
    camera.updateProjectionMatrix();
    renderer.setSize(el.clientWidth, el.clientHeight);
    invalidate();
}

function animate() {
    raf = requestAnimationFrame(animate);
    // OrbitControls damping emits a 'change' event (→ invalidate) while it is
    // still settling, so we only need to render when needsRender is set. This
    // keeps the viewport idle when static (no continuous WebGL work), matching
    // SceneViewport3D — continuous rendering otherwise starves screenshotting.
    orbit?.update();
    if (needsRender && renderer && scene && camera) {
        renderer.render(scene, camera);
        needsRender = false;
    }
}

function init() {
    const el = container.value;
    if (!el) return;

    scene = new THREE.Scene();
    scene.background = new THREE.Color(0x17181c);

    camera = new THREE.PerspectiveCamera(50, el.clientWidth / Math.max(1, el.clientHeight), 0.1, 1000);
    camera.position.set(2.6, 2, 3.2);

    renderer = new THREE.WebGLRenderer({ antialias: true });
    renderer.setPixelRatio(window.devicePixelRatio);
    renderer.setSize(el.clientWidth, el.clientHeight);
    el.appendChild(renderer.domElement);

    orbit = new OrbitControls(camera, renderer.domElement);
    orbit.enableDamping = true;
    orbit.addEventListener('change', invalidate);

    scene.add(new THREE.HemisphereLight(0xffffff, 0x2a2f38, 1.0));
    const key = new THREE.DirectionalLight(0xffffff, 1.4);
    key.position.set(3, 5, 2);
    scene.add(key);

    scene.add(new THREE.GridHelper(10, 10, 0x3a3d46, 0x24262c));
    scene.add(new THREE.AxesHelper(1));

    material = new THREE.MeshStandardMaterial({
        color: 0x9aa4b2,
        roughness: 0.55,
        metalness: 0.05,
        side: THREE.DoubleSide,
    });

    updateMesh();

    ro = new ResizeObserver(onResize);
    ro.observe(el);
    animate();
}

watch(() => props.mesh, updateMesh);

onMounted(init);
onBeforeUnmount(() => {
    cancelAnimationFrame(raf);
    ro?.disconnect();
    orbit?.dispose();
    meshObj?.geometry.dispose();
    material?.dispose();
    if (renderer) {
        renderer.dispose();
        // dispose() alone doesn't release the GL context; force it so contexts
        // don't accumulate when switching between WebGL viewports (Scene ↔ Mesh)
        // until the browser hits its context limit.
        renderer.forceContextLoss();
        renderer.domElement.remove();
    }
    renderer = null;
    scene = null;
});
</script>

<template>
    <div ref="container" class="w-full h-full" />
</template>
