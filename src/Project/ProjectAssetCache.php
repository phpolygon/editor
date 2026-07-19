<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Project;

use PHPolygon\Geometry\MeshData;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;

/**
 * Bridges the engine's per-request static registries to the editor's stateless
 * request model.
 *
 * A project's materials and meshes are often produced imperatively inside a
 * scene's PHP `build()` — they only live in {@see MaterialRegistry} /
 * {@see MeshRegistry} for the duration of the request that ran the build. A
 * later `get_material` / `get_mesh` request starts with empty registries and
 * would fail with "Unknown material/mesh".
 *
 * So right after a scene loads (build has populated the registries) we snapshot
 * them to a per-project cache on disk; the getters fall back to that snapshot
 * on a registry miss. The array shapes match the live command responses.
 *
 * Storage is ONE small file PER asset (`<kind>/<md5(id)>.json`) plus an
 * `<kind>/_index.json` id list. The viewport fetches one get_mesh per unique
 * mesh, and the dev server resets PHP statics between requests, so a single
 * combined file would be json_decode()'d in full on every lookup — tens of MB
 * each time for a big scene (slow, and it blew the memory_limit). Per-asset
 * files keep every lookup O(one small file).
 */
final class ProjectAssetCache
{
    private static function dir(string $projectDir): string
    {
        return sys_get_temp_dir().DIRECTORY_SEPARATOR.'phpolygon-editor-assets'.DIRECTORY_SEPARATOR.md5($projectDir);
    }

    /** Snapshot every registered material + mesh for the project. */
    public static function capture(string $projectDir): void
    {
        $materials = [];
        foreach (MaterialRegistry::ids() as $id) {
            $material = MaterialRegistry::get($id);
            if ($material instanceof Material) {
                $materials[$id] = self::materialToArray($id, $material);
            }
        }

        $meshes = [];
        foreach (MeshRegistry::ids() as $id) {
            $mesh = MeshRegistry::get($id);
            if ($mesh instanceof MeshData) {
                $meshes[$id] = self::meshToArray($id, $mesh, MeshRegistry::version($id));
            }
        }

        self::write($projectDir, $meshes, $materials);
    }

    /**
     * Write a pre-built mesh/material snapshot into the cache — used both by
     * {@see capture()} and when the geometry was produced out-of-process (e.g. a
     * game `expandCommand` that boots the engine and returns a {meshes,
     * materials} bundle), so the shapes already match {@see meshToArray()} /
     * {@see materialToArray()}.
     *
     * @param array<string, mixed> $meshes    id => mesh array
     * @param array<string, mixed> $materials id => material array
     */
    public static function write(string $projectDir, array $meshes, array $materials): void
    {
        self::writeCollection($projectDir, 'meshes', $meshes);
        self::writeCollection($projectDir, 'materials', $materials);
    }

    /** @return array<string, mixed>|null */
    public static function material(string $projectDir, string $id): ?array
    {
        return self::readOne($projectDir, 'materials', $id);
    }

    /** @return array<string, mixed>|null */
    public static function mesh(string $projectDir, string $id): ?array
    {
        return self::readOne($projectDir, 'meshes', $id);
    }

    /** @return list<string> */
    public static function materialIds(string $projectDir): array
    {
        return self::readIndex($projectDir, 'materials');
    }

    /** @return list<string> */
    public static function meshIds(string $projectDir): array
    {
        return self::readIndex($projectDir, 'meshes');
    }

    /**
     * Every cached mesh, for a single bulk fetch — the viewport syncing hundreds
     * of entities would otherwise fire one get_mesh per unique mesh, and the
     * single-threaded dev server serialises them (seconds of round-trips).
     *
     * @return list<array<string, mixed>>
     */
    public static function allMeshes(string $projectDir): array
    {
        return self::readAll($projectDir, 'meshes');
    }

    /** @return list<array<string, mixed>> every cached material */
    public static function allMaterials(string $projectDir): array
    {
        return self::readAll($projectDir, 'materials');
    }

    /** @return array{id: string, albedo: array{r:float,g:float,b:float,a:float}, roughness: float, metallic: float, emission: array{r:float,g:float,b:float,a:float}, alpha: float, shader: string, albedoTexture: ?string, clearcoat: float, clearcoatRoughness: float, normalIntensity: float, useEnvironmentMap: bool, normalPattern: string, surfacePattern: string} */
    public static function materialToArray(string $id, Material $material): array
    {
        $color = static fn (Color $c): array => ['r' => $c->r, 'g' => $c->g, 'b' => $c->b, 'a' => $c->a];

        return [
            'id' => $id,
            'albedo' => $color($material->albedo),
            'roughness' => $material->roughness,
            'metallic' => $material->metallic,
            'emission' => $color($material->emission),
            'alpha' => $material->alpha,
            'shader' => $material->shader,
            'albedoTexture' => $material->albedoTexture,
            'clearcoat' => $material->clearcoat,
            'clearcoatRoughness' => $material->clearcoatRoughness,
            'normalIntensity' => $material->normalIntensity,
            'useEnvironmentMap' => $material->useEnvironmentMap,
            'normalPattern' => $material->normalPattern,
            'surfacePattern' => $material->surfacePattern,
        ];
    }

    /** @return array<string, mixed> */
    public static function meshToArray(string $id, MeshData $mesh, int $version): array
    {
        return [
            'id' => $id,
            'version' => $version,
            'vertices' => $mesh->vertices,
            'normals' => $mesh->normals,
            'uvs' => $mesh->uvs,
            'indices' => $mesh->indices,
            'vertexCount' => $mesh->vertexCount(),
            'triangleCount' => $mesh->triangleCount(),
        ];
    }

    /**
     * Write one asset kind: a small file per id (keyed by md5 for a safe, fixed
     * filename) plus an `_index.json` id list for the list_* commands.
     *
     * @param array<string, mixed> $items id => asset array
     */
    private static function writeCollection(string $projectDir, string $kind, array $items): void
    {
        $subdir = self::dir($projectDir).DIRECTORY_SEPARATOR.$kind;
        if (! is_dir($subdir) && ! @mkdir($subdir, 0o777, true) && ! is_dir($subdir)) {
            return;
        }

        $ids = [];
        foreach ($items as $id => $data) {
            $id = (string) $id;
            $ids[] = $id;
            @file_put_contents($subdir.DIRECTORY_SEPARATOR.md5($id).'.json', json_encode($data));
        }

        @file_put_contents($subdir.DIRECTORY_SEPARATOR.'_index.json', json_encode($ids));
    }

    /** @return array<string, mixed>|null */
    private static function readOne(string $projectDir, string $kind, string $id): ?array
    {
        $path = self::dir($projectDir).DIRECTORY_SEPARATOR.$kind.DIRECTORY_SEPARATOR.md5($id).'.json';
        if (! is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    /** @return list<array<string, mixed>> */
    private static function readAll(string $projectDir, string $kind): array
    {
        $out = [];
        foreach (self::readIndex($projectDir, $kind) as $id) {
            $data = self::readOne($projectDir, $kind, $id);
            if ($data !== null) {
                $out[] = $data;
            }
        }

        return $out;
    }

    /** @return list<string> */
    private static function readIndex(string $projectDir, string $kind): array
    {
        $path = self::dir($projectDir).DIRECTORY_SEPARATOR.$kind.DIRECTORY_SEPARATOR.'_index.json';
        if (! is_file($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            return [];
        }
        $data = json_decode($raw, true);

        return is_array($data) ? array_values(array_map('strval', $data)) : [];
    }
}
