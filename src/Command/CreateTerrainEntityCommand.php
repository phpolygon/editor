<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Component\HeightmapCollider3D;
use PHPolygon\Component\Terrain;
use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\SceneDocument;
use PHPolygon\Editor\Terrain\TerrainAssetStore;
use PHPolygon\Editor\Terrain\TerrainScatter as TerrainScatterAsset;
use RuntimeException;

/**
 * Place a saved terrain asset into the active scene as an entity carrying a
 * {@see Terrain} component.
 *
 * The heightmap is *copied* into the component rather than referenced by asset
 * path. That is what lets the engine stay free of a runtime terrain file
 * format — the scene document is self-contained — at the cost of the scene
 * holding a second copy of the payload. Re-running this command after further
 * sculpting re-syncs the entity.
 *
 * A {@see HeightmapCollider3D} is attached alongside by
 * default, because the collider's own height data is never serialised: the
 * Terrain component repopulates it on every rebuild, which is also what
 * restores it after a scene load.
 */
class CreateTerrainEntityCommand implements CommandInterface
{
    private const TERRAIN_CLASS = 'PHPolygon\\Component\\Terrain';

    private const SCATTER_CLASS = 'PHPolygon\\Component\\TerrainScatter';

    private const COLLIDER_CLASS = 'PHPolygon\\Component\\HeightmapCollider3D';

    private const TRANSFORM_CLASS = 'PHPolygon\\Component\\Transform3D';

    /** @param array<string, mixed> $args */
    public function __construct(private readonly array $args = []) {}

    /** @return array<string, mixed> */
    public function execute(EditorContext $context): array
    {
        $doc = $context->getActiveDocument();
        if ($doc === null) {
            throw new RuntimeException('No active scene document');
        }

        $terrainName = is_string($this->args['name'] ?? null) ? $this->args['name'] : '';
        if ($terrainName === '') {
            throw new RuntimeException("Missing 'name' argument");
        }

        $store = new TerrainAssetStore($context);
        $document = $store->load($store->sanitizeName($terrainName));

        $withCollider = is_bool($this->args['collider'] ?? null) ? $this->args['collider'] : true;
        $parent = is_string($this->args['parent'] ?? null) ? $this->args['parent'] : null;
        $entityName = is_string($this->args['entityName'] ?? null) && $this->args['entityName'] !== ''
            ? $this->args['entityName']
            : $document->name;

        $existing = $doc->getEntity($entityName);
        $name = $existing !== null && ($this->args['replace'] ?? false) === true
            ? $entityName
            : $this->uniqueName($doc, $entityName);

        if ($doc->getEntity($name) === null) {
            $doc->addEntity($name, $parent);
            $doc->addComponent($name, self::TRANSFORM_CLASS, [
                'position' => ['x' => 0.0, 'y' => 0.0, 'z' => 0.0],
                'rotation' => ['x' => 0.0, 'y' => 0.0, 'z' => 0.0, 'w' => 1.0],
                'scale' => ['x' => 1.0, 'y' => 1.0, 'z' => 1.0],
            ]);
        }

        $doc->addComponent($name, self::TERRAIN_CLASS, [
            'gridWidth' => $document->gridWidth,
            'gridDepth' => $document->gridDepth,
            'sizeX' => $document->sizeX,
            'sizeZ' => $document->sizeZ,
            'minHeight' => $document->minHeight,
            'maxHeight' => $document->maxHeight,
            'heights' => $document->heights,
            'chunkSize' => $document->chunkSize,
            'meshIdPrefix' => $document->name,
            'materialId' => $document->materialId,
            'castShadows' => true,
            'generateCollider' => $withCollider,
        ]);

        if ($withCollider) {
            $doc->addComponent($name, self::COLLIDER_CLASS, [
                'gridWidth' => $document->gridWidth,
                'gridDepth' => $document->gridDepth,
                'worldMinX' => -$document->sizeX / 2.0,
                'worldMaxX' => $document->sizeX / 2.0,
                'worldMinZ' => -$document->sizeZ / 2.0,
                'worldMaxZ' => $document->sizeZ / 2.0,
            ]);
        }

        // Scatter sets ride along on the same entity: TerrainScatter reads the
        // sibling Terrain's heightmap and regenerates its instances from these
        // density maps and rules. The set shape is copied verbatim, so the
        // editor's authoring format and the component's are the same thing.
        $scatterSets = array_map(
            static fn (TerrainScatterAsset $set): array => $set->toArray(),
            $document->scatter,
        );

        if ($scatterSets !== []) {
            $doc->addComponent($name, self::SCATTER_CLASS, ['sets' => $scatterSets]);
        }

        return [
            'created' => $name,
            'terrain' => $document->name,
            'parent' => $parent,
            'collider' => $withCollider,
            'scatterSets' => count($scatterSets),
        ];
    }

    private function uniqueName(SceneDocument $doc, string $base): string
    {
        if ($doc->getEntity($base) === null) {
            return $base;
        }
        $i = 2;
        while ($doc->getEntity($base.'_'.$i) !== null) {
            $i++;
        }

        return $base.'_'.$i;
    }
}
