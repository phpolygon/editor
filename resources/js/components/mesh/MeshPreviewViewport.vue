<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import * as THREE from 'three';
import { OrbitControls } from 'three/examples/jsm/controls/OrbitControls.js';
import { TransformControls } from 'three/examples/jsm/controls/TransformControls.js';
import { buildGeometry } from '@/three/meshCache';
import { weldPositions, setGroupPosition, type PositionGroup, type RawMeshData } from '@/mesh/editMesh';
import type { MeshData } from '@/types';

/**
 * Single-mesh preview with an optional vertex-editing mode. In edit mode it
 * renders `editMesh`, draws one draggable handle per welded corner, and moves
 * every coincident vertex together (so the surface doesn't tear), emitting the
 * new vertex array. On-demand rendering keeps it idle when static.
 */
const props = defineProps<{
    mesh: MeshData | null;
    editMode?: boolean;
    editMesh?: RawMeshData | null;
}>();

const emit = defineEmits<{ 'update:vertices': [number[]] }>();

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

// Vertex-editing state.
let handles: THREE.Points | null = null;
let gizmo: TransformControls | null = null;
let proxy: THREE.Object3D | null = null;
let weldGroups: PositionGroup[] = [];
let workingVertices: number[] = [];
let selected = -1;
const raycaster = new THREE.Raycaster();
raycaster.params.Points = { threshold: 0.08 };
const pointer = new THREE.Vector2();

function invalidate() {
    needsRender = true;
}

// ── Preview mesh (non-edit) ────────────────────────────────────────────────
function displayGeometry(): THREE.BufferGeometry | null {
    if (props.editMode && props.editMesh) {
        return buildGeometry(props.editMesh as unknown as MeshData);
    }
    return props.mesh ? buildGeometry(props.mesh) : null;
}

function updateMesh() {
    if (!scene || !material) return;
    if (meshObj) {
        scene.remove(meshObj);
        meshObj.geometry.dispose();
        meshObj = null;
    }
    const geo = displayGeometry();
    if (geo) {
        meshObj = new THREE.Mesh(geo, material);
        scene.add(meshObj);
    }
    invalidate();
}

// ── Vertex handles ─────────────────────────────────────────────────────────
function buildHandles() {
    teardownHandles();
    if (!scene || !props.editMesh) return;

    workingVertices = [...props.editMesh.vertices];
    weldGroups = weldPositions(workingVertices);

    const positions = new Float32Array(weldGroups.length * 3);
    weldGroups.forEach((g, i) => {
        positions[i * 3] = g.position[0];
        positions[i * 3 + 1] = g.position[1];
        positions[i * 3 + 2] = g.position[2];
    });
    const geo = new THREE.BufferGeometry();
    geo.setAttribute('position', new THREE.BufferAttribute(positions, 3));
    handles = new THREE.Points(
        geo,
        new THREE.PointsMaterial({ color: 0x4c8dff, size: 10, sizeAttenuation: false, depthTest: false }),
    );
    handles.renderOrder = 2;
    scene.add(handles);

    proxy = new THREE.Object3D();
    scene.add(proxy);
    invalidate();
}

function teardownHandles() {
    detachGizmo();
    if (handles && scene) {
        scene.remove(handles);
        handles.geometry.dispose();
        (handles.material as THREE.Material).dispose();
        handles = null;
    }
    if (proxy && scene) {
        scene.remove(proxy);
        proxy = null;
    }
    weldGroups = [];
    selected = -1;
    invalidate();
}

function attachGizmo(groupIndex: number) {
    if (!proxy || !gizmo) return;
    selected = groupIndex;
    const g = weldGroups[groupIndex];
    proxy.position.set(g.position[0], g.position[1], g.position[2]);
    gizmo.attach(proxy);
    invalidate();
}

function detachGizmo() {
    gizmo?.detach();
    selected = -1;
    invalidate();
}

function onPointerDown(ev: PointerEvent) {
    if (!props.editMode || !handles || !camera || !renderer) return;
    // Ignore clicks on the transform gizmo itself.
    if (gizmo?.dragging) return;

    const rect = renderer.domElement.getBoundingClientRect();
    pointer.x = ((ev.clientX - rect.left) / rect.width) * 2 - 1;
    pointer.y = -((ev.clientY - rect.top) / rect.height) * 2 + 1;
    raycaster.setFromCamera(pointer, camera);

    const hits = raycaster.intersectObject(handles, false);
    if (hits.length > 0 && hits[0].index !== undefined) {
        attachGizmo(hits[0].index);
    } else {
        detachGizmo();
    }
}

function onGizmoChange() {
    if (selected < 0 || !proxy || !meshObj) return;
    const g = weldGroups[selected];
    setGroupPosition(workingVertices, g.indices, proxy.position.x, proxy.position.y, proxy.position.z);
    g.position = [proxy.position.x, proxy.position.y, proxy.position.z];

    // Live-update the rendered geometry's positions.
    const posAttr = meshObj.geometry.getAttribute('position') as THREE.BufferAttribute;
    for (const vi of g.indices) {
        posAttr.setXYZ(vi, proxy.position.x, proxy.position.y, proxy.position.z);
    }
    posAttr.needsUpdate = true;
    meshObj.geometry.computeVertexNormals();

    // Move the handle point too.
    if (handles) {
        const hp = handles.geometry.getAttribute('position') as THREE.BufferAttribute;
        hp.setXYZ(selected, proxy.position.x, proxy.position.y, proxy.position.z);
        hp.needsUpdate = true;
    }
    invalidate();
}

function commitEdit() {
    emit('update:vertices', [...workingVertices]);
}

// ── Lifecycle ──────────────────────────────────────────────────────────────
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

    gizmo = new TransformControls(camera, renderer.domElement);
    gizmo.setMode('translate');
    gizmo.addEventListener('change', invalidate);
    gizmo.addEventListener('objectChange', onGizmoChange);
    gizmo.addEventListener('dragging-changed', (e) => {
        if (orbit) orbit.enabled = !(e as unknown as { value: boolean }).value;
        if (!(e as unknown as { value: boolean }).value) commitEdit();
    });
    scene.add(gizmo.getHelper ? gizmo.getHelper() : (gizmo as unknown as THREE.Object3D));

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

    renderer.domElement.addEventListener('pointerdown', onPointerDown);

    updateMesh();
    if (props.editMode) buildHandles();

    ro = new ResizeObserver(onResize);
    ro.observe(el);
    animate();
}

watch(() => props.mesh, updateMesh);
watch(
    () => props.editMode,
    (on) => {
        updateMesh();
        if (on) buildHandles();
        else teardownHandles();
    },
);
// Rebuild handles when the edited mesh identity changes (load / flip).
watch(
    () => props.editMesh,
    () => {
        if (props.editMode) {
            updateMesh();
            buildHandles();
        }
    },
);

onMounted(init);
onBeforeUnmount(() => {
    cancelAnimationFrame(raf);
    ro?.disconnect();
    renderer?.domElement.removeEventListener('pointerdown', onPointerDown);
    teardownHandles();
    gizmo?.dispose();
    orbit?.dispose();
    meshObj?.geometry.dispose();
    material?.dispose();
    if (renderer) {
        renderer.dispose();
        // dispose() alone doesn't release the GL context; force it so contexts
        // don't accumulate when switching between WebGL viewports.
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
