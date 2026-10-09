import './bootstrap';
import './flatpickr';
import './CodeHighlighter';
import './chart';

import { mount } from 'svelte';
import App from './App.svelte';

function initApp() {
    const appElement = document.getElementById('app');
    if (appElement && !appElement.dataset.mounted) {
        appElement.dataset.mounted = 'true';
        appElement.innerHTML = '';
        mount(App, {
            target: appElement,
        });
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initApp);
} else {
    initApp();
}
