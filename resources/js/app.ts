import { createInertiaApp } from '@inertiajs/vue3';
import { initializeTheme } from '@/composables/useAppearance';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { initializeFlashEtiquetas } from '@/lib/flashEtiquetas';
import { initializeFlashToast } from '@/lib/flashToast';
import { initializeTemaVisual } from '@/lib/temaVisual';

const appName = import.meta.env.VITE_APP_NAME || 'Sistema de Uniformes';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on page load...
initializeTheme();

// This will apply the global visual personalization (brand colors) on page
// load and keep it in sync after each Inertia navigation / save...
initializeTemaVisual();

// This will listen for flash toast data from the server...
initializeFlashToast();

// This will open the QR-labels PDF (if any) flashed by the server, separate
// from the Inertia response that created it...
initializeFlashEtiquetas();
