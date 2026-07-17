import { describe, expect, it } from 'vitest';
import {
    emptyMatGraph,
    addMatNode,
    createMatNode,
    connectMat,
    removeMatNode,
    matNodeById,
    evalMatGraph,
    type MatGraph,
} from './matGraph';

function withColor(g: MatGraph, id: string, r: number, gr: number, b: number): MatGraph {
    return addMatNode(g, { ...createMatNode('colorConst', id), params: { out: { r, g: gr, b, a: 1 } } });
}

describe('matGraph structure', () => {
    it('starts with just the material sink', () => {
        const g = emptyMatGraph();
        expect(g.nodes).toHaveLength(1);
        expect(g.nodes[0].type).toBe('material');
    });

    it('never removes the material sink', () => {
        const g = removeMatNode(emptyMatGraph(), 'material');
        expect(matNodeById(g, 'material')).toBeDefined();
    });

    it('rejects a type-mismatched connection (float → color port)', () => {
        let g = addMatNode(emptyMatGraph(), createMatNode('floatConst', 'f'));
        const before = g.connections.length;
        g = connectMat(g, { node: 'f', port: 'out' }, { node: 'material', port: 'albedo' });
        expect(g.connections.length).toBe(before);
    });

    it('rejects a cycle', () => {
        let g = emptyMatGraph();
        g = addMatNode(g, createMatNode('multiply', 'm1'));
        g = addMatNode(g, createMatNode('multiply', 'm2'));
        g = connectMat(g, { node: 'm1', port: 'out' }, { node: 'm2', port: 'a' });
        const before = g.connections.length;
        g = connectMat(g, { node: 'm2', port: 'out' }, { node: 'm1', port: 'a' });
        expect(g.connections.length).toBe(before);
    });
});

describe('matGraph evaluation', () => {
    it('routes a color constant into albedo', () => {
        let g = withColor(emptyMatGraph(), 'col', 1, 0, 0);
        g = connectMat(g, { node: 'col', port: 'out' }, { node: 'material', port: 'albedo' });

        const mat = evalMatGraph(g, 'test');
        expect(mat.albedo.r).toBe(1);
        expect(mat.albedo.g).toBe(0);
    });

    it('mixes two colors by a factor into albedo', () => {
        let g = withColor(emptyMatGraph(), 'a', 0, 0, 0);
        g = withColor(g, 'b', 1, 1, 1);
        g = addMatNode(g, { ...createMatNode('floatConst', 't'), params: { out: 0.25 } });
        g = addMatNode(g, createMatNode('mix', 'm'));
        g = connectMat(g, { node: 'a', port: 'out' }, { node: 'm', port: 'a' });
        g = connectMat(g, { node: 'b', port: 'out' }, { node: 'm', port: 'b' });
        g = connectMat(g, { node: 't', port: 'out' }, { node: 'm', port: 't' });
        g = connectMat(g, { node: 'm', port: 'out' }, { node: 'material', port: 'albedo' });

        const mat = evalMatGraph(g, 'x');
        expect(mat.albedo.r).toBeCloseTo(0.25);
        expect(mat.albedo.g).toBeCloseTo(0.25);
    });

    it('falls back to defaults for unconnected inputs', () => {
        const mat = evalMatGraph(emptyMatGraph(), 'x');
        expect(mat.roughness).toBe(0.5);
        expect(mat.metallic).toBe(0);
    });

    it('routes a float constant into roughness', () => {
        let g = addMatNode(emptyMatGraph(), { ...createMatNode('floatConst', 'r'), params: { out: 0.9 } });
        g = connectMat(g, { node: 'r', port: 'out' }, { node: 'material', port: 'roughness' });
        expect(evalMatGraph(g, 'x').roughness).toBe(0.9);
    });
});
