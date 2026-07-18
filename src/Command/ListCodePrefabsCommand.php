<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use Symfony\Component\Process\Process;

/**
 * List the game's editor-placeable CODE prefabs — the engine {@see \PHPolygon\Scene\Prefab}
 * classes the game registers, as opposed to the file-based `assets/prefabs/*.prefab.json`
 * that {@see ListPrefabsCommand} covers.
 *
 * The game exposes them through a headless command declared in the project
 * manifest (`prefabsCommand`, cwd = project dir) that prints
 * `{ "prefabs": [ { "name", "class", "variants"? } ] }` on stdout. Placing one
 * spawns a prefab REFERENCE (class + variant + transform) rather than inlined
 * geometry; the game regenerates the geometry from the prefab's build() at load.
 *
 * Best-effort: a missing command, a non-zero exit, or malformed output yields an
 * empty list rather than an error, so the prefab palette degrades gracefully.
 */
class ListCodePrefabsCommand implements CommandInterface
{
    /** @param array<string, mixed> $args */
    public function __construct(private readonly array $args = []) {}

    /** @return array{prefabs: list<array{name: string, class: string, variants?: list<string>}>} */
    public function execute(EditorContext $context): array
    {
        $command = $context->manifest->prefabsCommand;
        if ($command === '') {
            return ['prefabs' => []];
        }

        try {
            $process = Process::fromShellCommandline(
                $command,
                $context->projectDir !== '' ? $context->projectDir : null,
                null,
                null,
                60.0,
            );
            $process->run();
            if (! $process->isSuccessful()) {
                return ['prefabs' => []];
            }
            $decoded = json_decode($process->getOutput(), true);
        } catch (\Throwable) {
            return ['prefabs' => []];
        }

        if (! is_array($decoded) || ! is_array($decoded['prefabs'] ?? null)) {
            return ['prefabs' => []];
        }

        $prefabs = [];
        foreach ($decoded['prefabs'] as $entry) {
            if (! is_array($entry) || ! is_string($entry['name'] ?? null) || ! is_string($entry['class'] ?? null)) {
                continue;
            }
            $prefab = ['name' => $entry['name'], 'class' => $entry['class']];
            if (is_array($entry['variants'] ?? null)) {
                $prefab['variants'] = array_values(array_filter($entry['variants'], 'is_string'));
            }
            if (is_string($entry['variantComponent'] ?? null)) {
                $prefab['variantComponent'] = $entry['variantComponent'];
            }
            if (is_string($entry['variantProperty'] ?? null)) {
                $prefab['variantProperty'] = $entry['variantProperty'];
            }
            $prefabs[] = $prefab;
        }

        usort($prefabs, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return ['prefabs' => $prefabs];
    }
}
