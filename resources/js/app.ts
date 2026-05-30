import { createApp, h } from 'vue';
import { createPinia } from 'pinia';
import { createRouter, createWebHashHistory, RouterView } from 'vue-router';
import EditorLayout from '@/components/layout/EditorLayout.vue';

const router = createRouter({
    history: createWebHashHistory(),
    routes: [
        { path: '/', component: EditorLayout },
    ],
});

// Render function (not a `template: '...'` string) — the production Vue
// build ships without the runtime template compiler, so inline template
// strings silently render nothing.
const app = createApp({
    render: () => h(RouterView),
});

app.use(createPinia());
app.use(router);
app.mount('#app');
