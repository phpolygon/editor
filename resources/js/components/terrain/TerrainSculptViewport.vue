<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import * as THREE from 'three';
import { OrbitControls } from 'three/examples/jsm/controls/OrbitControls.js';
import { useTerrainEditorStore } from '@/stores/terrainEditor';
import {
    buildTerrainGeometry,
    geometryMatches,
    raycastTerrain,
    updateTerrainGeometry,
    type TerrainGeometry,
} from '@/terrain/terrainMesh';
import { splatToVertexWeights } from '@/terrain/splat';
import { layerTintNormalised } from '@/terrain/layerTints';
import { generateScatterInstances } from '@/terrain/scatter';

/**
 * Interactive terrain viewport: orbit to navigate, drag on the surface to
 * sculpt, paint or scatter depending on the active tool.
 *
 * Two decisions drive the implementation:
 *
 * 1. **Brush placement uses a height-field raycast, not three.js mesh
 *    picking.** Marching the ray against the heightmap stays correct while the
 *    geometry is mid-update and costs the same regardless of triangle count,
 *    whereas BVH-based mesh picking would have to be rebuilt every stroke.
 *
 * 2. **Geometry buffers are reused.** A stroke changes heights but never the
 *    grid topology, so positions and normals are rewritten in place and flagged
 *    for re-upload; the BufferGeometry is only rebuilt when the resolution
 *    actually changes.
 *
 * Rendering is on-demand — the loop only draws when something invalidated it —
 * so an idle terrain workspace does not spin the GPU.
 */

const store = useTerrainEditorStore();

const container = ref<HTMLDivElement | null>(null);
/** Live readout under the cursor, shown as an overlay. */
const readout = ref<{ height: number; slope: number } | null>(null);

/**
 * Whether the active tool has anything to act on. Painting with no layers, or
 * scattering with no set, silently does nothing — so the overlay says why
 * instead of leaving the user dragging at an unresponsive terrain.
 */
const toolReady = computed(() => {
    if (store.mode === 'paint') return store.layers.length > 0;
    if (store.mode === 'scatter') return store.scatterSets.length > 0;
    return true;
});

const toolHint = computed(() => {
    if (store.mode === 'paint') {
        return store.layers.length === 0
            ? 'Add a texture layer to paint'
            : `Drag to paint “${store.layers[store.activeLayer]?.name}” · Alt erases · Ctrl+wheel resizes`;
    }
    if (store.mode === 'scatter') {
        return store.scatterSets.length === 0
            ? 'Add a scatter set to paint density'
            : `Drag to scatter “${store.scatterSets[store.activeScatter]?.name}” · Alt thins · Ctrl+wheel resizes`;
    }
    return 'Drag to sculpt · Alt inverts · Ctrl+wheel resizes';
});

let renderer: THREE.WebGLRenderer | null = null;
let scene: THREE.Scene | null = null;
let camera: THREE.PerspectiveCamera | null = null;
let orbit: OrbitControls | null = null;
let terrainMesh: THREE.Mesh | null = null;
let terrainMaterial: THREE.MeshStandardMaterial | null = null;
let brushRing: THREE.Line | null = null;
let scatterPreview: THREE.InstancedMesh | null = null;
let scatterGeometry: THREE.BufferGeometry | null = null;
let scatterMaterial: THREE.MeshStandardMaterial | null = null;
let ro: ResizeObserver | null = null;

let geometry: TerrainGeometry | null = null;
let bufferGeometry: THREE.BufferGeometry | null = null;
let raf = 0;
let needsRender = true;

// Stroke state.
let painting = false;
let invertStroke = false;
let lastStrokeTime = 0;
let pointerNdc = new THREE.Vector2();
let hoverPoint: [number, number, number] | null = null;

const BRUSH_RING_SEGMENTS = 64;

function invalidate() {
    needsRender = true;
}

// ── Terrain geometry ────────────────────────────────────────────────────────

function rebuildGeometry() {
    if (!scene || !terrainMaterial) return;

    geometry = buildTerrainGeometry(store.heightmap);
    bufferGeometry?.dispose();
    bufferGeometry = new THREE.BufferGeometry();
    bufferGeometry.setAttribute('position', new THREE.BufferAttribute(geometry.positions, 3));
    bufferGeometry.setAttribute('normal', new THREE.BufferAttribute(geometry.normals, 3));
    bufferGeometry.setAttribute('uv', new THREE.BufferAttribute(geometry.uvs, 2));
    bufferGeometry.setIndex(new THREE.BufferAttribute(geometry.indices, 1));
    bufferGeometry.computeBoundingSphere();

    if (terrainMesh) scene.remove(terrainMesh);
    terrainMesh = new THREE.Mesh(bufferGeometry, terrainMaterial);
    scene.add(terrainMesh);

    applyLayerColours();
    frameCamera();
    invalidate();
}

function refreshGeometry() {
    if (!geometry || !bufferGeometry || !geometryMatches(store.heightmap, geometry)) {
        rebuildGeometry();
        return;
    }

    updateTerrainGeometry(store.heightmap, geometry);
    bufferGeometry.attributes.position.needsUpdate = true;
    bufferGeometry.attributes.normal.needsUpdate = true;
    bufferGeometry.computeBoundingSphere();
    applyLayerColours();
    invalidate();
}

/**
 * Preview the splat layers as vertex colours.
 *
 * A real multi-layer terrain shader belongs in the engine's rendering path;
 * until a project supplies materials for the layers, blending flat per-layer
 * tints gives an artist the coverage feedback they need to paint against.
 */
function applyLayerColours() {
    if (!bufferGeometry || !terrainMaterial) return;

    const layers = store.layers;
    if (layers.length === 0) {
        if (bufferGeometry.getAttribute('color')) bufferGeometry.deleteAttribute('color');
        terrainMaterial.vertexColors = false;
        terrainMaterial.needsUpdate = true;
        return;
    }

    const weights = splatToVertexWeights(store.splat);
    const vertexCount = store.gridWidth * store.gridDepth;
    const colours = new Float32Array(vertexCount * 3);

    for (let v = 0; v < vertexCount; v++) {
        let r = 0;
        let g = 0;
        let b = 0;
        for (let layer = 0; layer < layers.length; layer++) {
            const w = weights[v * layers.length + layer];
            if (w <= 0) continue;
            const tint = layerTintNormalised(layer);
            r += tint[0] * w;
            g += tint[1] * w;
            b += tint[2] * w;
        }
        colours[v * 3] = r;
        colours[v * 3 + 1] = g;
        colours[v * 3 + 2] = b;
    }

    bufferGeometry.setAttribute('color', new THREE.BufferAttribute(colours, 3));
    terrainMaterial.vertexColors = true;
    terrainMaterial.needsUpdate = true;
}

// ── Scatter preview ─────────────────────────────────────────────────────────

/**
 * Draw scattered objects as instanced boxes.
 *
 * Placeholder geometry rather than the referenced mesh: the scatter set names a
 * mesh id that lives in the *engine's* registry, which the editor viewport does
 * not have. What matters while painting density is where and how many, which
 * boxes convey.
 */
function refreshScatterPreview() {
    if (!scene) return;

    if (scatterPreview) {
        scene.remove(scatterPreview);
        scatterPreview.dispose();
        scatterPreview = null;
    }

    const set = store.scatterSets[store.activeScatter];
    if (!set || store.mode !== 'scatter') {
        invalidate();
        return;
    }

    const instances = generateScatterInstances(set, store.heightmap, 4000);
    if (instances.length === 0) {
        invalidate();
        return;
    }

    if (!scatterGeometry) scatterGeometry = new THREE.BoxGeometry(1, 3, 1);
    if (!scatterMaterial) {
        scatterMaterial = new THREE.MeshStandardMaterial({ color: 0x6c9c5a, roughness: 0.8 });
    }

    scatterPreview = new THREE.InstancedMesh(scatterGeometry, scatterMaterial, instances.length);
    const matrix = new THREE.Matrix4();
    const euler = new THREE.Euler();
    const quaternion = new THREE.Quaternion();
    const scaleVec = new THREE.Vector3();
    const position = new THREE.Vector3();

    instances.forEach((instance, i) => {
        euler.set(instance.rotation[0], instance.rotation[1], instance.rotation[2]);
        quaternion.setFromEuler(euler);
        // Box origin is centred, so lift it by half its height to stand on the
        // surface rather than sink into it.
        position.set(
            instance.position[0],
            instance.position[1] + 1.5 * instance.scale,
            instance.position[2],
        );
        scaleVec.setScalar(instance.scale);
        matrix.compose(position, quaternion, scaleVec);
        scatterPreview!.setMatrixAt(i, matrix);
    });
    scatterPreview.instanceMatrix.needsUpdate = true;
    scene.add(scatterPreview);
    invalidate();
}

// ── Brush ring ──────────────────────────────────────────────────────────────

function buildBrushRing() {
    const positions = new Float32Array((BRUSH_RING_SEGMENTS + 1) * 3);
    const ringGeometry = new THREE.BufferGeometry();
    ringGeometry.setAttribute('position', new THREE.BufferAttribute(positions, 3));
    brushRing = new THREE.Line(
        ringGeometry,
        new THREE.LineBasicMaterial({ color: 0x4c8dff, depthTest: false, transparent: true }),
    );
    brushRing.renderOrder = 10;
    brushRing.visible = false;
    scene?.add(brushRing);
}

/**
 * Lay the brush ring on the terrain surface.
 *
 * Sampling the height around the circle rather than drawing a flat disc keeps
 * the ring readable on slopes — a flat ring on a hillside disappears into the
 * ground on one side and floats on the other.
 */
function updateBrushRing() {
    if (!brushRing) return;

    if (!hoverPoint) {
        if (brushRing.visible) {
            brushRing.visible = false;
            invalidate();
        }
        return;
    }

    const attribute = brushRing.geometry.getAttribute('position') as THREE.BufferAttribute;
    const positions = attribute.array as Float32Array;
    const radius = store.brush.radius;
    const map = store.heightmap;

    for (let i = 0; i <= BRUSH_RING_SEGMENTS; i++) {
        const angle = (i / BRUSH_RING_SEGMENTS) * Math.PI * 2;
        const x = hoverPoint[0] + Math.cos(angle) * radius;
        const z = hoverPoint[2] + Math.sin(angle) * radius;
        positions[i * 3] = x;
        positions[i * 3 + 1] = sampleSurface(x, z) + 0.15;
        positions[i * 3 + 2] = z;
    }

    attribute.needsUpdate = true;
    brushRing.geometry.computeBoundingSphere();
    brushRing.visible = true;
    invalidate();

    function sampleSurface(x: number, z: number): number {
        const halfX = map.sizeX / 2;
        const halfZ = map.sizeZ / 2;
        const cx = Math.min(halfX, Math.max(-halfX, x));
        const cz = Math.min(halfZ, Math.max(-halfZ, z));
        return store.heightmap.minHeight + surfaceHeight(cx, cz);
    }

    function surfaceHeight(x: number, z: number): number {
        const gx = ((x + map.sizeX / 2) / map.sizeX) * (map.gridWidth - 1);
        const gz = ((z + map.sizeZ / 2) / map.sizeZ) * (map.gridDepth - 1);
        const x0 = Math.floor(gx);
        const z0 = Math.floor(gz);
        const fx = gx - x0;
        const fz = gz - z0;
        const range = map.maxHeight - map.minHeight;
        const at = (sx: number, sz: number) => {
            const ix = Math.min(map.gridWidth - 1, Math.max(0, sx));
            const iz = Math.min(map.gridDepth - 1, Math.max(0, sz));
            return map.samples[iz * map.gridWidth + ix] * range;
        };
        return (
            at(x0, z0) * (1 - fx) * (1 - fz) +
            at(x0 + 1, z0) * fx * (1 - fz) +
            at(x0, z0 + 1) * (1 - fx) * fz +
            at(x0 + 1, z0 + 1) * fx * fz
        );
    }
}

// ── Pointer interaction ─────────────────────────────────────────────────────

function pointerToTerrain(event: PointerEvent): [number, number, number] | null {
    if (!renderer || !camera) return null;

    const rect = renderer.domElement.getBoundingClientRect();
    pointerNdc.x = ((event.clientX - rect.left) / rect.width) * 2 - 1;
    pointerNdc.y = -((event.clientY - rect.top) / rect.height) * 2 + 1;

    const raycaster = new THREE.Raycaster();
    raycaster.setFromCamera(pointerNdc, camera);

    return raycastTerrain(
        store.heightmap,
        [raycaster.ray.origin.x, raycaster.ray.origin.y, raycaster.ray.origin.z],
        [raycaster.ray.direction.x, raycaster.ray.direction.y, raycaster.ray.direction.z],
    );
}

function onPointerDown(event: PointerEvent) {
    // Only the left button sculpts; middle/right stay with the orbit controls.
    if (event.button !== 0) return;

    const hit = pointerToTerrain(event);
    if (!hit) return;

    event.preventDefault();
    painting = true;
    invertStroke = event.altKey;
    lastStrokeTime = performance.now();
    if (orbit) orbit.enabled = false;
    renderer?.domElement.setPointerCapture(event.pointerId);

    store.beginStroke(hit[0], hit[2]);
    store.applyAt(hit[0], hit[2], 1 / 60, invertStroke);
}

function onPointerMove(event: PointerEvent) {
    const hit = pointerToTerrain(event);
    hoverPoint = hit;

    if (hit) {
        readout.value = {
            height: hit[1],
            slope: store.slopeAtWorld(hit[0], hit[2]),
        };
    } else {
        readout.value = null;
    }
    updateBrushRing();

    if (!painting || !hit) return;

    const now = performance.now();
    // Clamp the step so a stall (tab switch, GC pause) cannot land one huge
    // brush application that gouges the terrain.
    const dt = Math.min(0.05, (now - lastStrokeTime) / 1000);
    lastStrokeTime = now;

    store.applyAt(hit[0], hit[2], dt, invertStroke);
}

function endStroke(event?: PointerEvent) {
    if (!painting) return;
    painting = false;
    if (orbit) orbit.enabled = true;
    if (event) renderer?.domElement.releasePointerCapture(event.pointerId);
    store.endStroke();
    if (store.mode === 'scatter') refreshScatterPreview();
}

function onPointerLeave() {
    hoverPoint = null;
    readout.value = null;
    updateBrushRing();
}

/** Ctrl+wheel resizes the brush, matching the convention in other DCC tools. */
function onWheel(event: WheelEvent) {
    if (!event.ctrlKey) return;
    event.preventDefault();
    const next = store.brush.radius * (event.deltaY > 0 ? 0.9 : 1.1);
    store.brush.radius = Math.max(1, Math.min(store.heightmap.sizeX / 2, next));
    updateBrushRing();
}

// ── Camera ──────────────────────────────────────────────────────────────────

/** Frame the whole terrain, called when its extents change. */
function frameCamera() {
    if (!camera || !orbit) return;

    const map = store.heightmap;
    const extent = Math.max(map.sizeX, map.sizeZ);
    camera.near = Math.max(0.1, extent / 2000);
    camera.far = extent * 8;
    camera.updateProjectionMatrix();

    // Only reposition when the camera is still at its default — moving it on
    // every resolution change would fight the user's navigation.
    if (!cameraPlaced) {
        camera.position.set(extent * 0.6, extent * 0.5, extent * 0.6);
        orbit.target.set(0, 0, 0);
        orbit.update();
        cameraPlaced = true;
    }
}

let cameraPlaced = false;

// ── Lifecycle ───────────────────────────────────────────────────────────────

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

    camera = new THREE.PerspectiveCamera(50, el.clientWidth / Math.max(1, el.clientHeight), 0.1, 5000);

    renderer = new THREE.WebGLRenderer({ antialias: true });
    renderer.setPixelRatio(window.devicePixelRatio);
    renderer.setSize(el.clientWidth, el.clientHeight);
    el.appendChild(renderer.domElement);

    orbit = new OrbitControls(camera, renderer.domElement);
    orbit.enableDamping = true;
    orbit.addEventListener('change', invalidate);

    scene.add(new THREE.HemisphereLight(0xffffff, 0x2a2f38, 1.0));
    const key = new THREE.DirectionalLight(0xffffff, 1.6);
    key.position.set(1, 2, 1).multiplyScalar(200);
    scene.add(key);

    terrainMaterial = new THREE.MeshStandardMaterial({
        color: 0x8a9585,
        roughness: 0.9,
        metalness: 0,
        flatShading: false,
    });

    buildBrushRing();
    rebuildGeometry();
    refreshScatterPreview();

    renderer.domElement.addEventListener('pointerdown', onPointerDown);
    renderer.domElement.addEventListener('pointermove', onPointerMove);
    renderer.domElement.addEventListener('pointerup', endStroke);
    renderer.domElement.addEventListener('pointercancel', endStroke);
    renderer.domElement.addEventListener('pointerleave', onPointerLeave);
    renderer.domElement.addEventListener('wheel', onWheel, { passive: false });

    ro = new ResizeObserver(onResize);
    ro.observe(el);
    animate();
}

// The store bumps `revision` once per committed change; watching it avoids
// deep-watching a Float32Array of tens of thousands of samples.
watch(() => store.revision, refreshGeometry);
watch(() => store.brush.radius, updateBrushRing);
watch(
    () => [store.mode, store.activeScatter],
    () => refreshScatterPreview(),
);

onMounted(init);
onBeforeUnmount(() => {
    cancelAnimationFrame(raf);
    ro?.disconnect();

    const canvas = renderer?.domElement;
    canvas?.removeEventListener('pointerdown', onPointerDown);
    canvas?.removeEventListener('pointermove', onPointerMove);
    canvas?.removeEventListener('pointerup', endStroke);
    canvas?.removeEventListener('pointercancel', endStroke);
    canvas?.removeEventListener('pointerleave', onPointerLeave);
    canvas?.removeEventListener('wheel', onWheel);

    orbit?.dispose();
    bufferGeometry?.dispose();
    terrainMaterial?.dispose();
    brushRing?.geometry.dispose();
    (brushRing?.material as THREE.Material | undefined)?.dispose();
    scatterPreview?.dispose();
    scatterGeometry?.dispose();
    scatterMaterial?.dispose();

    if (renderer) {
        renderer.dispose();
        // dispose() alone does not release the GL context; force it so contexts
        // do not accumulate when switching between WebGL workspaces.
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
            v-if="readout"
            class="absolute left-2 bottom-2 px-2 py-1 rounded-md bg-editor-panel/85 border border-editor-border text-[11px] tabular-nums text-editor-muted pointer-events-none"
        >
            <span class="text-editor-text">{{ readout.height.toFixed(1) }}</span> m
            · <span class="text-editor-text">{{ readout.slope.toFixed(0) }}</span>°
        </div>
        <div
            class="absolute right-2 bottom-2 px-2 py-1 rounded-md bg-editor-panel/85 border border-editor-border text-[11px] pointer-events-none"
            :class="toolReady ? 'text-editor-muted' : 'text-editor-warning'"
        >
            {{ toolHint }}
        </div>
    </div>
</template>
