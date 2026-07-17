<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue';
import * as THREE from 'three';
import { OrbitControls } from 'three/examples/jsm/controls/OrbitControls.js';
import { generateFragmentShader, VERTEX_SHADER, type ShaderGraph } from '@/shader/shaderGraph';

/** Live preview of a generated GLSL shader on a sphere, with an animated uTime
 * uniform. Recompiles when the graph changes; surfaces compile errors. */
const props = defineProps<{ graph: ShaderGraph }>();
const emit = defineEmits<{ error: [string | null] }>();

let container: HTMLDivElement | null = null;
let renderer: THREE.WebGLRenderer | null = null;
let scene: THREE.Scene | null = null;
let camera: THREE.PerspectiveCamera | null = null;
let orbit: OrbitControls | null = null;
let sphere: THREE.Mesh | null = null;
let material: THREE.ShaderMaterial | null = null;
let ro: ResizeObserver | null = null;
let raf = 0;
let elapsed = 0;
let last = 0;

function rebuild() {
    if (!sphere || !renderer) return;
    const fragmentShader = generateFragmentShader(props.graph);
    const next = new THREE.ShaderMaterial({
        vertexShader: VERTEX_SHADER,
        fragmentShader,
        uniforms: { uTime: { value: elapsed } },
        side: THREE.DoubleSide,
    });
    // Detect a compile failure and report it (three logs to console otherwise).
    renderer.getContext(); // ensure context is live
    const old = material;
    material = next;
    sphere.material = next;
    old?.dispose();
    emit('error', null);
}

function onResize() {
    if (!container || !renderer || !camera) return;
    camera.aspect = container.clientWidth / Math.max(1, container.clientHeight);
    camera.updateProjectionMatrix();
    renderer.setSize(container.clientWidth, container.clientHeight);
}

function animate(now: number) {
    raf = requestAnimationFrame(animate);
    if (!last) last = now;
    elapsed += (now - last) / 1000;
    last = now;
    if (material) material.uniforms.uTime.value = elapsed;
    orbit?.update();
    if (renderer && scene && camera) renderer.render(scene, camera);
}

function init(el: HTMLDivElement) {
    container = el;
    scene = new THREE.Scene();
    scene.background = new THREE.Color(0x17181c);

    camera = new THREE.PerspectiveCamera(45, el.clientWidth / Math.max(1, el.clientHeight), 0.1, 100);
    camera.position.set(0, 0, 3.2);

    renderer = new THREE.WebGLRenderer({ antialias: true });
    renderer.setPixelRatio(window.devicePixelRatio);
    renderer.setSize(el.clientWidth, el.clientHeight);
    // Report shader compile errors to the UI instead of only the console.
    renderer.debug.onShaderError = (_gl, _prog, _vs, fs) => {
        emit('error', 'Shader compile error — check the graph.');
    };
    el.appendChild(renderer.domElement);

    orbit = new OrbitControls(camera, renderer.domElement);
    orbit.enableDamping = true;

    sphere = new THREE.Mesh(new THREE.SphereGeometry(1, 48, 32), new THREE.MeshBasicMaterial());
    scene.add(sphere);
    rebuild();

    ro = new ResizeObserver(onResize);
    ro.observe(el);
    raf = requestAnimationFrame(animate);
}

watch(() => props.graph, rebuild, { deep: true });

onBeforeUnmount(() => {
    cancelAnimationFrame(raf);
    ro?.disconnect();
    orbit?.dispose();
    material?.dispose();
    sphere?.geometry.dispose();
    if (renderer) {
        renderer.dispose();
        renderer.forceContextLoss();
        renderer.domElement.remove();
    }
    renderer = null;
    scene = null;
});

const mount = (el: Element | null) => {
    if (el && !renderer) init(el as HTMLDivElement);
};
</script>

<template>
    <div :ref="mount" class="w-full h-full" />
</template>
