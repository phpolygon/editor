<script setup lang="ts">
import * as THREE from 'three';
import { computed } from 'vue';

interface Quat {
    x: number;
    y: number;
    z: number;
    w: number;
}

const props = defineProps<{
    label: string;
    modelValue: Quat | null;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: Quat];
}>();

const IDENTITY: Quat = { x: 0, y: 0, z: 0, w: 1 };

function round(v: number): number {
    // Avoid -0 and long float tails in the input display.
    return Math.round((v + 0) * 100) / 100;
}

/** Show the quaternion as human-friendly Euler angles (degrees, XYZ order). */
const euler = computed(() => {
    const q = props.modelValue ?? IDENTITY;
    const e = new THREE.Euler().setFromQuaternion(
        new THREE.Quaternion(q.x, q.y, q.z, q.w),
        'XYZ',
    );
    return {
        x: round(THREE.MathUtils.radToDeg(e.x)),
        y: round(THREE.MathUtils.radToDeg(e.y)),
        z: round(THREE.MathUtils.radToDeg(e.z)),
    };
});

function update(axis: 'x' | 'y' | 'z', event: Event): void {
    const value = parseFloat((event.target as HTMLInputElement).value);
    if (Number.isNaN(value)) return;

    const deg = { ...euler.value, [axis]: value };
    const q = new THREE.Quaternion().setFromEuler(
        new THREE.Euler(
            THREE.MathUtils.degToRad(deg.x),
            THREE.MathUtils.degToRad(deg.y),
            THREE.MathUtils.degToRad(deg.z),
            'XYZ',
        ),
    );
    emit('update:modelValue', { x: q.x, y: q.y, z: q.z, w: q.w });
}
</script>

<template>
    <div class="flex items-center gap-1 px-1 py-0.5">
        <label class="text-xs text-editor-muted w-20 shrink-0 truncate" :title="`${label} (Euler°)`">
            {{ label }}
        </label>
        <div class="flex-1 flex gap-1">
            <div class="flex items-center gap-0.5 flex-1">
                <span class="text-xs text-red-400">X</span>
                <input
                    type="number"
                    step="1"
                    :value="euler.x"
                    class="flex-1 bg-editor-input border border-editor-border text-editor-text text-sm px-1 py-0.5 rounded focus:border-editor-accent focus:outline-none w-0 min-w-0"
                    @change="update('x', $event)"
                />
            </div>
            <div class="flex items-center gap-0.5 flex-1">
                <span class="text-xs text-green-400">Y</span>
                <input
                    type="number"
                    step="1"
                    :value="euler.y"
                    class="flex-1 bg-editor-input border border-editor-border text-editor-text text-sm px-1 py-0.5 rounded focus:border-editor-accent focus:outline-none w-0 min-w-0"
                    @change="update('y', $event)"
                />
            </div>
            <div class="flex items-center gap-0.5 flex-1">
                <span class="text-xs text-blue-400">Z</span>
                <input
                    type="number"
                    step="1"
                    :value="euler.z"
                    class="flex-1 bg-editor-input border border-editor-border text-editor-text text-sm px-1 py-0.5 rounded focus:border-editor-accent focus:outline-none w-0 min-w-0"
                    @change="update('z', $event)"
                />
            </div>
        </div>
    </div>
</template>
