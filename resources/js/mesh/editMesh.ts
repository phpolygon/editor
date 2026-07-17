/**
 * Pure geometry helpers for manual vertex editing, operating on flat MeshData
 * arrays (xyz-interleaved vertices/normals, xy uvs, triangle indices). Kept
 * free of three.js so they can be unit-tested and reused by the viewport's
 * handle layer.
 *
 * A generated mesh usually duplicates a corner into several vertices (one per
 * face, for flat normals), so editing "a vertex" means moving every duplicate
 * at that position together — otherwise the surface tears apart. `weldPositions`
 * groups the duplicates; the editor shows one handle per group.
 */

export interface RawMeshData {
    vertices: number[];
    normals: number[];
    uvs: number[];
    indices: number[];
}

export interface PositionGroup {
    /** Vertex indices (into the vertex list, i.e. vertices[i*3..]) at this position. */
    indices: number[];
    /** The shared position. */
    position: [number, number, number];
}

/** Group coincident vertices by position (welding), for one handle per corner. */
export function weldPositions(vertices: number[], precision = 4): PositionGroup[] {
    const map = new Map<string, number[]>();
    const count = Math.floor(vertices.length / 3);
    for (let i = 0; i < count; i++) {
        const x = vertices[i * 3];
        const y = vertices[i * 3 + 1];
        const z = vertices[i * 3 + 2];
        const key = `${x.toFixed(precision)},${y.toFixed(precision)},${z.toFixed(precision)}`;
        const group = map.get(key);
        if (group) group.push(i);
        else map.set(key, [i]);
    }
    return [...map.values()].map((indices) => ({
        indices,
        position: [
            vertices[indices[0] * 3],
            vertices[indices[0] * 3 + 1],
            vertices[indices[0] * 3 + 2],
        ],
    }));
}

/** Move every vertex in a welded group to a new position (mutates in place). */
export function setGroupPosition(
    vertices: number[],
    indices: number[],
    x: number,
    y: number,
    z: number,
): void {
    for (const i of indices) {
        vertices[i * 3] = x;
        vertices[i * 3 + 1] = y;
        vertices[i * 3 + 2] = z;
    }
}

/** Recompute smooth per-vertex normals from the faces (area-weighted). */
export function computeNormals(vertices: number[], indices: number[]): number[] {
    const normals = new Array<number>(vertices.length).fill(0);

    for (let i = 0; i < indices.length; i += 3) {
        const a = indices[i] * 3;
        const b = indices[i + 1] * 3;
        const c = indices[i + 2] * 3;

        const abx = vertices[b] - vertices[a];
        const aby = vertices[b + 1] - vertices[a + 1];
        const abz = vertices[b + 2] - vertices[a + 2];
        const acx = vertices[c] - vertices[a];
        const acy = vertices[c + 1] - vertices[a + 1];
        const acz = vertices[c + 2] - vertices[a + 2];

        // Cross product (not normalized → area-weighted accumulation).
        const nx = aby * acz - abz * acy;
        const ny = abz * acx - abx * acz;
        const nz = abx * acy - aby * acx;

        for (const v of [a, b, c]) {
            normals[v] += nx;
            normals[v + 1] += ny;
            normals[v + 2] += nz;
        }
    }

    for (let i = 0; i < normals.length; i += 3) {
        const len = Math.hypot(normals[i], normals[i + 1], normals[i + 2]) || 1;
        normals[i] /= len;
        normals[i + 1] /= len;
        normals[i + 2] /= len;
    }

    return normals;
}

/** Flip triangle winding and negate normals (turn the surface inside-out). */
export function flipNormals(mesh: RawMeshData): RawMeshData {
    const indices = mesh.indices.slice();
    for (let i = 0; i < indices.length; i += 3) {
        const tmp = indices[i + 1];
        indices[i + 1] = indices[i + 2];
        indices[i + 2] = tmp;
    }
    return {
        vertices: mesh.vertices.slice(),
        normals: mesh.normals.map((n) => -n),
        uvs: mesh.uvs.slice(),
        indices,
    };
}
