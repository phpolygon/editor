<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { FolderSearch, Check } from 'lucide-vue-next';
import Modal from '@/components/ui/Modal.vue';
import Button from '@/components/ui/Button.vue';
import Select from '@/components/ui/Select.vue';
import SegmentedControl from '@/components/ui/SegmentedControl.vue';
import { useProjectStore } from '@/stores/project';
import { useToast } from '@/composables/useToast';

/**
 * Multi-step "New Game" wizard: collects everything needed for a buildable +
 * runnable project (and optional Steam files), then scaffolds + opens it via the
 * project store.
 */
const props = defineProps<{ modelValue: boolean }>();
const emit = defineEmits<{ 'update:modelValue': [boolean] }>();

const projectStore = useProjectStore();
const { addToast } = useToast();

const STEPS = ['Basics', 'Project', 'Runtime', 'Steam', 'Review'];
const ALL_EXTENSIONS = ['vio', 'glfw', 'vulkan', 'mbstring', 'zip', 'phar', 'gd', 'ffi'];

const inputClass =
    'w-full h-9 px-3 rounded-md bg-editor-input border border-editor-border text-editor-text text-sm ' +
    'focus:outline-none focus:border-editor-accent focus:ring-2 focus:ring-editor-accent/40';

const step = ref(0);
const busy = ref(false);

// Fields
const name = ref('');
const parentDir = ref('');
const namespace = ref('App');
const mode = ref<'2d' | '3d'>('3d');
const identifier = ref('');
const version = ref('1.0.0');
const sceneName = ref('MainScene');
const width = ref(1280);
const height = ref(720);
const extensions = ref<string[]>(['vio', 'glfw', 'mbstring', 'zip', 'phar']);
const threading = ref(false);
const install = ref(false);

// Steam
const steamEnabled = ref(false);
const steamAppId = ref('');
const steamUser = ref('');
const depotWin = ref('');
const depotLinux = ref('');
const depotMac = ref('');
const uploadTarget = ref('full');
const setLive = ref('');

const slug = computed(() =>
    name.value.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, ''),
);
const identifierPreview = computed(
    () => identifier.value.trim() || 'com.phpolygon.' + slug.value.replace(/-/g, ''),
);

const steamValid = computed(() => !steamEnabled.value || /^\d+$/.test(steamAppId.value.trim()));
const canNext = computed(() => {
    if (step.value === 0) return name.value.trim() !== '' && parentDir.value.trim() !== '';
    if (step.value === 3) return steamValid.value;
    return true;
});
const isLast = computed(() => step.value === STEPS.length - 1);

watch(
    () => props.modelValue,
    (open) => {
        if (open) reset();
    },
);

function reset() {
    step.value = 0;
    busy.value = false;
    name.value = '';
    parentDir.value = '';
    namespace.value = 'App';
    mode.value = '3d';
    identifier.value = '';
    version.value = '1.0.0';
    sceneName.value = 'MainScene';
    width.value = 1280;
    height.value = 720;
    extensions.value = ['vio', 'glfw', 'mbstring', 'zip', 'phar'];
    threading.value = false;
    install.value = false;
    steamEnabled.value = false;
    steamAppId.value = '';
    steamUser.value = '';
    depotWin.value = '';
    depotLinux.value = '';
    depotMac.value = '';
    uploadTarget.value = 'full';
    setLive.value = '';
}

function close() {
    if (busy.value) return;
    emit('update:modelValue', false);
}

function next() {
    if (!canNext.value) return;
    if (isLast.value) create();
    else step.value++;
}
function back() {
    if (step.value > 0) step.value--;
}

function toggleExtension(ext: string) {
    const i = extensions.value.indexOf(ext);
    if (i === -1) extensions.value.push(ext);
    else extensions.value.splice(i, 1);
}

async function browse() {
    try {
        const dir = await projectStore.pickCreateFolder();
        if (dir) parentDir.value = dir;
    } catch (e: any) {
        addToast(e?.message ?? 'Could not pick a folder', 'error');
    }
}

async function create() {
    busy.value = true;
    try {
        const { installed } = await projectStore.createProject({
            parentDir: parentDir.value.trim(),
            name: name.value.trim(),
            install: install.value,
            options: {
                namespace: namespace.value.trim() || 'App',
                mode: mode.value,
                identifier: identifier.value.trim() || undefined,
                version: version.value.trim() || undefined,
                sceneName: sceneName.value.trim() || undefined,
                width: Number(width.value) || undefined,
                height: Number(height.value) || undefined,
                extensions: extensions.value,
                threading: threading.value,
                steam: steamEnabled.value
                    ? {
                          enabled: true,
                          appId: steamAppId.value.trim(),
                          steamUser: steamUser.value.trim(),
                          uploadTarget: uploadTarget.value.trim() || 'full',
                          setLive: setLive.value.trim(),
                          depots: { windows: depotWin.value.trim(), linux: depotLinux.value.trim(), macos: depotMac.value.trim() },
                      }
                    : { enabled: false },
            },
        });
        addToast(`Project "${projectStore.name}" created`, 'success');
        if (install.value && installed === false) {
            addToast('Dependency install failed — run "composer install" manually', 'error');
        }
        emit('update:modelValue', false);
    } catch (e: any) {
        if (e?.message !== 'No directory selected') {
            addToast(e?.message ?? 'Failed to create project', 'error');
        }
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <Modal
        :model-value="modelValue"
        title="New Game"
        width="max-w-xl"
        :persistent="busy"
        @update:model-value="(v: boolean) => v || close()"
    >
        <!-- Step indicator -->
        <div class="flex items-center gap-1.5 mb-4">
            <template v-for="(s, i) in STEPS" :key="s">
                <div
                    class="flex items-center gap-1.5 text-[11px] font-medium px-2 py-1 rounded"
                    :class="i === step ? 'bg-editor-accent/15 text-editor-accent' : i < step ? 'text-editor-text' : 'text-editor-muted'"
                >
                    <Check v-if="i < step" :size="12" />
                    {{ s }}
                </div>
                <div v-if="i < STEPS.length - 1" class="w-3 h-px bg-editor-border" />
            </template>
        </div>

        <div class="flex flex-col gap-4 min-h-[15rem]">
            <!-- 0: Basics -->
            <template v-if="step === 0">
                <div>
                    <label class="block text-xs text-editor-muted mb-1">Game name</label>
                    <input v-model="name" type="text" placeholder="My Game" :class="inputClass" />
                </div>
                <div>
                    <label class="block text-xs text-editor-muted mb-1">Location</label>
                    <div class="flex gap-2">
                        <input v-model="parentDir" type="text" placeholder="Folder that will contain the project" :class="inputClass" />
                        <Button :icon="FolderSearch" size="md" @click="browse">Browse</Button>
                    </div>
                    <p v-if="slug && parentDir.trim()" class="text-[11px] text-editor-muted mt-1 truncate" dir="rtl">{{ parentDir }}\{{ slug }}</p>
                </div>
                <div class="flex gap-4">
                    <div class="flex-1">
                        <label class="block text-xs text-editor-muted mb-1">Namespace</label>
                        <input v-model="namespace" type="text" placeholder="App" :class="inputClass" />
                    </div>
                    <div>
                        <label class="block text-xs text-editor-muted mb-1">Mode</label>
                        <SegmentedControl v-model="mode" :options="[{ value: '3d', label: '3D' }, { value: '2d', label: '2D' }]" />
                    </div>
                </div>
            </template>

            <!-- 1: Project -->
            <template v-else-if="step === 1">
                <div>
                    <label class="block text-xs text-editor-muted mb-1">Bundle identifier</label>
                    <input v-model="identifier" type="text" :placeholder="identifierPreview" :class="inputClass" />
                    <p class="text-[11px] text-editor-muted mt-1">Reverse-DNS, used for macOS bundles. Leave blank for <code>{{ identifierPreview }}</code>.</p>
                </div>
                <div class="flex gap-4">
                    <div class="flex-1">
                        <label class="block text-xs text-editor-muted mb-1">Version</label>
                        <input v-model="version" type="text" placeholder="1.0.0" :class="inputClass" />
                    </div>
                    <div class="flex-1">
                        <label class="block text-xs text-editor-muted mb-1">Start scene</label>
                        <input v-model="sceneName" type="text" placeholder="MainScene" :class="inputClass" />
                    </div>
                </div>
                <div class="flex gap-4">
                    <div class="flex-1">
                        <label class="block text-xs text-editor-muted mb-1">Window width</label>
                        <input v-model.number="width" type="number" :class="inputClass" />
                    </div>
                    <div class="flex-1">
                        <label class="block text-xs text-editor-muted mb-1">Window height</label>
                        <input v-model.number="height" type="number" :class="inputClass" />
                    </div>
                </div>
            </template>

            <!-- 2: Runtime -->
            <template v-else-if="step === 2">
                <div>
                    <label class="block text-xs text-editor-muted mb-2">PHP extensions bundled into the build</label>
                    <div class="grid grid-cols-3 gap-2">
                        <label v-for="ext in ALL_EXTENSIONS" :key="ext" class="flex items-center gap-2 text-sm text-editor-text cursor-pointer select-none">
                            <input type="checkbox" class="accent-editor-accent h-4 w-4" :checked="extensions.includes(ext)" @change="toggleExtension(ext)" />
                            {{ ext }}
                        </label>
                    </div>
                </div>
                <label class="flex items-center gap-2 text-sm text-editor-text cursor-pointer select-none mt-2">
                    <input v-model="threading" type="checkbox" class="accent-editor-accent h-4 w-4" />
                    Enable multithreading (ZTS + <code>parallel</code>)
                </label>
            </template>

            <!-- 3: Steam -->
            <template v-else-if="step === 3">
                <label class="flex items-center gap-2 text-sm text-editor-text cursor-pointer select-none">
                    <input v-model="steamEnabled" type="checkbox" class="accent-editor-accent h-4 w-4" />
                    Add Steam support
                </label>
                <p class="text-[11px] text-editor-muted -mt-2">Creates <code>steam_appid.txt</code> + <code>steam-build.json</code>. The Steam runtime library is bundled automatically.</p>
                <template v-if="steamEnabled">
                    <div class="flex gap-4">
                        <div class="flex-1">
                            <label class="block text-xs text-editor-muted mb-1">Steam App ID</label>
                            <input v-model="steamAppId" type="text" placeholder="480" :class="inputClass" />
                            <p v-if="steamAppId && !steamValid" class="text-[11px] text-red-400 mt-1">Must be numeric.</p>
                        </div>
                        <div class="flex-1">
                            <label class="block text-xs text-editor-muted mb-1">Steam login</label>
                            <input v-model="steamUser" type="text" placeholder="your-steam-login" :class="inputClass" />
                        </div>
                    </div>
                    <div class="grid grid-cols-3 gap-3">
                        <div>
                            <label class="block text-xs text-editor-muted mb-1">Depot: Windows</label>
                            <input v-model="depotWin" type="text" :class="inputClass" />
                        </div>
                        <div>
                            <label class="block text-xs text-editor-muted mb-1">Depot: Linux</label>
                            <input v-model="depotLinux" type="text" :class="inputClass" />
                        </div>
                        <div>
                            <label class="block text-xs text-editor-muted mb-1">Depot: macOS</label>
                            <input v-model="depotMac" type="text" :class="inputClass" />
                        </div>
                    </div>
                    <div class="flex gap-4">
                        <div class="flex-1">
                            <label class="block text-xs text-editor-muted mb-1">Upload target</label>
                            <input v-model="uploadTarget" type="text" placeholder="full" :class="inputClass" />
                        </div>
                        <div class="flex-1">
                            <label class="block text-xs text-editor-muted mb-1">Set-live branch (optional)</label>
                            <input v-model="setLive" type="text" placeholder="beta" :class="inputClass" />
                        </div>
                    </div>
                </template>
            </template>

            <!-- 4: Review -->
            <template v-else>
                <div class="text-sm text-editor-text flex flex-col gap-1.5">
                    <div class="flex justify-between"><span class="text-editor-muted">Name</span><span>{{ name }}</span></div>
                    <div class="flex justify-between"><span class="text-editor-muted">Location</span><span class="font-mono text-[12px] truncate max-w-[60%]" dir="rtl">{{ parentDir }}\{{ slug }}</span></div>
                    <div class="flex justify-between"><span class="text-editor-muted">Namespace / Boot</span><span class="font-mono text-[12px]">{{ namespace || 'App' }}\Game::start()</span></div>
                    <div class="flex justify-between"><span class="text-editor-muted">Mode</span><span>{{ mode.toUpperCase() }} · {{ width }}×{{ height }}</span></div>
                    <div class="flex justify-between"><span class="text-editor-muted">Extensions</span><span class="text-[12px]">{{ extensions.join(', ') }}{{ threading ? ' · threading' : '' }}</span></div>
                    <div class="flex justify-between"><span class="text-editor-muted">Steam</span><span>{{ steamEnabled ? `App ${steamAppId}` : 'no' }}</span></div>
                </div>
                <label class="flex items-center gap-2 text-sm text-editor-text cursor-pointer select-none mt-2">
                    <input v-model="install" type="checkbox" class="accent-editor-accent h-4 w-4" />
                    Install dependencies now (composer install)
                </label>
            </template>
        </div>

        <template #footer>
            <Button variant="ghost" size="md" :disabled="busy || step === 0" @click="back">Back</Button>
            <Button variant="primary" size="md" :disabled="!canNext || busy" @click="next">
                {{ busy ? 'Creating…' : isLast ? 'Create' : 'Next' }}
            </Button>
        </template>
    </Modal>
</template>
