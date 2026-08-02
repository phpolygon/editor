<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Gltf\GlbParser;
use PHPolygon\Editor\Project\ProjectAssetCache;
use PHPolygon\Editor\Scene\SceneClassResolver;
use PHPolygon\Editor\SceneDocument;
use PHPolygon\Editor\Support\Path;
use PHPolygon\Geometry\MeshRegistry;
use RuntimeException;

/**
 * Import a whole Blender-authored world from a `.glb` into the editor as
 * engine-native, code-authored assets.
 *
 * Unlike the frontend three.js import (which bakes ONE prop and normalises it
 * to a unit cube, discarding placement), this reads the GLB in pure PHP via
 * {@see GlbParser}, keeps every node at its authored world transform, and
 * batches the geometry per material. It then persists:
 *   - one `assets/meshes/<id>.mesh.json` (raw) per material batch,
 *   - one `assets/materials/<id>.material.json` per material,
 *   - a Scene of raw-geometry entities (Transform3D + MeshRenderer), written as
 *     a transpiled PHP scene class (the canonical source).
 * and seeds {@see ProjectAssetCache} so the viewport renders the imported mesh
 * ids immediately (get_mesh reads the registry + that cache, not the raw asset
 * files).
 *
 * Args: `{ glbPath?: string, glbPaths?: string[], sceneName: string, idPrefix?: string }`.
 * Pass `glbPaths` to merge several GLBs (terrain + districts + props) into ONE
 * walkable scene; each file gets its own mesh-id prefix so ids never collide,
 * while materials dedupe by name across files. Paths may be absolute or relative
 * to the project root.
 *
 * Persists BOTH a transpiled PHP scene class (the canonical source) AND a
 * `<Class>.scene.json` snapshot, so the editor's LoadScene takes the direct
 * JSON path (no build() execution) and the viewport resolves the raw meshes via
 * the ProjectAssetCache seed + the on-disk assets/meshes fallback.
 *
 * This importer is game-agnostic: it emits ONLY geometry + materials. Node-name
 * gameplay conventions (terminals, NPCs, ground colliders) are the consuming
 * game's concern and are deliberately not interpreted here.
 */
class ImportGltfSceneCommand implements CommandInterface
{
    private const MESHES_SUBDIR = 'meshes';
    private const MATERIALS_SUBDIR = 'materials';

    /** @param array<string, mixed> $args */
    public function __construct(private readonly array $args = []) {}

    public function execute(EditorContext $context): array
    {
        $paths = $this->resolveGlbPaths($context);
        if ($paths === []) {
            throw new RuntimeException("Missing 'glbPath' or 'glbPaths' argument");
        }
        $sceneName = is_string($this->args['sceneName'] ?? null) ? trim($this->args['sceneName']) : '';
        if ($sceneName === '' || preg_replace('/[^A-Za-z0-9_\-]/', '', $sceneName) !== $sceneName) {
            throw new RuntimeException('Invalid sceneName (use letters, digits, underscore or hyphen)');
        }
        $basePrefix = is_string($this->args['idPrefix'] ?? null) ? trim($this->args['idPrefix']) : '';
        $basePrefix = preg_replace('/[^A-Za-z0-9_]/', '_', $basePrefix) ?: 'glb';

        $meshesDir = $this->ensureDir(Path::join($context->getAssetsDir(), self::MESHES_SUBDIR));
        $materialsDir = $this->ensureDir(Path::join($context->getAssetsDir(), self::MATERIALS_SUBDIR));

        /** @var array<string, mixed> $cacheMeshes */
        $cacheMeshes = [];
        /** @var array<string, mixed> $cacheMaterials */
        $cacheMaterials = [];
        $entities = [];
        $meshTotal = 0;

        // Parse + batch each GLB in world space, merging all into one scene. Each
        // file gets its own mesh-id prefix (unique ids); materials dedupe by name.
        foreach ($paths as $glbPath) {
            $prefix = count($paths) === 1
                ? $basePrefix
                : $basePrefix . '_' . $this->slug(basename($glbPath, '.glb'));
            $result = GlbParser::parse($glbPath, $prefix);

            foreach ($result->meshes as $meshId => $mesh) {
                $this->writeJson(Path::join($meshesDir, $meshId . '.mesh.json'), [
                    'name' => $meshId,
                    'raw' => [
                        'vertices' => $mesh->vertices,
                        'normals' => $mesh->normals,
                        'uvs' => $mesh->uvs,
                        'indices' => $mesh->indices,
                    ],
                ]);
                MeshRegistry::register($meshId, $mesh);
                $cacheMeshes[$meshId] = ProjectAssetCache::meshToArray($meshId, $mesh, MeshRegistry::version($meshId));
                $meshTotal++;
            }
            foreach ($result->materials as $matId => $material) {
                if (isset($cacheMaterials[$matId])) {
                    continue; // shared across files – write once
                }
                $array = ProjectAssetCache::materialToArray($matId, $material);
                $this->writeJson(Path::join($materialsDir, $matId . '.material.json'), $array);
                $cacheMaterials[$matId] = $array;
            }
            foreach ($result->meshMaterials as $meshId => $matId) {
                $entities[] = [
                    'name' => $meshId,
                    'components' => [
                        [
                            '_class' => 'PHPolygon\\Component\\Transform3D',
                            'position' => ['x' => 0, 'y' => 0, 'z' => 0],
                            'rotation' => ['x' => 0, 'y' => 0, 'z' => 0, 'w' => 1],
                            'scale' => ['x' => 1, 'y' => 1, 'z' => 1],
                        ],
                        [
                            '_class' => 'PHPolygon\\Component\\MeshRenderer',
                            'meshId' => $meshId,
                            'materialId' => $matId,
                        ],
                    ],
                ];
            }
        }

        if ($entities === []) {
            throw new RuntimeException('GLB(s) produced no geometry');
        }

        // Seed the preview cache so the viewport's get_mesh resolves the ids.
        ProjectAssetCache::write($context->projectDir, $cacheMeshes, $cacheMaterials);

        // Persist a transpiled PHP scene class (canonical source) + a JSON
        // snapshot (LoadScene prefers it → loads directly, no build() execution).
        $scenesDir = $this->ensureDir($context->getScenesDir());
        $className = str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $sceneName)));
        $scenePath = Path::join($scenesDir, $className . '.php');
        $jsonPath = Path::join($scenesDir, $className . '.scene.json');

        $namespace = SceneClassResolver::namespaceFor($scenePath, $context);
        $sceneFqcn = $namespace === '' ? $className : $namespace . '\\' . $className;

        $data = [
            '_version' => 1,
            '_scene' => $sceneFqcn,
            'name' => $sceneName,
            'systems' => [],
            'entities' => $entities,
        ];

        if (file_put_contents($scenePath, $context->transpiler->fromArray($data)) === false) {
            throw new RuntimeException("Failed to write scene file: {$scenePath}");
        }
        $this->writeJson($jsonPath, $data);
        $context->setActiveDocument(new SceneDocument($data));

        return [
            'imported' => true,
            'scene' => $scenePath,
            'sceneJson' => $jsonPath,
            'sceneClass' => $sceneFqcn,
            'glbCount' => count($paths),
            'meshCount' => $meshTotal,
            'materialCount' => count($cacheMaterials),
            'entityCount' => count($entities),
        ];
    }

    /**
     * Resolve the `glbPath` / `glbPaths` args into a list of existing absolute
     * paths (relative paths resolve against the project root).
     *
     * @return list<string>
     */
    private function resolveGlbPaths(EditorContext $context): array
    {
        $raw = [];
        if (is_string($this->args['glbPath'] ?? null) && trim($this->args['glbPath']) !== '') {
            $raw[] = trim($this->args['glbPath']);
        }
        if (is_array($this->args['glbPaths'] ?? null)) {
            foreach ($this->args['glbPaths'] as $p) {
                if (is_string($p) && trim($p) !== '') {
                    $raw[] = trim($p);
                }
            }
        }
        $out = [];
        foreach ($raw as $p) {
            $abs = $this->isAbsolute($p) ? $p : Path::join($context->projectDir, $p);
            if (! is_file($abs)) {
                throw new RuntimeException("GLB not found: {$abs}");
            }
            $out[] = $abs;
        }
        return $out;
    }

    private function slug(string $value): string
    {
        $s = preg_replace('/[^A-Za-z0-9_]+/', '_', strtolower($value)) ?? '';
        return trim($s, '_');
    }

    private function isAbsolute(string $path): bool
    {
        return $path !== '' && (
            $path[0] === '/'
            || $path[0] === '\\'
            || (strlen($path) > 1 && $path[1] === ':')
        );
    }

    private function ensureDir(string $dir): string
    {
        if (! is_dir($dir) && ! mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            throw new RuntimeException("Failed to create directory: {$dir}");
        }
        return $dir;
    }

    /** @param array<string, mixed> $data */
    private function writeJson(string $path, array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents($path, $json) === false) {
            throw new RuntimeException("Failed to write file: {$path}");
        }
    }
}
