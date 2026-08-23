<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Registry\PrefabScanner;
use PHPolygon\Editor\Support\PhpCommand;
use PHPolygon\Scene\Prefab;
use Symfony\Component\Process\Process;

/**
 * List the project's editor-placeable CODE prefabs — engine
 * {@see Prefab} classes, as opposed to the file-based
 * `assets/prefabs/*.prefab.json` that {@see ListPrefabsCommand} covers.
 *
 * Placing one spawns a prefab REFERENCE (class + variant + transform) rather
 * than inlined geometry; the game regenerates the geometry from the prefab's
 * build() at load.
 *
 * Two sources, merged:
 *
 *  - The game's own list, via the `prefabsCommand` declared in the project
 *    manifest (cwd = project dir), printing
 *    `{ "prefabs": [ { "name", "class", "variants"? } ] }` on stdout. This is
 *    authoritative: only the game knows about variants and about prefabs it
 *    registers under a name at runtime.
 *  - A scan of the project's PSR-4 roots ({@see PrefabScanner}). Prefab
 *    references resolve through the autoloader at runtime, so a class needs no
 *    registration to work — without the scan, a prefab the editor had just
 *    generated stayed invisible until someone wired it into the game by hand.
 *
 * Best-effort throughout: a missing command, a non-zero exit, or malformed
 * output falls back to the scan rather than erroring, so the palette degrades
 * gracefully instead of going blank.
 */
class ListCodePrefabsCommand implements CommandInterface
{
    /** @param array<string, mixed> $args */
    public function __construct(private readonly array $args = []) {}

    /** @return array{prefabs: list<array{name: string, class: string, variants?: list<string>}>} */
    public function execute(EditorContext $context): array
    {
        return ['prefabs' => $this->merge(
            $this->fromGame($context),
            (new PrefabScanner)->scan($context->projectDir, $context->manifest->psr4Roots),
        )];
    }

    /**
     * Prefabs the game reports, or an empty list when it reports none.
     *
     * @return list<array{name: string, class: string, variants?: list<string>}>
     */
    private function fromGame(EditorContext $context): array
    {
        $command = $context->manifest->prefabsCommand;
        if ($command === '') {
            return [];
        }

        try {
            $process = Process::fromShellCommandline(
                PhpCommand::resolve($command),
                $context->projectDir !== '' ? $context->projectDir : null,
                null,
                null,
                60.0,
            );
            $process->run();
            if (! $process->isSuccessful()) {
                return [];
            }
            $decoded = json_decode($process->getOutput(), true);
        } catch (\Throwable) {
            return [];
        }

        if (! is_array($decoded) || ! is_array($decoded['prefabs'] ?? null)) {
            return [];
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

        return $prefabs;
    }

    /**
     * Combine both sources, keyed by class.
     *
     * The game's entry wins on overlap: it may carry variants and a display
     * name the scan cannot know. Scanned classes fill in everything the game
     * does not mention.
     *
     * @param  list<array{name: string, class: string, variants?: list<string>}>  $fromGame
     * @param  list<array{name: string, class: string}>  $scanned
     * @return list<array{name: string, class: string, variants?: list<string>}>
     */
    private function merge(array $fromGame, array $scanned): array
    {
        $byClass = [];
        foreach ($scanned as $prefab) {
            $byClass[$prefab['class']] = $prefab;
        }
        foreach ($fromGame as $prefab) {
            $byClass[$prefab['class']] = $prefab;
        }

        $prefabs = array_values($byClass);
        usort($prefabs, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $prefabs;
    }
}
