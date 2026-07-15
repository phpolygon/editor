<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Project\ProjectAssetCache;
use PHPolygon\Editor\Scene\EntityFormatter;
use PHPolygon\Editor\Scene\SceneClassResolver;
use PHPolygon\Editor\SceneDocument;
use PHPolygon\Editor\Support\Path;
use PHPolygon\Scene\Scene;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Process\Process;

class LoadSceneCommand implements CommandInterface
{
    /** @param array<string, mixed> $args */
    public function __construct(private readonly array $args = []) {}

    public function execute(EditorContext $context): array
    {
        $sceneName = (string) ($this->args['sceneName'] ?? $this->args['scene'] ?? '');
        if ($sceneName === '') {
            throw new RuntimeException("Missing 'sceneName' argument");
        }

        // Scenes are addressed by file basename. Accept a fully-qualified class
        // name too (e.g. a manifest entryScene like "CodeRescue\Scene\MainMenu")
        // by reducing it to the basename, so it is not mistaken for a path
        // under the scenes directory.
        if (str_contains($sceneName, '\\')) {
            $sceneName = substr((string) strrchr($sceneName, '\\'), 1);
        }

        $scenesDir = $context->getScenesDir();
        $jsonFile = Path::join($scenesDir, $sceneName.'.scene.json');

        // For the scene backed by the running game's World (its PHP build() is
        // empty by design), regenerate a fresh snapshot by running the project's
        // headless exporter — so the editor shows the game's real entities without
        // the game running. Best-effort: on failure we fall back to any existing
        // snapshot, then to the (empty) PHP scene.
        if ($sceneName === $context->manifest->liveWorldScene
            && $context->manifest->liveWorldCommand !== ''
        ) {
            $this->regenerateLiveWorld($context->manifest->liveWorldCommand, $jsonFile, $context->projectDir);
        }

        // Prefer an exported JSON snapshot (`<name>.scene.json`) — it is already
        // the transpiled format, so it loads directly without instantiating a
        // PHP scene class. This is how a live game world reaches the editor.
        if (is_file($jsonFile)) {
            return $this->loadJson($jsonFile, $sceneName, $context);
        }

        $sceneFile = Path::join($scenesDir, $sceneName.'.php');

        if (! file_exists($sceneFile)) {
            throw new RuntimeException("Scene file not found: {$sceneFile}");
        }

        // Load the scene class declared in the file.
        require_once $sceneFile;

        $className = SceneClassResolver::classFor($sceneFile, $context);

        if (! class_exists($className)) {
            throw new RuntimeException("Scene class not found: {$className}");
        }

        // Guard against non-scene classes that live in the scenes folder
        // (abstract bases, traits, static helpers). Instantiating those —
        // e.g. a class with a private constructor — would be a fatal error.
        if (! is_subclass_of($className, Scene::class)) {
            throw new RuntimeException("Class {$className} is not a PHPolygon scene");
        }

        if (! (new ReflectionClass($className))->isInstantiable()) {
            throw new RuntimeException(
                "Scene {$className} cannot be instantiated (its constructor is not public)",
            );
        }

        $scene = new $className;
        $data = $context->transpiler->toArray($scene);

        // build() just populated the mesh/material registries; snapshot them so
        // subsequent get_mesh / get_material requests (fresh, empty registries)
        // can still serve assets the scene created imperatively.
        ProjectAssetCache::capture($context->projectDir);

        // The document keeps the flat, on-disk component shape; the frontend
        // gets the nested shape it consumes for rendering and inspecting.
        $context->setActiveDocument(new SceneDocument($data));
        $data['entities'] = EntityFormatter::nestComponents($data['entities'] ?? []);

        return $data;
    }

    /**
     * Run the project's headless world-exporter to (re)write the live-world
     * snapshot at $outPath. Failures are swallowed: a missing PHP binary, a
     * broken exporter, or a timeout must not break scene loading — the caller
     * then falls back to a stale snapshot or the empty PHP scene.
     */
    private function regenerateLiveWorld(string $command, string $outPath, string $projectDir): void
    {
        try {
            $process = Process::fromShellCommandline(
                $command.' '.escapeshellarg($outPath),
                $projectDir !== '' ? $projectDir : null,
                null,
                null,
                60.0,
            );
            $process->run();
        } catch (\Throwable) {
            // best-effort — leave any existing snapshot in place
        }
    }

    /**
     * Load a scene from an exported `*.scene.json` snapshot.
     *
     * @return array<string, mixed>
     */
    private function loadJson(string $path, string $sceneName, EditorContext $context): array
    {
        $raw = file_get_contents($path);
        $data = $raw !== false ? json_decode($raw, true) : null;
        if (! is_array($data)) {
            throw new RuntimeException("Invalid scene JSON: {$path}");
        }

        // Fall back to the filename if the snapshot omits its name.
        if (! isset($data['name']) || ! is_string($data['name'])) {
            $data['name'] = $sceneName;
        }

        // The document keeps the flat, on-disk component shape; the frontend
        // gets the nested shape (mirrors the PHP-scene path).
        $context->setActiveDocument(new SceneDocument($data));
        $data['entities'] = EntityFormatter::nestComponents(
            is_array($data['entities'] ?? null) ? $data['entities'] : [],
        );

        return $data;
    }
}
