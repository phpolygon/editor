/**
 * Moving a terrain between a scene entity and the terrain workspace.
 *
 * A placed terrain keeps its heightmap *inside* the Terrain component (the
 * scene document is self-contained — see `CreateTerrainEntityCommand`), while
 * the workspace edits a `.terrain.json` asset that additionally holds the paint
 * layers and splat map. Neither is a superset of the other, so opening an
 * entity's terrain means reading the component and topping it up from the asset
 * it was placed from, and applying means writing the component's fields back.
 *
 * This module is the pure translation between the two shapes; the store owns
 * the loading, merging and the round trip.
 */

import type { TerrainPayload, TerrainScatterPayload } from '@/bridge/commands';
import type { ComponentData } from '@/types';

function numberOr(value: unknown, fallback: number): number {
    return typeof value === 'number' && Number.isFinite(value) ? value : fallback;
}

function str(value: unknown): string {
    return typeof value === 'string' ? value : '';
}

/**
 * Read a Terrain component into a payload the workspace can load.
 *
 * Layers and splat have no home on the component (they are baked into a texture
 * before they reach the game), so they come out empty here and are merged in
 * from the asset by the caller when the grids match.
 */
export function payloadFromComponent(
    component: ComponentData,
    name: string,
    scatter: TerrainScatterPayload[] = [],
): TerrainPayload {
    const props = component.properties;

    return {
        name,
        gridWidth: Math.max(2, Math.round(numberOr(props.gridWidth, 129))),
        gridDepth: Math.max(2, Math.round(numberOr(props.gridDepth, 129))),
        sizeX: numberOr(props.sizeX, 256),
        sizeZ: numberOr(props.sizeZ, 256),
        minHeight: numberOr(props.minHeight, 0),
        maxHeight: numberOr(props.maxHeight, 50),
        heights: str(props.heights),
        chunkSize: Math.max(1, Math.round(numberOr(props.chunkSize, 32))),
        materialId: str(props.materialId),
        layers: [],
        splat: '',
        scatter,
    };
}

/** The Terrain component properties to write back, keyed by property name. */
export function terrainProperties(payload: TerrainPayload): Record<string, unknown> {
    return {
        gridWidth: payload.gridWidth,
        gridDepth: payload.gridDepth,
        sizeX: payload.sizeX,
        sizeZ: payload.sizeZ,
        minHeight: payload.minHeight,
        maxHeight: payload.maxHeight,
        heights: payload.heights,
        chunkSize: payload.chunkSize,
        materialId: payload.materialId,
        meshIdPrefix: payload.name,
    };
}

/**
 * The sibling HeightmapCollider3D's bounds for the same terrain.
 *
 * Its sample data is repopulated by the Terrain component at runtime, but the
 * grid size and world extent are serialised — so a resized or rescaled terrain
 * leaves the collider describing the old shape unless these travel with it.
 */
export function colliderProperties(payload: TerrainPayload): Record<string, unknown> {
    return {
        gridWidth: payload.gridWidth,
        gridDepth: payload.gridDepth,
        worldMinX: -payload.sizeX / 2,
        worldMaxX: payload.sizeX / 2,
        worldMinZ: -payload.sizeZ / 2,
        worldMaxZ: payload.sizeZ / 2,
    };
}

/**
 * Whether an asset's paint data can be reused for a component's heightmap.
 *
 * Splat coverage and scatter density are stored per grid sample, so they only
 * mean anything at the resolution they were painted at.
 */
export function gridsMatch(a: { gridWidth: number; gridDepth: number }, b: { gridWidth: number; gridDepth: number }): boolean {
    return a.gridWidth === b.gridWidth && a.gridDepth === b.gridDepth;
}
