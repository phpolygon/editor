/**
 * Sculpting a terrain that lives in the scene, in world context.
 *
 * The terrain workspace is for authoring a terrain asset on its own; this is
 * for the other half of the job — adjusting terrain against the buildings,
 * paths and props already placed on it, which is impossible to judge in
 * isolation.
 *
 * The two share their brush settings (from the terrain editor store) so a
 * radius set in one applies in the other, but they operate on different data:
 * the workspace edits the `.terrain.json` asset, while this edits the `heights`
 * property of a Terrain component on a scene entity. Edits here go through
 * `update_property`, so they land in the scene document's undo history exactly
 * like any other inspector change.
 */

import { heightmapFromEncoded, encodeHeights, type Heightmap } from './heightmap';
import type { ComponentData, EntityNode } from '@/types';

export const TERRAIN_COMPONENT = 'PHPolygon\\Component\\Terrain';

/** The Terrain component on an entity, if it has one. */
export function findTerrainComponent(entity: EntityNode | null | undefined): ComponentData | null {
    if (!entity) return null;
    return (
        entity.components.find(
            (c) => c._class === TERRAIN_COMPONENT || c._class.endsWith('\\Terrain'),
        ) ?? null
    );
}

function numberOr(value: unknown, fallback: number): number {
    return typeof value === 'number' && Number.isFinite(value) ? value : fallback;
}

/** Decode a Terrain component's heightmap into an editable heightmap. */
export function heightmapFromComponent(comp: ComponentData): Heightmap {
    const props = comp.properties;

    return heightmapFromEncoded(
        {
            gridWidth: Math.max(2, Math.round(numberOr(props.gridWidth, 129))),
            gridDepth: Math.max(2, Math.round(numberOr(props.gridDepth, 129))),
            sizeX: numberOr(props.sizeX, 256),
            sizeZ: numberOr(props.sizeZ, 256),
            minHeight: numberOr(props.minHeight, 0),
            maxHeight: numberOr(props.maxHeight, 50),
        },
        typeof props.heights === 'string' ? props.heights : '',
    );
}

/** The payload to write back into the component's `heights` property. */
export function heightsPropertyValue(heightmap: Heightmap): string {
    return encodeHeights(heightmap.samples);
}

/**
 * Convert a world-space point into the terrain's local space.
 *
 * The terrain grid is centred on its entity's origin, so a terrain placed away
 * from the world origin needs the entity's translation removed before the
 * heightmap can be indexed. Rotation and scale are deliberately not handled:
 * sculpting a rotated terrain would need the inverse transform on every brush
 * sample, and a rotated heightfield is not a case the runtime supports either.
 */
export function worldToTerrainLocal(
    world: [number, number, number],
    entityPosition: [number, number, number],
): [number, number] {
    return [world[0] - entityPosition[0], world[2] - entityPosition[2]];
}

/** Whether a terrain can be sculpted in place, with the reason when it cannot. */
export function sculptability(
    entity: EntityNode | null | undefined,
): { ok: true; component: ComponentData } | { ok: false; reason: string } {
    const component = findTerrainComponent(entity);
    if (!component) {
        return { ok: false, reason: 'Select an entity with a Terrain component to sculpt it.' };
    }

    const transform = entity?.components.find((c) => c._class.endsWith('\\Transform3D'));
    if (transform) {
        const rotation = transform.properties.rotation as
            | { x?: number; y?: number; z?: number; w?: number }
            | undefined;
        const isIdentity =
            !rotation ||
            (Math.abs(rotation.x ?? 0) < 1e-6 &&
                Math.abs(rotation.y ?? 0) < 1e-6 &&
                Math.abs(rotation.z ?? 0) < 1e-6 &&
                Math.abs(Math.abs(rotation.w ?? 1) - 1) < 1e-6);

        if (!isIdentity) {
            return {
                ok: false,
                reason: 'This terrain is rotated. Sculpt it in the Terrain workspace instead.',
            };
        }
    }

    return { ok: true, component };
}
