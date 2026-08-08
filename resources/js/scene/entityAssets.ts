/**
 * Which authoring workspace can take over a given scene entity, and with what.
 *
 * The inspector offers a jump into the mesh / material / shader / terrain
 * workspace for the selected entity; each of those needs to know where the
 * entity keeps that piece of content, so the workspace can open the real thing
 * and write the result back. Resolving that is pure data work, kept here so the
 * stores and the inspector agree on the rules.
 */

import type { ComponentData, EntityNode } from '@/types';
import type { GraphNode } from '@/prefab/graph';

/** Suffix match on the namespaced class, as the viewport sync does. */
export function findComponent(entity: EntityNode, suffix: string): ComponentData | undefined {
    return entity.components.find((c) => c._class.endsWith('\\' + suffix) || c._class === suffix);
}

function str(value: unknown): string {
    return typeof value === 'string' ? value : '';
}

// ── Mesh ────────────────────────────────────────────────────────────────────

/**
 * Where a mesh opened in the mesh editor came from, so edits can be written
 * back to the entity that owns it.
 *
 * `graph` — a ProceduralMesh component: the entity stores the node graph
 * itself, so applying means writing `nodes` + `output` back.
 * `asset`  — a MeshRenderer component: the entity only references geometry by
 * id, so applying means baking the mesh to an asset and setting `meshId`.
 */
export interface EntityMeshLink {
    entity: string;
    /** Fully qualified class of the component holding the mesh. */
    componentClass: string;
    kind: 'graph' | 'asset';
}

/** A link plus the mesh data currently on the entity. */
export interface EntityMeshTarget extends EntityMeshLink {
    nodes: GraphNode[];
    output: string;
    meshId: string;
}

/**
 * Work out which component of an entity owns its geometry, or null when the
 * entity has none that the mesh editor can edit.
 *
 * A ProceduralMesh wins over a MeshRenderer: when both are present the graph
 * generates the geometry and the renderer only supplies material/visibility —
 * the same precedence the viewport applies.
 */
export function resolveEntityMesh(entity: EntityNode | null | undefined): EntityMeshTarget | null {
    if (!entity) return null;

    // A terrain builds its geometry from its heightmap and is sculpted in the
    // Terrain workspace; its MeshRenderer only carries the material.
    if (findComponent(entity, 'Terrain')) return null;

    const procedural = findComponent(entity, 'ProceduralMesh');
    if (procedural) {
        const nodes = procedural.properties.nodes;
        return {
            entity: entity.name,
            componentClass: procedural._class,
            kind: 'graph',
            nodes: Array.isArray(nodes) ? (nodes as GraphNode[]) : [],
            output: str(procedural.properties.output),
            meshId: str(procedural.properties.meshId),
        };
    }

    const renderer = findComponent(entity, 'MeshRenderer');
    if (renderer) {
        return {
            entity: entity.name,
            componentClass: renderer._class,
            kind: 'asset',
            nodes: [],
            output: '',
            meshId: str(renderer.properties.meshId),
        };
    }

    return null;
}

// ── Material ────────────────────────────────────────────────────────────────

export interface EntityMaterialLink {
    entity: string;
    /** The component carrying the `materialId` property. */
    componentClass: string;
}

export interface EntityMaterialTarget extends EntityMaterialLink {
    materialId: string;
}

/**
 * The component whose `materialId` the material workspace should edit.
 *
 * Several component types reference a material (MeshRenderer, Terrain, …), so
 * this goes by the property rather than by class, preferring one that actually
 * has a material assigned over one still left blank.
 */
function findMaterialHolder(entity: EntityNode): ComponentData | null {
    const holders = entity.components.filter((c) => 'materialId' in c.properties);
    if (holders.length === 0) return null;
    return holders.find((c) => str(c.properties.materialId) !== '') ?? holders[0];
}

export function resolveEntityMaterial(entity: EntityNode | null | undefined): EntityMaterialTarget | null {
    if (!entity) return null;

    const holder = findMaterialHolder(entity);
    if (!holder) return null;

    return {
        entity: entity.name,
        componentClass: holder._class,
        materialId: str(holder.properties.materialId),
    };
}

// ── Shader ──────────────────────────────────────────────────────────────────

/**
 * An entity never references a shader directly — its material does, via the
 * material's `shader` field. Editing "this entity's shader" therefore means
 * editing the shader of the material the entity uses, and applying writes the
 * shader name back into that material.
 */
export interface EntityShaderLink {
    entity: string;
    materialId: string;
}

export type EntityShaderTarget = EntityShaderLink;

export function resolveEntityShader(entity: EntityNode | null | undefined): EntityShaderTarget | null {
    const material = resolveEntityMaterial(entity);
    if (!material || material.materialId === '') return null;

    return { entity: material.entity, materialId: material.materialId };
}

// ── Terrain ─────────────────────────────────────────────────────────────────

export interface EntityTerrainLink {
    entity: string;
    componentClass: string;
    /** Sibling components kept in sync when the terrain is written back. */
    colliderComponentClass: string | null;
    scatterComponentClass: string | null;
}

export interface EntityTerrainTarget extends EntityTerrainLink {
    /** The Terrain component itself — the authoritative heightmap for the scene. */
    component: ComponentData;
    /** The terrain asset this entity was placed from (`meshIdPrefix`), if any. */
    assetName: string;
    /** The scatter sets currently on the entity, if it carries a TerrainScatter. */
    scatterSets: unknown[];
}

export function resolveEntityTerrain(entity: EntityNode | null | undefined): EntityTerrainTarget | null {
    if (!entity) return null;

    const terrain = findComponent(entity, 'Terrain');
    if (!terrain) return null;

    const scatter = findComponent(entity, 'TerrainScatter');
    const sets = scatter?.properties.sets;

    return {
        entity: entity.name,
        componentClass: terrain._class,
        colliderComponentClass: findComponent(entity, 'HeightmapCollider3D')?._class ?? null,
        scatterComponentClass: scatter?._class ?? null,
        component: terrain,
        assetName: str(terrain.properties.meshIdPrefix),
        scatterSets: Array.isArray(sets) ? sets : [],
    };
}
