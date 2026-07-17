import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import SegmentedControl from './SegmentedControl.vue';

const options = [
    { value: 'a', label: 'A' },
    { value: 'b', label: 'B' },
];

describe('SegmentedControl', () => {
    it('renders one button per option and marks the active one pressed', () => {
        const wrapper = mount(SegmentedControl, { props: { modelValue: 'a', options } });
        const buttons = wrapper.findAll('button');
        expect(buttons).toHaveLength(2);
        expect(buttons[0].attributes('aria-pressed')).toBe('true');
        expect(buttons[1].attributes('aria-pressed')).toBe('false');
    });

    it('emits update:modelValue with the clicked option value', async () => {
        const wrapper = mount(SegmentedControl, { props: { modelValue: 'a', options } });
        await wrapper.findAll('button')[1].trigger('click');
        expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['b']);
    });
});
