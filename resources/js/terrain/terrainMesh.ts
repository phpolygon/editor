/**
 * Builds renderable geometry from a heightmap for the editor's preview.
 *
 * This is the client-side twin of the engine's `TerrainMeshBuilder`. Both must
 * produce identical geometry, or the sculpt an artist sees would not be the
 * terrain the game renders — `bake_terrain_mesh` runs the engine builder over
 * the same heightmap so the two can be compared directly.
 *
 * Buffers are typed arrays sized up front. A 257x257 preview is ~66k vertices
 * rebuilt on every brush step, and growing plain arrays there is what turns
 * sculpting from smooth into a slideshow.
 */

import { type Heightmap, heightAt, normalAt, worldX, worldZ } from './heightmap';

export interface TerrainGeometry {
    positions: Float32Array;
    normals: Float32Array;
    uvs: Float32Array;
    /** Uint32 because a 513x513 terrain exceeds the 65k limit of Uint16. */
    indices: Uint32Array;
    vertexCount: number;
    triangleCount: number;
}

/**
 * Build the whole heightmap as one geometry.
 *
 * The editor previews terrain unchunked: chunking exists so the *runtime* can
 * frustum-cull and re-upload incrementally, neither of which helps a single
 * preview mesh that is always fully visible.
 */
export function buildTerrainGeometry(map: Heightmap): TerrainGeometry {
    return buildRegion(map, 0, 0, map.gridWidth - 1, map.gridDepth - 1);
}

/**
 * Build the inclusive grid region [x0..x1] x [z0..z1].
 *
 * Vertices stay in terrain-local space — the region is not re-centred — so a
 * chunk lines up with its neighbours under the terrain's own transform.
 */
export function buildRegion(
    map: Heightmap,
    x0: number,
    z0: number,
    x1: number,
    z1: number,
): TerrainGeometry {
    const cols = x1 - x0 + 1;
    const rows = z1 - z0 + 1;
    const vertexCount = cols * rows;
    const triangleCount = (cols - 1) * (rows - 1) * 2;

    const positions = new Float32Array(vertexCount * 3);
    const normals = new Float32Array(vertexCount * 3);
    const uvs = new Float32Array(vertexCount * 2);
    const indices = new Uint32Array(triangleCount * 3);

    let v = 0;
    let n = 0;
    let t = 0;

    for (let z = z0; z <= z1; z++) {
        const wz = worldZ(map, z);
        // UVs span 0..1 across the whole terrain rather than per region, so
        // splat maps and terrain-wide textures line up across chunk borders.
        const vCoord = z / (map.gridDepth - 1);

        for (let x = x0; x <= x1; x++) {
            positions[v] = worldX(map, x);
            positions[v + 1] = heightAt(map, x, z);
            positions[v + 2] = wz;
            v += 3;

            const [nx, ny, nz] = normalAt(map, x, z);
            normals[n] = nx;
            normals[n + 1] = ny;
            normals[n + 2] = nz;
            n += 3;

            uvs[t] = x / (map.gridWidth - 1);
            uvs[t + 1] = vCoord;
            t += 2;
        }
    }

    let i = 0;
    for (let z = 0; z < rows - 1; z++) {
        for (let x = 0; x < cols - 1; x++) {
            const topLeft = z * cols + x;
            const topRight = topLeft + 1;
            const bottomLeft = topLeft + cols;
            const bottomRight = bottomLeft + 1;

            // Counter-clockwise seen from +Y, matching the engine's winding for
            // upward-facing surfaces.
            indices[i] = topLeft;
            indices[i + 1] = bottomLeft;
            indices[i + 2] = topRight;
            indices[i + 3] = topRight;
            indices[i + 4] = bottomLeft;
            indices[i + 5] = bottomRight;
            i += 6;
        }
    }

    return { positions, normals, uvs, indices, vertexCount, triangleCount };
}

/**
 * Rewrite only the position and normal buffers of an existing geometry.
 *
 * A brush changes heights but never the grid topology, so re-emitting indices
 * and UVs on every stroke is wasted work — and reusing the same buffers lets
 * the viewport flag them for re-upload instead of rebuilding the whole
 * three.js BufferGeometry.
 */
export function updateTerrainGeometry(map: Heightmap, geometry: TerrainGeometry): void {
    const { positions, normals } = geometry;
    let v = 0;

    for (let z = 0; z < map.gridDepth; z++) {
        for (let x = 0; x < map.gridWidth; x++) {
            positions[v + 1] = heightAt(map, x, z);

            const [nx, ny, nz] = normalAt(map, x, z);
            normals[v] = nx;
            normals[v + 1] = ny;
            normals[v + 2] = nz;
            v += 3;
        }
    }
}

/** Whether a geometry still matches the heightmap's grid. */
export function geometryMatches(map: Heightmap, geometry: TerrainGeometry | null): boolean {
    return geometry !== null && geometry.vertexCount === map.gridWidth * map.gridDepth;
}

/**
 * Ray/terrain intersection in terrain-local space, for placing the brush.
 *
 * Marches the ray in grid-sized steps and reports the first step where it drops
 * below the surface, then refines by bisection. Ray-vs-heightfield marching is
 * used rather than three.js raycasting against the mesh because it stays
 * accurate while the geometry is mid-update, and it costs the same regardless
 * of triangle count.
 */
export function raycastTerrain(
    map: Heightmap,
    origin: [number, number, number],
    direction: [number, number, number],
    maxDistance = 10000,
): [number, number, number] | null {
    const stepSize = Math.max(
        0.25,
        Math.min(map.sizeX / (map.gridWidth - 1), map.sizeZ / (map.gridDepth - 1)),
    );

    const heightAtPoint = (distance: number): number => {
        const x = origin[0] + direction[0] * distance;
        const z = origin[2] + direction[2] * distance;
        return sampleWorldHeight(map, x, z);
    };
    const rayY = (distance: number): number => origin[1] + direction[1] * distance;

    // Starting underground has no meaningful surface hit in the view direction.
    if (rayY(0) - heightAtPoint(0) < 0) return null;

    let previous = 0;
    for (let distance = stepSize; distance <= maxDistance; distance += stepSize) {
        const delta = rayY(distance) - heightAtPoint(distance);
        if (delta <= 0) {
            let low = previous;
            let high = distance;
            for (let i = 0; i < 12; i++) {
                const mid = (low + high) / 2;
                if (rayY(mid) - heightAtPoint(mid) > 0) low = mid;
                else high = mid;
            }
            const hit = (low + high) / 2;
            return [
                origin[0] + direction[0] * hit,
                rayY(hit),
                origin[2] + direction[2] * hit,
            ];
        }
        previous = distance;
    }

    return null;
}

/**
 * Bilinear world height, but outside the terrain footprint it returns -Infinity
 * so a ray passing beside the terrain cannot register a false hit against the
 * clamped border height.
 */
function sampleWorldHeight(map: Heightmap, wx: number, wz: number): number {
    const halfX = map.sizeX * 0.5;
    const halfZ = map.sizeZ * 0.5;
    if (wx < -halfX || wx > halfX || wz < -halfZ || wz > halfZ) return -Infinity;

    const gx = ((wx + halfX) / map.sizeX) * (map.gridWidth - 1);
    const gz = ((wz + halfZ) / map.sizeZ) * (map.gridDepth - 1);
    const x0 = Math.floor(gx);
    const z0 = Math.floor(gz);
    const fx = gx - x0;
    const fz = gz - z0;

    const h00 = heightAt(map, x0, z0);
    const h10 = heightAt(map, x0 + 1, z0);
    const h01 = heightAt(map, x0, z0 + 1);
    const h11 = heightAt(map, x0 + 1, z0 + 1);

    return (
        h00 * (1 - fx) * (1 - fz) +
        h10 * fx * (1 - fz) +
        h01 * (1 - fx) * fz +
        h11 * fx * fz
    );
}
