import { describe, expect, it, beforeEach, vi, type Mock } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';

vi.mock('@/bridge/commands', () => ({
    listPrefabs: vi.fn(),
    spawnPrefab: vi.fn().mockResolvedValue({ spawned: 'Tree', parent: null }),
    // used transitively by the scene store's refreshHierarchy
    getEntityHierarchy: vi.fn().mockResolvedValue({ entities: [] }),
}));

import PrefabBrowserPanel from './PrefabBrowserPanel.vue';
import { listPrefabs, spawnPrefab } from '@/bridge/commands';
import { useSelectionStore } from '@/stores/selection';

const mockList = listPrefabs as unknown as Mock;
const mockSpawn = spawnPrefab as unknown as Mock;

describe('PrefabBrowserPanel', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        mockList.mockReset();
        mockSpawn.mockClear();
    });

    it('lists prefabs after mount', async () => {
        mockList.mockResolvedValue({ prefabs: [{ name: 'Tree', path: 'prefabs/Tree.prefab.json' }] });
        const wrapper = mount(PrefabBrowserPanel);
        await flushPromises();

        expect(wrapper.find('[data-testid="prefab-Tree"]').exists()).toBe(true);
    });

    it('shows an empty state when there are no prefabs', async () => {
        mockList.mockResolvedValue({ prefabs: [] });
        const wrapper = mount(PrefabBrowserPanel);
        await flushPromises();

        expect(wrapper.find('[data-testid="prefab-empty"]').exists()).toBe(true);
    });

    it('spawns the clicked prefab under the current selection', async () => {
        mockList.mockResolvedValue({ prefabs: [{ name: 'Tree', path: 'prefabs/Tree.prefab.json' }] });
        const wrapper = mount(PrefabBrowserPanel);
        await flushPromises();

        // selectedEntity is derived from the selection now, so select properly.
        useSelectionStore().selectEntity('Ground');
        await wrapper.find('[data-testid="prefab-Tree"]').trigger('click');
        await flushPromises();

        expect(mockSpawn).toHaveBeenCalledWith('prefabs/Tree.prefab.json', 'Ground');
    });
});
