import { describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import Button from './Button.vue';

describe('Button', () => {
    it('renders its slot label', () => {
        const wrapper = mount(Button, { slots: { default: 'Save' } });
        expect(wrapper.text()).toContain('Save');
    });

    it('forwards clicks to a listener', async () => {
        const onClick = vi.fn();
        const wrapper = mount(Button, { slots: { default: 'Go' }, attrs: { onClick } });
        await wrapper.trigger('click');
        expect(onClick).toHaveBeenCalledOnce();
    });

    it('is disabled when the prop is set', () => {
        const wrapper = mount(Button, { props: { disabled: true }, slots: { default: 'X' } });
        expect(wrapper.find('button').attributes('disabled')).toBeDefined();
    });

    it('applies accent styling for the primary variant', () => {
        const wrapper = mount(Button, { props: { variant: 'primary' }, slots: { default: 'X' } });
        expect(wrapper.find('button').classes().join(' ')).toContain('bg-editor-accent');
    });
});
