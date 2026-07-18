<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Project\ProjectAssetCache;
use PHPolygon\Editor\Scene\EntityFormatter;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Expand the active scene's code-prefab REFERENCES into real geometry for the
 * viewport preview.
 *
 * A code prefab stores only `prefab` + variant + transform; the editor cannot
 * render it. This runs the game's headless `expandCommand` (project manifest,
 * cwd = project dir, input + output scene paths appended), which boots the engine
 * and re-runs each prefab's build() to produce a bundle `{entities, meshes,
 * materials}`. The meshes/materials are written into {@see ProjectAssetCache} so
 * get_mesh / get_material can serve them, and the expanded (nested) entity tree
 * is returned for rendering. The active SceneDocument keeps the AUTHORED
 * references, so this is a read-only preview; call it again after edits.
 *
 * Best-effort: no expand command, no prefab refs, or a failed run returns the
 * authored scene as-is (nested) rather than erroring.
 */
class ExpandSceneCommand implements CommandInterface
{
    /** @param array<string, mixed> $args */
    public function __construct(private readonly array $args = []) {}

    /** @return array<string, mixed> */
    public function execute(EditorContext $context): array
    {
        $doc = $context->getActiveDocument();
        if ($doc === null) {
            throw new RuntimeException('No active scene document');
        }

        $data = $doc->toArray();
        /** @var list<array<string, mixed>> $entities */
        $entities = is_array($data['entities'] ?? null) ? array_values($data['entities']) : [];

        $command = $context->manifest->expandCommand;
        if ($command === '' || ! $this->hasPrefabRef($entities)) {
            $data['entities'] = EntityFormatter::nestComponents($entities);

            return $data;
        }

        $bundle = $this->runExpand($command, $data, $context->projectDir);
        if ($bundle === null) {
            // Expansion failed — fall back to the authored scene.
            $data['entities'] = EntityFormatter::nestComponents($entities);

            return $data;
        }

        /** @var array<string, mixed> $meshes */
        $meshes = is_array($bundle['meshes'] ?? null) ? $bundle['meshes'] : [];
        /** @var array<string, mixed> $materials */
        $materials = is_array($bundle['materials'] ?? null) ? $bundle['materials'] : [];
        ProjectAssetCache::write($context->projectDir, $meshes, $materials);

        /** @var list<array<string, mixed>> $expanded */
        $expanded = is_array($bundle['entities'] ?? null) ? array_values($bundle['entities']) : [];

        return [
            'name' => is_string($data['name'] ?? null) ? $data['name'] : '',
            'entities' => EntityFormatter::nestComponents($expanded),
            'expanded' => true,
        ];
    }

    /**
     * @param list<array<string, mixed>> $entities
     */
    private function hasPrefabRef(array $entities): bool
    {
        foreach ($entities as $entity) {
            if (is_string($entity['prefab'] ?? null) && $entity['prefab'] !== '') {
                return true;
            }
            if (is_array($entity['children'] ?? null) && $this->hasPrefabRef(array_values($entity['children']))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $sceneData
     * @return array<string, mixed>|null
     */
    private function runExpand(string $command, array $sceneData, string $projectDir): ?array
    {
        $input = tempnam(sys_get_temp_dir(), 'phpolygon-expand-in');
        $output = tempnam(sys_get_temp_dir(), 'phpolygon-expand-out');
        if ($input === false || $output === false) {
            return null;
        }

        try {
            file_put_contents($input, (string) json_encode($sceneData, JSON_UNESCAPED_SLASHES));

            $process = Process::fromShellCommandline(
                $command . ' ' . escapeshellarg($input) . ' ' . escapeshellarg($output),
                $projectDir !== '' ? $projectDir : null,
                null,
                null,
                120.0,
            );
            $process->run();
            if (! $process->isSuccessful()) {
                return null;
            }

            $raw = file_get_contents($output);
            if ($raw === false) {
                return null;
            }
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable) {
            return null;
        } finally {
            @unlink($input);
            @unlink($output);
        }
    }
}
