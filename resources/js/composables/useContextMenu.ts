import { ref } from 'vue';

export interface ContextMenuItem {
    label: string;
    action: () => void;
    separator?: boolean;
    disabled?: boolean;
}

const x = ref(0);
const y = ref(0);
const visible = ref(false);
const items = ref<ContextMenuItem[]>([]);

export function useContextMenu() {
    function show(event: MouseEvent, menuItems: ContextMenuItem[]) {
        event.preventDefault();
        x.value = event.clientX;
        y.value = event.clientY;
        items.value = menuItems;
        visible.value = true;
    }

    function hide() {
        visible.value = false;
    }

    return { x, y, visible, items, show, hide };
}
