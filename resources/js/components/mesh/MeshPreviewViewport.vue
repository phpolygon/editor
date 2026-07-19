<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import * as THREE from 'three';
import { OrbitControls } from 'three/examples/jsm/controls/OrbitControls.js';
import { TransformControls } from 'three/examples/jsm/controls/TransformControls.js';
import { buildGeometry } from '@/three/meshCache';
import {
    weldPositions,
    centroid,
    applyMatrixToGroups,
    type PositionGroup,
    type RawMeshData,
} from '@/mesh/editMesh';
import type { MeshData } from '@/types';

/**
 * Single-mesh preview with an optional vertex-editing mode. In edit mode it
 * renders `editMesh`, draws one handle per welded corner, and supports
 * multi-selection (shift-click to add/remove, shift-drag on empty space for a
 * rubber-band box). The transform gizmo sits at the selection's centroid and
 * translates / rotates / scales every selected corner together (coincident
 * vertices move as one, so the surface doesn't tear), emitting the new vertex
 * array on release. On-demand rendering keeps it idle when static.
 */
const props = withDefaults(
    defineProps<{
        mesh: MeshData | null;
        editMode?: boolean;
        editMesh?: RawMeshData | null;
        transformMode?: 'translate' | 'rotate' | 'scale';
    }>(),
    { transformMode: 'translate' },
);

const emit = defineEmits<{
    'update:vertices': [number[]];
    'update:selectionCount': [number];
}>();

const container = ref<HTMLDivElement | null>(null);
// Rubber-band overlay rectangle, in element-local pixels (null = inactive).
const band = ref<{ x: number; y: number; w: number; h: number } | null>(null);

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
const selectedGroups = new Set<number>();

// Drag snapshot: geometry + pivot captured when a gizmo drag starts, so the
// transform is always computed relative to the drag's origin (not cumulative).
let baseVertices: number[] = [];
const pivotStart = new THREE.Vector3();

// Rubber-band drag bookkeeping.
let bandStart: { x: number; y: number } | null = null;

// Set right before we emit a committed edit, so the resulting `editMesh` change
// (the parent re-deriving it) doesn't rebuild the handles and drop the current
// selection — only external changes (load / flip / enter) should rebuild.
let selfEmit = false;

const raycaster = new THREE.Raycaster();
raycaster.params.Points = { threshold: 0.08 };
const pointer = new THREE.Vector2();

const COLOR_IDLE = new THREE.Color(0x4c8dff);
const COLOR_SELECTED = new THREE.Color(0xffb020);

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
    const colors = new Float32Array(weldGroups.length * 3);
    weldGroups.forEach((g, i) => {
        positions[i * 3] = g.position[0];
        positions[i * 3 + 1] = g.position[1];
        positions[i * 3 + 2] = g.position[2];
        COLOR_IDLE.toArray(colors, i * 3);
    });
    const geo = new THREE.BufferGeometry();
    geo.setAttribute('position', new THREE.BufferAttribute(positions, 3));
    geo.setAttribute('color', new THREE.BufferAttribute(colors, 3));
    handles = new THREE.Points(
        geo,
        new THREE.PointsMaterial({ size: 10, sizeAttenuation: false, depthTest: false, vertexColors: true }),
    );
    handles.renderOrder = 2;
    scene.add(handles);

    proxy = new THREE.Object3D();
    scene.add(proxy);

    selectedGroups.clear();
    emit('update:selectionCount', 0);
    detachGizmo();
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
    selectedGroups.clear();
    emit('update:selectionCount', 0);
    invalidate();
}

function refreshHandleColors() {
    if (!handles) return;
    const attr = handles.geometry.getAttribute('color') as THREE.BufferAttribute;
    for (let i = 0; i < weldGroups.length; i++) {
        (selectedGroups.has(i) ? COLOR_SELECTED : COLOR_IDLE).toArray(attr.array as Float32Array, i * 3);
    }
    attr.needsUpdate = true;
    invalidate();
}

/** Move the gizmo proxy to the selection centroid with identity orientation. */
function syncGizmo() {
    if (!proxy || !gizmo) return;
    if (selectedGroups.size === 0) {
        detachGizmo();
        return;
    }
    const c = centroid(weldGroups, selectedGroups);
    proxy.position.set(c[0], c[1], c[2]);
    proxy.quaternion.identity();
    proxy.scale.set(1, 1, 1);
    gizmo.attach(proxy);
    invalidate();
}

function detachGizmo() {
    gizmo?.detach();
    invalidate();
}

function setSelection(indices: Iterable<number>) {
    selectedGroups.clear();
    for (const i of indices) selectedGroups.add(i);
    emit('update:selectionCount', selectedGroups.size);
    refreshHandleColors();
    syncGizmo();
}

// ── Pointer interaction ─────────────────────────────────────────────────────
function localPointer(ev: PointerEvent): { x: number; y: number; rect: DOMRect } | null {
    if (!renderer) return null;
    const rect = renderer.domElement.getBoundingClientRect();
    return { x: ev.clientX - rect.left, y: ev.clientY - rect.top, rect };
}

function pickHandle(lx: number, ly: number, rect: DOMRect): number | null {
    if (!handles || !camera) return null;
    pointer.x = (lx / rect.width) * 2 - 1;
    pointer.y = -(ly / rect.height) * 2 + 1;
    raycaster.setFromCamera(pointer, camera);
    const hits = raycaster.intersectObject(handles, false);
    return hits.length > 0 && hits[0].index !== undefined ? hits[0].index : null;
}

function onPointerDown(ev: PointerEvent) {
    if (!props.editMode || !handles) return;
    if (gizmo?.dragging) return; // let the gizmo own its own drags

    const p = localPointer(ev);
    if (!p) return;
    const additive = ev.shiftKey || ev.ctrlKey || ev.metaKey;
    const hit = pickHandle(p.x, p.y, p.rect);

    if (hit !== null) {
        if (additive) {
            if (selectedGroups.has(hit)) selectedGroups.delete(hit);
            else selectedGroups.add(hit);
            emit('update:selectionCount', selectedGroups.size);
            refreshHandleColors();
            syncGizmo();
        } else {
            setSelection([hit]);
        }
        return;
    }

    // Empty space: shift-drag starts a rubber-band box; a plain click clears.
    if (additive) {
        beginBand(p.x, p.y);
    } else {
        setSelection([]);
    }
}

function beginBand(x: number, y: number) {
    if (orbit) orbit.enabled = false;
    bandStart = { x, y };
    band.value = { x, y, w: 0, h: 0 };
    window.addEventListener('pointermove', onBandMove);
    window.addEventListener('pointerup', onBandUp);
}

function onBandMove(ev: PointerEvent) {
    if (!bandStart || !renderer) return;
    const rect = renderer.domElement.getBoundingClientRect();
    const x = ev.clientX - rect.left;
    const y = ev.clientY - rect.top;
    band.value = {
        x: Math.min(bandStart.x, x),
        y: Math.min(bandStart.y, y),
        w: Math.abs(x - bandStart.x),
        h: Math.abs(y - bandStart.y),
    };
}

function onBandUp() {
    window.removeEventListener('pointermove', onBandMove);
    window.removeEventListener('pointerup', onBandUp);
    const rect = band.value;
    const start = bandStart;
    bandStart = null;
    band.value = null;
    if (orbit) orbit.enabled = true;
    if (!rect || !camera || !renderer || !start) return;

    // A negligible drag is really a click on empty space → no-op (keep selection).
    if (rect.w < 3 && rect.h < 3) return;

    const domRect = renderer.domElement.getBoundingClientRect();
    const inBox: number[] = [];
    const v = new THREE.Vector3();
    weldGroups.forEach((g, i) => {
        v.set(g.position[0], g.position[1], g.position[2]).project(camera!);
        const sx = (v.x * 0.5 + 0.5) * domRect.width;
        const sy = (-v.y * 0.5 + 0.5) * domRect.height;
        if (sx >= rect.x && sx <= rect.x + rect.w && sy >= rect.y && sy <= rect.y + rect.h) {
            inBox.push(i);
        }
    });
    // Shift-box is additive: union with the current selection.
    for (const i of inBox) selectedGroups.add(i);
    emit('update:selectionCount', selectedGroups.size);
    refreshHandleColors();
    syncGizmo();
}

// ── Gizmo transform ─────────────────────────────────────────────────────────
function onDragStart() {
    baseVertices = [...workingVertices];
    if (proxy) pivotStart.copy(proxy.position);
}

const _m = new THREE.Matrix4();
const _r = new THREE.Matrix4();
const _s = new THREE.Matrix4();

function onGizmoChange() {
    if (selectedGroups.size === 0 || !proxy || !meshObj) return;

    // M = T(pos) · R(quat) · S(scale) · T(-pivotStart) — pivot-relative transform.
    _m.makeTranslation(proxy.position.x, proxy.position.y, proxy.position.z);
    _r.makeRotationFromQuaternion(proxy.quaternion);
    _s.makeScale(proxy.scale.x, proxy.scale.y, proxy.scale.z);
    _m.multiply(_r).multiply(_s);
    _m.multiply(_r.makeTranslation(-pivotStart.x, -pivotStart.y, -pivotStart.z));

    applyMatrixToGroups(baseVertices, workingVertices, weldGroups, selectedGroups, _m.elements);

    // Live-update rendered geometry + handle points for the moved corners.
    const posAttr = meshObj.geometry.getAttribute('position') as THREE.BufferAttribute;
    const handleAttr = handles?.geometry.getAttribute('position') as THREE.BufferAttribute | undefined;
    for (const gi of selectedGroups) {
        const g = weldGroups[gi];
        const first = g.indices[0];
        if (handleAttr) {
            handleAttr.setXYZ(
                gi,
                workingVertices[first * 3],
                workingVertices[first * 3 + 1],
                workingVertices[first * 3 + 2],
            );
        }
        for (const vi of g.indices) {
            posAttr.setXYZ(vi, workingVertices[vi * 3], workingVertices[vi * 3 + 1], workingVertices[vi * 3 + 2]);
        }
    }
    posAttr.needsUpdate = true;
    if (handleAttr) handleAttr.needsUpdate = true;
    meshObj.geometry.computeVertexNormals();
    invalidate();
}

function onDragEnd() {
    // Bake the moved corners into the weld-group positions, then re-centre the
    // gizmo (identity) for the next drag and emit the committed vertices.
    for (const gi of selectedGroups) {
        const g = weldGroups[gi];
        const first = g.indices[0];
        g.position = [
            workingVertices[first * 3],
            workingVertices[first * 3 + 1],
            workingVertices[first * 3 + 2],
        ];
    }
    syncGizmo();
    selfEmit = true;
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
    gizmo.setMode(props.transformMode);
    gizmo.addEventListener('change', invalidate);
    gizmo.addEventListener('objectChange', onGizmoChange);
    gizmo.addEventListener('dragging-changed', (e) => {
        const dragging = (e as unknown as { value: boolean }).value;
        if (orbit) orbit.enabled = !dragging;
        if (dragging) onDragStart();
        else onDragEnd();
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
        // Our own commit already updated the live geometry + handles; rebuilding
        // would just drop the selection. Only rebuild for external changes.
        if (selfEmit) {
            selfEmit = false;
            return;
        }
        if (props.editMode) {
            updateMesh();
            buildHandles();
        }
    },
);
watch(
    () => props.transformMode,
    (mode) => gizmo?.setMode(mode),
);

onMounted(init);
onBeforeUnmount(() => {
    cancelAnimationFrame(raf);
    ro?.disconnect();
    window.removeEventListener('pointermove', onBandMove);
    window.removeEventListener('pointerup', onBandUp);
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
    <div ref="container" class="relative w-full h-full">
        <div
            v-if="band"
            class="absolute border border-editor-accent bg-editor-accent/15 pointer-events-none"
            :style="{ left: band.x + 'px', top: band.y + 'px', width: band.w + 'px', height: band.h + 'px' }"
        />
    </div>
</template>
