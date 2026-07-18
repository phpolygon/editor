<script setup lang="ts">
import { computed, nextTick, onUnmounted, ref, watch } from 'vue';
import { Hammer, PackageCheck, TriangleAlert, Info } from 'lucide-vue-next';
import Modal from '@/components/ui/Modal.vue';
import Button from '@/components/ui/Button.vue';
import Select from '@/components/ui/Select.vue';
import SegmentedControl from '@/components/ui/SegmentedControl.vue';
import { get, post } from '@/bridge/api';
import { useToast } from '@/composables/useToast';

/**
 * "Build game" dialog. Two modes:
 * - Host: package for the current platform natively (no Docker).
 * - Cross-platform: build bundles for chosen platforms in the engine's Docker
 *   image (needs Docker + a GitHub token + a build.json in the project).
 * Progress is streamed by polling the build status.
 */
const props = defineProps<{ modelValue: boolean }>();
const emit = defineEmits<{ 'update:modelValue': [boolean] }>();

const { addToast } = useToast();

const PLATFORMS = [
    { value: 'windows-x86_64', label: 'Windows (x64)' },
    { value: 'linux-x86_64', label: 'Linux (x64)' },
    { value: 'macos-arm64', label: 'macOS (Apple Silicon)' },
    { value: 'macos-x86_64', label: 'macOS (Intel)' },
];

const mode = ref<'host' | 'docker'>('host');
const variant = ref<'base' | 'steam'>('base');
const platforms = ref<string[]>(['windows-x86_64', 'linux-x86_64', 'macos-arm64', 'macos-x86_64']);
const building = ref(false);
const done = ref(false);
const exitCode = ref<number | null>(null);
const log = ref('');
const outputDir = ref('');
const logEl = ref<HTMLElement | null>(null);
let pollTimer: ReturnType<typeof setTimeout> | null = null;

const success = computed(() => done.value && exitCode.value === 0);
const canClose = computed(() => !building.value || done.value);
const canBuild = computed(() => !building.value && (mode.value === 'host' || platforms.value.length > 0));

watch(
    () => props.modelValue,
    (open) => {
        if (open) reset();
        else stopPolling();
    },
);

onUnmounted(stopPolling);

function reset() {
    stopPolling();
    mode.value = 'host';
    variant.value = 'base';
    platforms.value = ['windows-x86_64', 'linux-x86_64', 'macos-arm64', 'macos-x86_64'];
    building.value = false;
    done.value = false;
    exitCode.value = null;
    log.value = '';
    outputDir.value = '';
}

function stopPolling() {
    if (pollTimer !== null) {
        clearTimeout(pollTimer);
        pollTimer = null;
    }
}

function close() {
    if (!canClose.value) return;
    emit('update:modelValue', false);
}

function togglePlatform(value: string) {
    const i = platforms.value.indexOf(value);
    if (i === -1) platforms.value.push(value);
    else platforms.value.splice(i, 1);
}

async function startBuild() {
    if (!canBuild.value) return;
    building.value = true;
    done.value = false;
    exitCode.value = null;
    log.value = '';
    try {
        const res = await post<{ buildId: string; outputDir: string }>('/project/build-start', {
            variant: variant.value,
            docker: mode.value === 'docker',
            platforms: platforms.value.join(','),
        });
        outputDir.value = res.outputDir;
        poll(res.buildId);
    } catch (e: any) {
        building.value = false;
        addToast(e?.message ?? 'Failed to start build', 'error');
    }
}

async function poll(id: string) {
    try {
        const s = await get<{ found: boolean; log: string; done: boolean; exitCode: number | null }>(
            '/project/build-status?id=' + encodeURIComponent(id),
        );
        log.value = s.log;
        scrollLog();
        if (s.done) {
            done.value = true;
            exitCode.value = s.exitCode;
            building.value = false;
            addToast(s.exitCode === 0 ? 'Build complete' : 'Build failed', s.exitCode === 0 ? 'success' : 'error');
            return;
        }
        pollTimer = setTimeout(() => poll(id), 1200);
    } catch {
        pollTimer = setTimeout(() => poll(id), 2000);
    }
}

function scrollLog() {
    nextTick(() => {
        if (logEl.value) logEl.value.scrollTop = logEl.value.scrollHeight;
    });
}
</script>

<template>
    <Modal
        :model-value="modelValue"
        title="Build Game"
        width="max-w-2xl"
        :persistent="!canClose"
        @update:model-value="(v: boolean) => v || close()"
    >
        <div class="flex flex-col gap-4">
            <SegmentedControl
                v-model="mode"
                :options="[
                    { value: 'host', label: 'This platform' },
                    { value: 'docker', label: 'Cross-platform (Docker)' },
                ]"
                :disabled="building"
            />

            <p class="text-sm text-editor-muted">
                <template v-if="mode === 'host'">
                    Package the project into a standalone executable for your current platform. The engine
                    downloads the runtime and bundles it with your game — this can take a few minutes.
                </template>
                <template v-else>
                    Build bundles for multiple platforms in the engine's Docker image, all from this machine.
                </template>
            </p>

            <div class="flex items-end gap-3">
                <div>
                    <label class="block text-xs text-editor-muted mb-1">Variant</label>
                    <Select
                        v-model="variant"
                        :options="[{ value: 'base', label: 'Base' }, { value: 'steam', label: 'Steam' }]"
                        size="md"
                        :disabled="building"
                    />
                </div>
                <Button :icon="Hammer" variant="primary" size="md" :disabled="!canBuild" @click="startBuild">
                    {{ building ? 'Building…' : 'Build' }}
                </Button>
            </div>

            <!-- Docker-only: platform picker + requirements note -->
            <div v-if="mode === 'docker'" class="flex flex-col gap-2">
                <label class="block text-xs text-editor-muted">Target platforms</label>
                <div class="grid grid-cols-2 gap-2">
                    <label
                        v-for="p in PLATFORMS"
                        :key="p.value"
                        class="flex items-center gap-2 text-sm text-editor-text cursor-pointer select-none"
                    >
                        <input
                            type="checkbox"
                            class="accent-editor-accent h-4 w-4"
                            :checked="platforms.includes(p.value)"
                            :disabled="building"
                            @change="togglePlatform(p.value)"
                        />
                        {{ p.label }}
                    </label>
                </div>
                <div class="flex items-start gap-2 text-[11px] text-editor-muted mt-1">
                    <Info :size="14" class="shrink-0 mt-0.5" />
                    <span>Requires Docker running, a GitHub token (or <code>gh</code> login), and a
                        <code>build.json</code> in the project.</span>
                </div>
            </div>

            <!-- Live log -->
            <div
                v-if="log || building"
                ref="logEl"
                class="h-64 overflow-auto rounded-md bg-editor-bg border border-editor-border p-3
                       font-mono text-[11px] leading-relaxed text-editor-muted whitespace-pre-wrap"
            >{{ log || 'Starting build…' }}</div>

            <!-- Result -->
            <div v-if="done && success" class="flex items-center gap-2 text-sm text-editor-text">
                <PackageCheck :size="18" class="text-emerald-400 shrink-0" />
                <span>Build complete. Output in <span class="font-mono text-[12px]" dir="rtl">{{ outputDir }}</span></span>
            </div>
            <div v-else-if="done && !success" class="flex items-center gap-2 text-sm text-editor-text">
                <TriangleAlert :size="18" class="text-amber-400 shrink-0" />
                <span>Build failed (exit code {{ exitCode }}). See the log above.</span>
            </div>
        </div>

        <template #footer>
            <Button variant="ghost" size="md" :disabled="!canClose" @click="close">
                {{ done ? 'Close' : 'Cancel' }}
            </Button>
        </template>
    </Modal>
</template>
