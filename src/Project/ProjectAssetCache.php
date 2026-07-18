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
        $dir = self::dir($projectDir);
        if (! is_dir($dir) && ! @mkdir($dir, 0o777, true) && ! is_dir($dir)) {
            return;
        }

        $materials = [];
        foreach (MaterialRegistry::ids() as $id) {
            $material = MaterialRegistry::get($id);
            if ($material instanceof Material) {
                $materials[$id] = self::materialToArray($id, $material);
            }
        }
        @file_put_contents($dir.DIRECTORY_SEPARATOR.'materials.json', json_encode($materials));

        $meshes = [];
        foreach (MeshRegistry::ids() as $id) {
            $mesh = MeshRegistry::get($id);
            if ($mesh instanceof MeshData) {
                $meshes[$id] = self::meshToArray($id, $mesh, MeshRegistry::version($id));
            }
        }
        @file_put_contents($dir.DIRECTORY_SEPARATOR.'meshes.json', json_encode($meshes));
    }

    /**
     * Write a pre-built mesh/material snapshot into the cache — used when the
     * geometry was produced out-of-process (e.g. a game `expandCommand` that
     * boots the engine and returns a {meshes, materials} bundle), so the shapes
     * already match {@see meshToArray()} / {@see materialToArray()}.
     *
     * @param array<string, mixed> $meshes    id => mesh array
     * @param array<string, mixed> $materials id => material array
     */
    public static function write(string $projectDir, array $meshes, array $materials): void
    {
        $dir = self::dir($projectDir);
        if (! is_dir($dir) && ! @mkdir($dir, 0o777, true) && ! is_dir($dir)) {
            return;
        }

        @file_put_contents($dir.DIRECTORY_SEPARATOR.'meshes.json', json_encode($meshes));
        @file_put_contents($dir.DIRECTORY_SEPARATOR.'materials.json', json_encode($materials));
    }

    /** @return array<string, mixed>|null */
    public static function material(string $projectDir, string $id): ?array
    {
        return self::lookup($projectDir, 'materials.json', $id);
    }

    /** @return array<string, mixed>|null */
    public static function mesh(string $projectDir, string $id): ?array
    {
        return self::lookup($projectDir, 'meshes.json', $id);
    }

    /** @return list<string> */
    public static function materialIds(string $projectDir): array
    {
        return self::keys($projectDir, 'materials.json');
    }

    /** @return list<string> */
    public static function meshIds(string $projectDir): array
    {
        return self::keys($projectDir, 'meshes.json');
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

    /** @return array<string, mixed>|null */
    private static function lookup(string $projectDir, string $file, string $id): ?array
    {
        $data = self::read($projectDir, $file);
        $entry = $data[$id] ?? null;

        return is_array($entry) ? $entry : null;
    }

    /** @return list<string> */
    private static function keys(string $projectDir, string $file): array
    {
        return array_values(array_map('strval', array_keys(self::read($projectDir, $file))));
    }

    /** @return array<string, mixed> */
    private static function read(string $projectDir, string $file): array
    {
        $path = self::dir($projectDir).DIRECTORY_SEPARATOR.$file;
        if (! is_file($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            return [];
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $data : [];
    }
}
