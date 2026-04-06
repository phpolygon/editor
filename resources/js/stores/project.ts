import { defineStore } from 'pinia';
import { ref } from 'vue';
import { get, post } from '@/bridge/api';
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
