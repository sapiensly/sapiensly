import { ref, watch } from 'vue';

/**
 * Shared collapse state for the runtime left sidebar. A module-level singleton so
 * the rail (SiteSidebar) and the toggle (in the content panel's title bar) read
 * and flip the SAME state without prop threading. Persisted per browser.
 */
const STORAGE_KEY = 'sp-sidebar-collapsed';
const collapsed = ref(false);
let initialized = false;

export function useSidebarCollapsed() {
    if (!initialized) {
        initialized = true;
        try {
            collapsed.value = localStorage.getItem(STORAGE_KEY) === '1';
        } catch {
            /* ignore (SSR / blocked storage) */
        }
        watch(collapsed, (v) => {
            try {
                localStorage.setItem(STORAGE_KEY, v ? '1' : '0');
            } catch {
                /* ignore */
            }
        });
    }

    return collapsed;
}
