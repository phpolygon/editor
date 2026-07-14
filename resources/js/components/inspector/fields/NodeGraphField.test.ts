import { describe, expect, it, beforeEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';

vi.mock('@/bridge/commands', () => ({
    updateProperty: vi.fn().mockResolvedValue(undefined),
}));

import NodeGraphField from './NodeGraphField.vue';
import type { GraphNode } from '@/prefab/graph';

function nodes(): GraphNode[] {
    return [
        { id: 'trunk', type: 'cylinder', params: { radius: 0.5, height: 1, segments: 16 }, inputs: {} },
        { id: 'tree', type: 'combine', params: {}, inputs: {} },
    ];
}

function mountField(modelValue: GraphNode[]) {
    return mount(NodeGraphField, {
        props: { label: 'nodes', modelValue, entityName: 'E', componentClass: 'X' },
    });
}

describe('NodeGraphField', () => {
    beforeEach(() => setActivePinia(createPinia()));

    it('renders a node for each graph node', () => {
        const wrapper = mountField(nodes());
        expect(wrapper.find('[data-testid="node-trunk"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="node-tree"]').exists()).toBe(true);
    });

    it('adds a node with default params via the palette', async () => {
        const wrapper = mountField(nodes());
        await wrapper.find('[data-testid="add-node"]').setValue('box');

        const emitted = wrapper.emitted('update:model-value');
        expect(emitted).toBeTruthy();
        const next = emitted![0][0] as GraphNode[];
        expect(next).toHaveLength(3);
        const added = next.find((n) => n.type === 'box');
        expect(added?.params).toEqual({ width: 1, height: 1, depth: 1 });
    });

    it('removes a node', async () => {
        const wrapper = mountField(nodes());
        await wrapper.find('[data-testid="del-trunk"]').trigger('click');

        const next = wrapper.emitted('update:model-value')![0][0] as GraphNode[];
        expect(next.find((n) => n.id === 'trunk')).toBeUndefined();
        expect(next).toHaveLength(1);
    });

    it('edits a param', async () => {
        const wrapper = mountField(nodes());
        await wrapper.find('[data-testid="param-trunk-radius"]').setValue('0.9');

        const next = wrapper.emitted('update:model-value')![0][0] as GraphNode[];
        expect(next.find((n) => n.id === 'trunk')?.params?.radius).toBe(0.9);
    });

    it('connects a source into a target slot with two clicks', async () => {
        const wrapper = mountField(nodes());
        // combine exposes an open variadic slot 'in0'
        await wrapper.find('[data-testid="slot-tree-in0"]').trigger('click');
        expect(wrapper.find('[data-testid="connect-hint"]').exists()).toBe(true);
        await wrapper.find('[data-testid="out-trunk"]').trigger('click');

        const next = wrapper.emitted('update:model-value')![0][0] as GraphNode[];
        expect(next.find((n) => n.id === 'tree')?.inputs).toEqual({ in0: 'trunk' });
    });

    it('shows validation errors for a graph with no output', () => {
        const wrapper = mountField(nodes());
        // no output set → validation should complain
        expect(wrapper.find('[data-testid="validation-errors"]').text()).toContain('output');
    });
});
