<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Project;

use PHPolygon\Editor\Support\Path;
use PHPolygon\Geometry\MeshData;

/**
 * Reads raw (baked) mesh assets straight from `assets/meshes/<id>.mesh.json`
 * into the {@see ProjectAssetCache::meshToArray()} shape.
 *
 * The viewport fetches geometry via `get_mesh` / `get_meshes`, which resolve
 * ids from the live {@see \PHPolygon\Geometry\MeshRegistry} and the temp
 * {@see ProjectAssetCache} snapshot — neither of which survives a fresh editor
 * session for meshes that were *imported* (not produced by a scene build). This
 * helper is the durable fallback: a raw mesh authored on disk (vertex-edited or
 * GLB-imported) renders even after the registry + temp cache are cold.
 *
 * Only the baked `{name, raw:{vertices,normals,uvs,indices}}` shape is served;
 * procedural graph assets (`{name, nodes, output}`) need the mesh-graph
 * evaluator and are skipped here.
 */
final class RawMeshAssets
{
    private const SUBDIR = 'meshes';

    /** Load one raw mesh asset by id, or null if absent / not a raw mesh. */
    public static function load(string $assetsDir, string $id): ?array
    {
        $path = Path::join($assetsDir, self::SUBDIR . '/' . $id . '.mesh.json');
        if (! is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        if (! is_array($data) || ! is_array($data['raw'] ?? null)) {
            return null;
        }
        return self::toArray($id, $data['raw']);
    }

    /**
     * Every raw mesh asset on disk, keyed by id.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(string $assetsDir): array
    {
        $dir = Path::join($assetsDir, self::SUBDIR);
        if (! is_dir($dir)) {
            return [];
        }
        $out = [];
        foreach (glob($dir . '/*.mesh.json') ?: [] as $file) {
            $id = basename($file, '.mesh.json');
            $mesh = self::load($assetsDir, $id);
            if ($mesh !== null) {
                $out[$id] = $mesh;
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private static function toArray(string $id, array $raw): array
    {
        $floats = static fn (mixed $v): array => is_array($v)
            ? array_map(static fn ($n): float => is_numeric($n) ? (float) $n : 0.0, $v)
            : [];
        $ints = static fn (mixed $v): array => is_array($v)
            ? array_map(static fn ($n): int => is_numeric($n) ? (int) $n : 0, $v)
            : [];

        $mesh = new MeshData(
            vertices: $floats($raw['vertices'] ?? []),
            normals: $floats($raw['normals'] ?? []),
            uvs: $floats($raw['uvs'] ?? []),
            indices: $ints($raw['indices'] ?? []),
        );

        return ProjectAssetCache::meshToArray($id, $mesh, 1);
    }
}
