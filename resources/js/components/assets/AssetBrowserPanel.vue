<script setup lang="ts">
import { ref, watch } from 'vue';
import { Folder, File, Image, Music, FileText, ChevronRight } from 'lucide-vue-next';
import PanelHeader from '@/components/layout/PanelHeader.vue';
import { useProjectStore } from '@/stores/project';
import { get } from '@/bridge/api';
import type { AssetFileEntry, AssetListResponse } from '@/types';

const projectStore = useProjectStore();

const currentPath = ref('');
const files = ref<AssetFileEntry[]>([]);
const loading = ref(false);

const imageExts = ['png', 'jpg', 'jpeg', 'gif', 'bmp', 'svg', 'webp'];
const audioExts = ['mp3', 'wav', 'ogg', 'flac', 'aac'];

async function fetchFiles(subPath = '') {
    loading.value = true;
    try {
        const param = subPath ? `?path=${encodeURIComponent(subPath)}` : '';
        const data = await get<AssetListResponse>(`/assets${param}`);
        files.value = data.files;
        currentPath.value = data.path;
    } catch {
        files.value = [];
    } finally {
        loading.value = false;
    }
}

function navigateTo(name: string) {
    const newPath = currentPath.value ? `${currentPath.value}/${name}` : name;
    fetchFiles(newPath);
}

function navigateUp() {
    const parts = currentPath.value.split('/').filter(Boolean);
    parts.pop();
    fetchFiles(parts.join('/'));
}

function navigateToBreadcrumb(index: number) {
    const parts = currentPath.value.split('/').filter(Boolean);
    fetchFiles(parts.slice(0, index + 1).join('/'));
}

function breadcrumbs() {
    return currentPath.value.split('/').filter(Boolean);
}

function isImage(ext: string | null) {
    return ext && imageExts.includes(ext.toLowerCase());
}

function isAudio(ext: string | null) {
    return ext && audioExts.includes(ext.toLowerCase());
}

function formatSize(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

watch(() => projectStore.opened, (opened) => {
    if (opened) fetchFiles();
}, { immediate: true });
</script>

<template>
    <div class="flex flex-col h-full bg-editor-panel">
        <PanelHeader title="Asset Browser" />

        <template v-if="!projectStore.opened">
            <div class="flex-1 flex items-center justify-center">
                <span class="text-xs text-editor-muted">Open a project to browse assets</span>
            </div>
        </template>

        <template v-else>
            <!-- Breadcrumb -->
            <div class="flex items-center gap-0.5 px-2 py-1 border-b border-editor-border text-xs text-editor-muted min-h-[24px]">
                <button
                    class="hover:text-editor-text"
                    @click="fetchFiles('')"
                >
                    assets
                </button>
                <template v-for="(crumb, i) in breadcrumbs()" :key="i">
                    <ChevronRight class="w-3 h-3 shrink-0" />
                    <button
                        class="hover:text-editor-text truncate"
                        @click="navigateToBreadcrumb(i)"
                    >
                        {{ crumb }}
                    </button>
                </template>
            </div>

            <!-- File grid -->
            <div class="flex-1 overflow-y-auto p-1">
                <div v-if="loading" class="flex items-center justify-center h-full">
                    <span class="text-xs text-editor-muted">Loading...</span>
                </div>

                <div v-else-if="files.length === 0" class="flex items-center justify-center h-full">
                    <span class="text-xs text-editor-muted">Empty folder</span>
                </div>

                <div v-else class="grid grid-cols-[repeat(auto-fill,minmax(72px,1fr))] gap-1">
                    <!-- Back button -->
                    <button
                        v-if="currentPath"
                        class="flex flex-col items-center gap-0.5 p-1.5 rounded hover:bg-editor-hover cursor-pointer"
                        @click="navigateUp"
                    >
                        <Folder class="w-6 h-6 text-editor-muted" />
                        <span class="text-[10px] text-editor-muted truncate w-full text-center">..</span>
                    </button>

                    <button
                        v-for="file in files"
                        :key="file.name"
                        class="flex flex-col items-center gap-0.5 p-1.5 rounded hover:bg-editor-hover cursor-pointer"
                        @dblclick="file.isDir ? navigateTo(file.name) : undefined"
                        @click="file.isDir ? navigateTo(file.name) : undefined"
                    >
                        <Folder v-if="file.isDir" class="w-6 h-6 text-yellow-500" />
                        <Image v-else-if="isImage(file.ext)" class="w-6 h-6 text-green-400" />
                        <Music v-else-if="isAudio(file.ext)" class="w-6 h-6 text-purple-400" />
                        <FileText v-else-if="file.ext === 'php' || file.ext === 'json'" class="w-6 h-6 text-blue-400" />
                        <File v-else class="w-6 h-6 text-editor-muted" />
                        <span class="text-[10px] text-editor-text truncate w-full text-center" :title="file.name">
                            {{ file.name }}
                        </span>
                        <span v-if="!file.isDir" class="text-[9px] text-editor-muted">
                            {{ formatSize(file.size) }}
                        </span>
                    </button>
                </div>
            </div>
        </template>
    </div>
</template>
