import { describe, expect, it } from 'vitest';
import { useDialog, useDialogHost } from './useDialog';

describe('useDialog', () => {
    it('prompt resolves the edited value on accept', async () => {
        const { prompt } = useDialog();
        const { active, accept } = useDialogHost();

        const pending = prompt({ title: 'Name', value: 'initial' });
        expect(active.value?.kind).toBe('prompt');
        expect(active.value?.value).toBe('initial');

        active.value!.value = 'edited';
        accept();

        await expect(pending).resolves.toBe('edited');
        expect(active.value).toBeNull();
    });

    it('prompt resolves null on dismiss', async () => {
        const { prompt } = useDialog();
        const { dismiss } = useDialogHost();

        const pending = prompt({ title: 'Name' });
        dismiss();

        await expect(pending).resolves.toBeNull();
        expect(useDialogHost().active.value).toBeNull();
    });

    it('confirm resolves true on accept and false on dismiss', async () => {
        const { confirm } = useDialog();
        const host = useDialogHost();

        const yes = confirm({ title: 'Delete?', danger: true });
        expect(host.active.value?.danger).toBe(true);
        host.accept();
        await expect(yes).resolves.toBe(true);

        const no = confirm({ title: 'Delete?' });
        host.dismiss();
        await expect(no).resolves.toBe(false);
    });
});
