<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Support\Path;
use PHPolygon\Editor\Terrain\TerrainAssetStore;
use PHPolygon\Editor\Terrain\TerrainDocument;
use PHPolygon\Geometry\MeshData;
use PHPolygon\Terrain\TerrainMeshBuilder;
use RuntimeException;

/**
 * Build a terrain's geometry through the engine's own {@see TerrainMeshBuilder}.
 *
 * The terrain workspace builds its preview mesh client-side so sculpting stays
 * interactive, which means there are two implementations of the same geometry.
 * This command is the authoritative one: it is what proves the preview matches
 * what the engine will render, and it is how a terrain is exported as a plain
 * mesh asset for a project that does not want the Terrain component.
 *
 * Source is either a saved asset (`name`) or an inline document, so the editor
 * can bake unsaved work.
 *
 * With `save` set, the geometry is also written to `assets/meshes/` as a raw
 * mesh asset. Chunked bakes write one asset per chunk, since a single mesh for
 * a large terrain is exactly what chunking exists to avoid.
 */
class BakeTerrainMeshCommand implements CommandInterface
{
    private const MESHES_SUBDIR = 'meshes';

    /** @param array<string, mixed> $args */
    public function __construct(private readonly array $args = []) {}

    /** @return array<string, mixed> */
    public function execute(EditorContext $context): array
    {
        $document = $this->resolveDocument($context);
        $heightmap = $document->toHeightmap();
        $builder = new TerrainMeshBuilder;

        $chunked = is_bool($this->args['chunked'] ?? null) ? $this->args['chunked'] : false;
        $save = is_bool($this->args['save'] ?? null) ? $this->args['save'] : false;
        $meshName = is_string($this->args['meshName'] ?? null) && $this->args['meshName'] !== ''
            ? $this->args['meshName']
            : $document->name;

        $meshes = [];
        if ($chunked) {
            foreach ($builder->buildChunks($heightmap, $document->chunkSize) as $chunk) {
                $meshes[] = [
                    'name' => $chunk->meshId($meshName),
                    'chunkX' => $chunk->chunkX,
                    'chunkZ' => $chunk->chunkZ,
                    'mesh' => $chunk->mesh,
                ];
            }
        } else {
            $meshes[] = [
                'name' => $meshName,
                'chunkX' => 0,
                'chunkZ' => 0,
                'mesh' => $builder->buildSingle($heightmap),
            ];
        }

        $result = [];
        foreach ($meshes as $entry) {
            /** @var MeshData $mesh */
            $mesh = $entry['mesh'];

            $written = null;
            if ($save) {
                $written = $this->writeMeshAsset($context, (string) $entry['name'], $mesh);
            }

            $result[] = [
                'name' => $entry['name'],
                'chunkX' => $entry['chunkX'],
                'chunkZ' => $entry['chunkZ'],
                'vertices' => $mesh->vertices,
                'normals' => $mesh->normals,
                'uvs' => $mesh->uvs,
                'indices' => $mesh->indices,
                'vertexCount' => $mesh->vertexCount(),
                'triangleCount' => $mesh->triangleCount(),
                'relativePath' => $written,
            ];
        }

        return [
            'terrain' => $document->name,
            'chunked' => $chunked,
            'saved' => $save,
            'meshes' => $result,
        ];
    }

    private function resolveDocument(EditorContext $context): TerrainDocument
    {
        $name = is_string($this->args['name'] ?? null) ? $this->args['name'] : '';

        if (is_array($this->args['terrain'] ?? null)) {
            return TerrainDocument::fromArray($this->args['terrain'], $name !== '' ? $name : 'terrain');
        }

        if ($name === '') {
            throw new RuntimeException("Missing 'name' or 'terrain' argument");
        }

        $store = new TerrainAssetStore($context);

        return $store->load($store->sanitizeName($name));
    }

    /** @return string Project-relative path of the written mesh asset. */
    private function writeMeshAsset(EditorContext $context, string $name, MeshData $mesh): string
    {
        $sanitized = preg_replace('/[^A-Za-z0-9_\-]/', '_', $name) ?? '';
        if ($sanitized === '') {
            throw new RuntimeException("Invalid mesh name: {$name}");
        }

        $dir = Path::join($context->getAssetsDir(), self::MESHES_SUBDIR);
        if (! is_dir($dir) && ! mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            throw new RuntimeException("Failed to create meshes directory: {$dir}");
        }

        // Same shape SaveMeshCommand writes for baked geometry, so a terrain
        // export opens in the mesh editor like any other raw mesh.
        $payload = [
            'name' => $sanitized,
            'raw' => [
                'vertices' => $mesh->vertices,
                'normals' => $mesh->normals,
                'uvs' => $mesh->uvs,
                'indices' => $mesh->indices,
            ],
        ];

        $file = Path::join($dir, $sanitized.'.mesh.json');
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents($file, $json) === false) {
            throw new RuntimeException("Failed to write mesh file: {$file}");
        }

        return self::MESHES_SUBDIR.'/'.$sanitized.'.mesh.json';
    }
}
