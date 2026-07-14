import { describe, expect, it } from 'vitest';
import {
    addNode,
    connect,
    createNode,
    disconnect,
    emptyGraph,
    NODE_TYPES,
    removeNode,
    setParam,
    setPosition,
    topoOrder,
    uniqueNodeId,
    validate,
    type ProceduralGraphData,
} from './graph';

function treeGraph(): ProceduralGraphData {
    // trunk (cylinder) + crown (sphere) -> combine
    let g = emptyGraph();
    g = addNode(g, createNode('cylinder', 'trunk'));
    g = addNode(g, createNode('sphere', 'crown'));
    g = addNode(g, createNode('combine', 'tree'));
    g = connect(g, 'tree', 'a', 'trunk');
    g = connect(g, 'tree', 'b', 'crown');
    g = { ...g, output: 'tree' };
    return g;
}

describe('procedural graph model', () => {
    it('creates nodes with catalogue default params', () => {
        const node = createNode('cylinder', 'c1');
        expect(node.params).toEqual({ radius: 0.5, height: 1, segments: 16 });
        expect(NODE_TYPES.cylinder.category).toBe('generator');
    });

    it('makes the first added node the default output', () => {
        const g = addNode(emptyGraph(), createNode('box', 'b'));
        expect(g.output).toBe('b');
    });

    it('generates unique node ids', () => {
        let g = emptyGraph();
        g = addNode(g, createNode('box', uniqueNodeId(g, 'box')));
        const second = uniqueNodeId(g, 'box');
        expect(second).toBe('box_2');
    });

    it('validates a well-formed graph', () => {
        expect(validate(treeGraph())).toEqual({ ok: true, errors: [] });
    });

    it('reports a missing required input', () => {
        let g = addNode(emptyGraph(), createNode('transform', 't'));
        g = { ...g, output: 't' };
        const result = validate(g);
        expect(result.ok).toBe(false);
        expect(result.errors.some((e) => e.includes("missing required input 'mesh'"))).toBe(true);
    });

    it('reports unknown node types and dangling connections', () => {
        let g = emptyGraph();
        g = addNode(g, { id: 'x', type: 'wobble' });
        g = addNode(g, createNode('transform', 't'));
        g = connect(g, 't', 'mesh', 'ghost');
        g = { ...g, output: 't' };
        const errors = validate(g).errors;
        expect(errors.some((e) => e.includes("unknown type 'wobble'"))).toBe(true);
        expect(errors.some((e) => e.includes("missing node 'ghost'"))).toBe(true);
    });

    it('detects cycles', () => {
        let g = emptyGraph();
        g = addNode(g, createNode('transform', 'a'));
        g = addNode(g, createNode('transform', 'b'));
        g = connect(g, 'a', 'mesh', 'b');
        g = connect(g, 'b', 'mesh', 'a');
        g = { ...g, output: 'a' };
        expect(validate(g).errors).toContain('Graph contains a cycle.');
    });

    it('removing a node drops connections into it and fixes the output', () => {
        let g = treeGraph();
        g = removeNode(g, 'crown');
        expect(g.nodes.find((n) => n.id === 'crown')).toBeUndefined();
        // the combine node no longer references crown
        const combine = g.nodes.find((n) => n.id === 'tree');
        expect(Object.values(combine?.inputs ?? {})).not.toContain('crown');
    });

    it('disconnect removes a single slot', () => {
        let g = treeGraph();
        g = disconnect(g, 'tree', 'b');
        const combine = g.nodes.find((n) => n.id === 'tree');
        expect(combine?.inputs).toEqual({ a: 'trunk' });
    });

    it('topoOrder puts dependencies before dependents', () => {
        const order = topoOrder(treeGraph());
        expect(order.indexOf('trunk')).toBeLessThan(order.indexOf('tree'));
        expect(order.indexOf('crown')).toBeLessThan(order.indexOf('tree'));
        expect(order[order.length - 1]).toBe('tree');
    });

    it('setPosition records an editor canvas position', () => {
        let g = addNode(emptyGraph(), createNode('box', 'b'));
        g = setPosition(g, 'b', 120, 40);
        const node = g.nodes.find((n) => n.id === 'b');
        expect(node?.x).toBe(120);
        expect(node?.y).toBe(40);
    });

    it('setParam is immutable and updates the value', () => {
        const g = treeGraph();
        const g2 = setParam(g, 'trunk', 'radius', 0.9);
        expect(g.nodes.find((n) => n.id === 'trunk')?.params?.radius).toBe(0.5);
        expect(g2.nodes.find((n) => n.id === 'trunk')?.params?.radius).toBe(0.9);
    });
});
