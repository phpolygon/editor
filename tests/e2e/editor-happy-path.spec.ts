import { test, expect } from '@playwright/test';
import { promises as fs } from 'fs';
import * as path from 'path';
import { setupTempProject, teardownTempProject } from './helpers/project';

let projectDir: string;

test.beforeAll(async () => {
    projectDir = await setupTempProject();
});

test.afterAll(async () => {
    await teardownTempProject(projectDir);
});

test('happy path: open project → load scene → create primitive → save prefab', async ({ page }) => {
    // Seed the session via page.request so the cookie lives in the same
    // context that page.goto then uses.
    const openResponse = await page.request.post('/api/editor/project/open', {
        data: { dir: projectDir },
    });
    expect(openResponse.ok()).toBeTruthy();

    await page.goto('/');

    // Scene name in the SceneViewPanel header signals that fetchProject +
    // load(entryScene) finished.
    await expect(page.getByText('Scene: MainScene')).toBeVisible({ timeout: 15_000 });

    // Hierarchy shows the two seed entities from MainScene::build.
    await expect(page.getByText('CameraRig')).toBeVisible();
    await expect(page.getByText('Origin')).toBeVisible();

    // Open the Create dropdown and add a Box primitive.
    await page.getByRole('button', { name: '+ Create' }).click();
    await page.getByRole('button', { name: 'Box', exact: true }).click();
    await expect(page.getByText('Added box')).toBeVisible({ timeout: 5_000 });
    await expect(page.getByRole('heading', { name: /Box/ }).or(page.getByText('Box', { exact: true }))).toBeVisible();

    // Save Prefab — handle the window.prompt dialog.
    page.once('dialog', (dialog) => {
        expect(dialog.type()).toBe('prompt');
        return dialog.accept('TestPrefab');
    });
    await page.getByRole('button', { name: 'Save Prefab' }).click();

    await expect(page.getByText(/Saved prefab: TestPrefab/i)).toBeVisible({ timeout: 5_000 });

    // Prefab file exists on disk.
    const prefabPath = path.join(projectDir, 'assets', 'prefabs', 'TestPrefab.prefab.json');
    const stat = await fs.stat(prefabPath);
    expect(stat.isFile()).toBe(true);

    const parsed = JSON.parse(await fs.readFile(prefabPath, 'utf8'));
    expect(parsed.name).toBe('TestPrefab');
    expect(parsed.root.name).toBe('Box');
    const meshRenderer = parsed.root.components.find(
        (c: { _class: string }) => c._class === 'PHPolygon\\Component\\MeshRenderer',
    );
    expect(meshRenderer).toBeDefined();
    expect(meshRenderer.meshId).toBe('editor_primitive_box');
});

test('2D/3D mode toggle persists per scene via localStorage', async ({ page }) => {
    const openResponse = await page.request.post('/api/editor/project/open', {
        data: { dir: projectDir },
    });
    expect(openResponse.ok()).toBeTruthy();

    await page.goto('/');
    await expect(page.getByText('Scene: MainScene')).toBeVisible({ timeout: 15_000 });

    await page.getByRole('button', { name: '2D', exact: true }).click();

    // Reload — localStorage persists across reloads.
    await page.reload();
    await expect(page.getByText('Scene: MainScene')).toBeVisible({ timeout: 15_000 });

    const stored = await page.evaluate(
        () => localStorage.getItem('phpolygon-editor:scene-mode:MainScene'),
    );
    expect(stored).toBe('2d');
});
