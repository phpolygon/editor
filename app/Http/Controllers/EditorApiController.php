<?php

namespace App\Http\Controllers;

use App\Services\GameBuildRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Native\Desktop\Dialog;
use PHPolygon\Editor\Command\EditorCommandBus;
use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Project\ProjectAutoloader;
use PHPolygon\Editor\Project\ProjectImporter;
use PHPolygon\Editor\Project\ProjectLoader;
use PHPolygon\Editor\Project\RecentProjects;
use PHPolygon\Editor\Registry\ComponentRegistry;
use PHPolygon\Editor\Support\Path;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class EditorApiController extends Controller
{
    public function dispatch(Request $request, EditorCommandBus $bus): JsonResponse
    {
        $request->validate([
            'command' => 'required|string',
            'args' => 'array',
        ]);

        try {
            $result = $bus->dispatch(
                $request->input('command'),
                $request->input('args', []),
            );

            return response()->json(['ok' => true, 'data' => $result]);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    public function project(EditorContext $context): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'data' => [
                'manifest' => $context->manifest->toArray(),
                'projectDir' => $context->projectDir,
                'opened' => $context->projectDir !== '',
            ],
        ]);
    }

    public function openProject(
        Request $request,
        ProjectLoader $loader,
        ComponentRegistry $registry,
        RecentProjects $recent,
    ): JsonResponse {
        $request->validate(['dir' => 'required|string']);

        $dir = $request->input('dir');

        if (! is_dir($dir)) {
            // Drop a stale entry if the user opened it from the recent menu.
            $recent->remove($dir);

            return response()->json(['ok' => false, 'error' => "Directory not found: {$dir}"], 422);
        }

        return $this->loadProject($dir, $loader, $registry, $recent);
    }

    /**
     * Recently opened projects, most-recent first. Stale entries (manifest
     * gone) are pruned before returning so the menu never offers dead paths.
     */
    public function recentProjects(RecentProjects $recent): JsonResponse
    {
        return response()->json(['ok' => true, 'data' => ['recent' => $recent->prune()]]);
    }

    public function openProjectDialog(
        ProjectLoader $loader,
        ComponentRegistry $registry,
        RecentProjects $recent,
    ): JsonResponse {
        try {
            $dir = Dialog::new()
                ->title('Open PHPolygon Project')
                ->folders()
                ->button('Open')
                ->open();
        } catch (\Throwable $e) {
            // NativePHP's Dialog facade talks to the Electron bridge on
            // localhost:4000. Web-only runs (`composer dev`) have no
            // Electron — the frontend can fall back to prompting for a
            // path and POSTing to /project/open directly.
            return response()->json([
                'ok' => false,
                'error' => 'Native dialog unavailable',
                'fallback' => 'path-input',
            ], 503);
        }

        if (! $dir || ! is_dir($dir)) {
            return response()->json(['ok' => false, 'error' => 'No directory selected'], 422);
        }

        return $this->loadProject($dir, $loader, $registry, $recent);
    }

    public function importProject(
        Request $request,
        ProjectImporter $importer,
        ProjectLoader $loader,
        ComponentRegistry $registry,
        RecentProjects $recent,
    ): JsonResponse {
        $request->validate(['dir' => 'required|string']);

        return $this->importAndLoad($request->input('dir'), $importer, $loader, $registry, $recent);
    }

    public function importProjectDialog(
        ProjectImporter $importer,
        ProjectLoader $loader,
        ComponentRegistry $registry,
        RecentProjects $recent,
    ): JsonResponse {
        try {
            $dir = Dialog::new()
                ->title('Import PHPolygon Project')
                ->folders()
                ->button('Import')
                ->open();
        } catch (\Throwable $e) {
            // No Electron bridge (web-only `composer dev`): the frontend
            // falls back to prompting for a path and POSTing to /project/import.
            return response()->json([
                'ok' => false,
                'error' => 'Native dialog unavailable',
                'fallback' => 'path-input',
            ], 503);
        }

        if (! $dir || ! is_dir($dir)) {
            return response()->json(['ok' => false, 'error' => 'No directory selected'], 422);
        }

        return $this->importAndLoad($dir, $importer, $loader, $registry, $recent);
    }

    /**
     * Pick a folder via the native dialog and return its path (without opening
     * anything) — used by the "New Project" flow to choose where the project
     * directory gets created. Falls back to a path prompt when there is no
     * Electron bridge (web-only `composer dev`), mirroring openProjectDialog.
     */
    public function pickFolder(): JsonResponse
    {
        try {
            $dir = Dialog::new()
                ->title('Select folder for the new project')
                ->folders()
                ->button('Select')
                ->open();
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'error' => 'Native dialog unavailable',
                'fallback' => 'path-input',
            ], 503);
        }

        if (! $dir || ! is_dir($dir)) {
            return response()->json(['ok' => false, 'error' => 'No folder selected'], 422);
        }

        return response()->json(['ok' => true, 'data' => ['dir' => $dir]]);
    }

    /**
     * Start a detached package build for the currently open project and return
     * a build id the UI polls via buildStatus. Requires an open project whose
     * dependencies are installed (vendor/bin/phpolygon present).
     */
    public function buildStart(Request $request, EditorContext $context, GameBuildRunner $runner): JsonResponse
    {
        $projectDir = $context->projectDir;
        if ($projectDir === '' || ! is_dir($projectDir)) {
            return response()->json(['ok' => false, 'error' => 'No project is open'], 422);
        }
        if (! is_file(Path::join($projectDir, 'vendor/bin/phpolygon'))) {
            return response()->json([
                'ok' => false,
                'error' => 'Project dependencies are not installed yet. Install them first.',
            ], 422);
        }

        $platform = (string) $request->input('platform', '');
        $variant = $request->input('variant') === 'steam' ? 'steam' : 'base';
        $phpVersion = $request->input('phpVersion') === '8.4' ? '8.4' : '8.5';
        $docker = $request->boolean('docker');
        $platforms = (string) $request->input('platforms', '');

        $id = $runner->start($projectDir, $platform, $variant, $phpVersion, $docker, $platforms);

        return response()->json(['ok' => true, 'data' => [
            'buildId' => $id,
            'outputDir' => Path::join($projectDir, 'build'),
        ]]);
    }

    /** Poll the live log + completion state of a build started via buildStart. */
    public function buildStatus(Request $request, GameBuildRunner $runner): JsonResponse
    {
        return response()->json(['ok' => true, 'data' => $runner->status((string) $request->input('id', ''))]);
    }

    public function browseAsset(EditorContext $context): JsonResponse
    {
        try {
            $assetsDir = $context->getAssetsDir();
            $path = Dialog::new()
                ->title('Select Asset')
                ->files()
                ->defaultPath($assetsDir)
                ->open();
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'error' => 'Native dialog unavailable',
                'fallback' => 'path-input',
            ], 503);
        }

        if (! $path) {
            return response()->json(['ok' => false, 'error' => 'No file selected'], 422);
        }

        $assetsDir = Path::normalize($context->getAssetsDir());
        $path = Path::normalize($path);
        $relativePath = str_starts_with($path, $assetsDir.DIRECTORY_SEPARATOR)
            ? ltrim(substr($path, strlen($assetsDir)), DIRECTORY_SEPARATOR)
            : basename($path);

        // Store asset references with forward slashes so scenes stay portable.
        return response()->json(['ok' => true, 'data' => ['path' => Path::toPortable($relativePath)]]);
    }

    public function assets(Request $request, EditorContext $context): JsonResponse
    {
        $subPath = $request->input('path', '');
        $assetsDir = $context->getAssetsDir();
        $fullPath = $subPath ? $assetsDir.DIRECTORY_SEPARATOR.$subPath : $assetsDir;

        if (! is_dir($fullPath)) {
            return response()->json(['ok' => true, 'data' => ['files' => [], 'path' => $subPath]]);
        }

        $files = [];
        $iterator = new \DirectoryIterator($fullPath);
        foreach ($iterator as $file) {
            if ($file->isDot()) {
                continue;
            }
            $files[] = [
                'name' => $file->getFilename(),
                'isDir' => $file->isDir(),
                'size' => $file->isFile() ? $file->getSize() : 0,
                'ext' => $file->isFile() ? $file->getExtension() : null,
            ];
        }

        usort($files, fn ($a, $b) => ($b['isDir'] <=> $a['isDir']) ?: strcmp($a['name'], $b['name']));

        return response()->json(['ok' => true, 'data' => ['files' => $files, 'path' => $subPath]]);
    }

    public function assetFile(Request $request, EditorContext $context): BinaryFileResponse|JsonResponse
    {
        $relPath = (string) $request->input('path', '');
        if ($relPath === '') {
            return response()->json(['ok' => false, 'error' => 'Missing path'], 422);
        }

        $assetsDir = realpath($context->getAssetsDir());
        if ($assetsDir === false) {
            return response()->json(['ok' => false, 'error' => 'Assets directory not found'], 404);
        }

        $candidate = realpath($assetsDir.DIRECTORY_SEPARATOR.$relPath);
        if ($candidate === false || ! is_file($candidate) || ! str_starts_with($candidate, $assetsDir.DIRECTORY_SEPARATOR)) {
            return response()->json(['ok' => false, 'error' => 'File not found'], 404);
        }

        return response()->file($candidate);
    }

    /**
     * Generate the editor manifest for a folder that lacks one (or load the
     * existing one), then open the project. The response carries a `created`
     * flag so the UI can distinguish a fresh import from opening a project
     * that was already initialized.
     */
    private function importAndLoad(
        string $dir,
        ProjectImporter $importer,
        ProjectLoader $loader,
        ComponentRegistry $registry,
        RecentProjects $recent,
    ): JsonResponse {
        if (! is_dir($dir)) {
            return response()->json(['ok' => false, 'error' => "Directory not found: {$dir}"], 422);
        }

        try {
            $result = $importer->import($dir);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        $response = $this->loadProject($dir, $loader, $registry, $recent);

        $payload = $response->getData(true);
        if (($payload['ok'] ?? false) === true) {
            $payload['data']['created'] = $result['created'];
            $response->setData($payload);
        }

        return $response;
    }

    private function loadProject(
        string $dir,
        ProjectLoader $loader,
        ComponentRegistry $registry,
        RecentProjects $recent,
    ): JsonResponse {
        try {
            $manifest = $loader->load($dir);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        // Store in session
        session(['editor_project_dir' => $dir]);

        // Remember it for the "Recent Projects" menu (survives restarts).
        $recent->add($dir, $manifest->name);

        // Make the project's own classes loadable so component discovery below
        // (and later scene loading) can resolve project-defined classes.
        app(ProjectAutoloader::class)->register($dir, $manifest->psr4Roots);

        // Scan components from project's PSR-4 roots
        foreach ($manifest->psr4Roots as $namespace => $path) {
            $fullPath = $dir.DIRECTORY_SEPARATOR.$path;
            if (is_dir($fullPath)) {
                $registry->scan($fullPath, $namespace);
            }
        }

        // Also scan engine's built-in components
        $engineComponentDir = dirname(__DIR__, 3).'/vendor/phpolygon/phpolygon/src/Component';
        if (is_dir($engineComponentDir)) {
            $registry->scan($engineComponentDir, 'PHPolygon\\Component\\');
        }

        // Clear singletons to rebuild with new project
        app()->forgetInstance(EditorContext::class);
        app()->forgetInstance(EditorCommandBus::class);

        return response()->json([
            'ok' => true,
            'data' => [
                'manifest' => $manifest->toArray(),
                'components' => $registry->toArray(),
                'projectDir' => $dir,
            ],
        ]);
    }
}
