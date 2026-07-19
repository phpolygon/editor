<script setup lang="ts">
import { Teleport, Transition } from 'vue';
import { LoaderCircle } from 'lucide-vue-next';
import { useSceneStore } from '@/stores/scene';

/**
 * Full-screen busy overlay shown while a scene loads. Scene loads bulk-preload
 * all geometry/materials and expand code prefabs, which on the single-threaded
 * dev server can take seconds — without this the app looks frozen after picking
 * a scene. Non-dismissable by design: it clears when `sceneStore.loading` flips.
 */
const sceneStore = useSceneStore();
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition duration-150 ease-out"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition duration-100 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="sceneStore.loading"
                class="fixed inset-0 z-[110] flex items-center justify-center bg-black/60 backdrop-blur-sm"
                role="status"
                aria-live="polite"
            >
                <div
                    class="flex flex-col items-center gap-3 px-8 py-6 rounded-xl border border-editor-border
                           bg-editor-panel shadow-2xl shadow-black/50"
                >
                    <LoaderCircle :size="30" :stroke-width="2" class="text-editor-accent animate-spin" />
                    <p class="text-sm font-medium text-editor-text">Loading scene…</p>
                    <p class="text-xs text-editor-muted">Preloading geometry &amp; materials</p>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
