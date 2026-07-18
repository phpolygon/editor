import { defineStore } from 'pinia';
import { ref } from 'vue';
import { ApiError, get, post } from '@/bridge/api';
import { createGameProject, installProjectDependencies, type NewProjectOptions } from '@/bridge/commands';
import { useSceneStore } from './scene';
import { useDialog } from '@/composables/useDialog';

interface ProjectData {
    manifest: {
        name: string;
        version: string;
        scenesPath: string;
        assetsPath: string;
        entryScene: string;
        defaultMode?: '2d' | '3d';
    };
    components?: Record<string, unknown>;
}

export interface RecentProject {
    dir: string;
    name: string;
    lastOpened: number;
}

export const useProjectStore = defineStore('project', () => {
    const name = ref('');
    const projectDir = ref('');
    const scenesPath = ref('');
    const assetsPath = ref('');
    const entryScene = ref('');
    const defaultMode = ref<'2d' | '3d'>('3d');
    const opened = ref(false);
    const recent = ref<RecentProject[]>([]);

    function applyManifest(data: ProjectData) {
        name.value = data.manifest.name;
        scenesPath.value = data.manifest.scenesPath;
        assetsPath.value = data.manifest.assetsPath;
        entryScene.value = data.manifest.entryScene;
        defaultMode.value = data.manifest.defaultMode === '2d' ? '2d' : '3d';
        opened.value = true;
    }

    /**
     * Load the project's entry scene. The manifest stores it as a FQCN (used by
     * the engine at runtime), but the scene list + loader identify scenes by
     * file basename — so reduce it and only load when that scene exists.
     */
    async function openEntryScene(entryScene: string): Promise<void> {
        if (!entryScene) return;
        const sceneStore = useSceneStore();
        const sceneName = entryScene.split('\\').pop() ?? '';
        if (sceneName && sceneStore.sceneList.includes(sceneName)) {
            await sceneStore.load(sceneName);
        }
    }

    async function openProject(dir: string) {
        const data = await post<ProjectData>('/project/open', { dir });
        projectDir.value = dir;
        applyManifest(data);

        const sceneStore = useSceneStore();
        await sceneStore.fetchSceneList();
        await openEntryScene(data.manifest.entryScene);
        await fetchRecent();
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
            await openEntryScene(data.manifest.entryScene);
            await fetchRecent();
        } catch (e) {
            // Web-only runs (composer dev) have no NativePHP bridge.
            // Backend returns 503 + fallback hint; fall back to a path
            // prompt so the editor still works in the browser.
            if (e instanceof ApiError && (e.body as { fallback?: string } | undefined)?.fallback === 'path-input') {
                const dir = await useDialog().prompt({
                    title: 'Open project',
                    message: 'Path to the PHPolygon project directory:',
                    placeholder: 'D:\\path\\to\\project',
                });
                if (!dir) throw new Error('No directory selected');
                await openProject(dir);
                return;
            }
            throw e;
        }
    }

    /**
     * Import an existing PHPolygon project folder: the backend writes a
     * `phpolygon.project.json` manifest (if the folder lacks one) and opens it.
     * Returns whether a fresh manifest was created vs. an already-initialized
     * project was opened.
     */
    async function importProject(dir: string): Promise<{ created: boolean }> {
        const data = await post<ProjectData & { created?: boolean }>('/project/import', { dir });
        projectDir.value = dir;
        applyManifest(data);

        const sceneStore = useSceneStore();
        await sceneStore.fetchSceneList();
        await openEntryScene(data.manifest.entryScene);
        await fetchRecent();

        return { created: data.created ?? false };
    }

    async function importProjectWithDialog(): Promise<{ created: boolean }> {
        try {
            const data = await post<ProjectData & { projectDir?: string; created?: boolean }>('/project/import-dialog');
            if (data.projectDir) {
                projectDir.value = data.projectDir;
            }
            applyManifest(data);

            const sceneStore = useSceneStore();
            await sceneStore.fetchSceneList();
            await openEntryScene(data.manifest.entryScene);
            await fetchRecent();

            return { created: data.created ?? false };
        } catch (e) {
            // Web-only runs (composer dev) have no NativePHP bridge; fall back
            // to a path prompt, mirroring openProjectWithDialog.
            if (e instanceof ApiError && (e.body as { fallback?: string } | undefined)?.fallback === 'path-input') {
                const dir = await useDialog().prompt({
                    title: 'Import project',
                    message: 'Path to the PHPolygon project directory to import:',
                    placeholder: 'D:\\path\\to\\project',
                });
                if (!dir) throw new Error('No directory selected');
                return await importProject(dir);
            }
            throw e;
        }
    }

    /**
     * Pick the folder that will contain the new project. Uses the native folder
     * dialog, falling back to a path prompt on web-only runs (no Electron).
     * Returns the chosen path, or null if the user cancelled.
     */
    async function pickCreateFolder(): Promise<string | null> {
        try {
            const data = await post<{ dir: string }>('/project/pick-folder');
            return data.dir;
        } catch (e) {
            if (e instanceof ApiError && (e.body as { fallback?: string } | undefined)?.fallback === 'path-input') {
                return await useDialog().prompt({
                    title: 'Choose location',
                    message: 'Path to the folder that will contain the new project:',
                    placeholder: 'D:\\path\\to\\projects',
                });
            }
            throw e;
        }
    }

    /**
     * Scaffold a new project inside `parentDir` and open it. Optionally runs
     * `composer install` afterwards (not required for editing — the editor
     * resolves engine classes from its own vendor — but makes the project
     * runnable). Returns the created project dir and the install outcome
     * (null when not requested).
     */
    async function createProject(opts: {
        parentDir: string;
        name: string;
        install?: boolean;
        options?: NewProjectOptions;
    }): Promise<{ projectDir: string; installed: boolean | null }> {
        const scaffold = await createGameProject(opts.parentDir, opts.name, opts.options ?? {});
        await openProject(scaffold.projectDir);

        let installed: boolean | null = null;
        if (opts.install) {
            const res = await installProjectDependencies(scaffold.projectDir);
            installed = res.installed;
        }

        return { projectDir: scaffold.projectDir, installed };
    }

    async function fetchProject() {
        const data = await get<{ manifest: ProjectData['manifest']; projectDir: string; opened: boolean }>('/project');
        if (data.opened) {
            projectDir.value = data.projectDir;
            applyManifest({ manifest: data.manifest });
        }
    }

    async function fetchRecent() {
        const data = await get<{ recent: RecentProject[] }>('/project/recent');
        recent.value = data.recent;
    }

    /**
     * Open a project chosen from the recent list. If its directory is gone
     * the backend prunes it; we refresh the list so the dead entry vanishes.
     */
    async function openRecent(dir: string) {
        try {
            await openProject(dir);
        } catch (e) {
            await fetchRecent();
            throw e;
        }
    }

    return {
        name,
        projectDir,
        scenesPath,
        assetsPath,
        entryScene,
        defaultMode,
        opened,
        recent,
        openProject,
        openProjectWithDialog,
        importProject,
        importProjectWithDialog,
        createProject,
        pickCreateFolder,
        openRecent,
        fetchProject,
        fetchRecent,
    };
});
