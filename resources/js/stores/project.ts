import { defineStore } from 'pinia';
import { ref } from 'vue';
import { ApiError, get, post } from '@/bridge/api';
import { useSceneStore } from './scene';

interface ProjectData {
    manifest: {
        name: string;
        version: string;
        scenesPath: string;
        assetsPath: string;
        entryScene: string;
    };
    components?: Record<string, unknown>;
}

export const useProjectStore = defineStore('project', () => {
    const name = ref('');
    const projectDir = ref('');
    const scenesPath = ref('');
    const assetsPath = ref('');
    const entryScene = ref('');
    const opened = ref(false);

    function applyManifest(data: ProjectData) {
        name.value = data.manifest.name;
        scenesPath.value = data.manifest.scenesPath;
        assetsPath.value = data.manifest.assetsPath;
        entryScene.value = data.manifest.entryScene;
        opened.value = true;
    }

    async function openProject(dir: string) {
        const data = await post<ProjectData>('/project/open', { dir });
        projectDir.value = dir;
        applyManifest(data);

        const sceneStore = useSceneStore();
        await sceneStore.fetchSceneList();
        if (data.manifest.entryScene) {
            await sceneStore.load(data.manifest.entryScene);
        }
    }

    async function openProjectWithDialog() {
        try {
            const data = await post<ProjectData & { projectDir?: string }>('/project/open-dialog');
            if (data.projectDir) {
                projectDir.value = data.projectDir;
            }
            applyManifest(data);

            const sceneStore = useSceneStore();
            await sceneStore.fetchSceneList();
            if (data.manifest.entryScene) {
                await sceneStore.load(data.manifest.entryScene);
            }
        } catch (e) {
            // Web-only runs (composer dev) have no NativePHP bridge.
            // Backend returns 503 + fallback hint; fall back to a path
            // prompt so the editor still works in the browser.
            if (e instanceof ApiError && (e.body as { fallback?: string } | undefined)?.fallback === 'path-input') {
                const dir = window.prompt('Path to PHPolygon project directory:');
                if (!dir) throw new Error('No directory selected');
                await openProject(dir);
                return;
            }
            throw e;
        }
    }

    async function fetchProject() {
        const data = await get<{ manifest: ProjectData['manifest']; projectDir: string; opened: boolean }>('/project');
        if (data.opened) {
            projectDir.value = data.projectDir;
            applyManifest({ manifest: data.manifest });
        }
    }

    return {
        name,
        projectDir,
        scenesPath,
        assetsPath,
        entryScene,
        opened,
        openProject,
        openProjectWithDialog,
        fetchProject,
    };
});
