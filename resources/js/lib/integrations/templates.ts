import { Github } from 'lucide-vue-next';
import type { Component } from 'vue';

/**
 * Display metadata for the "Conexiones" template picker. The actual
 * preset (form defaults, auth endpoints, headers) lives server-side in
 * `IntegrationController::resolveIntegrationTemplate()` — that helper is
 * the source of truth, and what the Create form reads via the `template`
 * prop. Keep this list in sync with the backend map when adding entries.
 */
export interface IntegrationTemplate {
    slug: string;
    label: string;
    descriptionKey: string;
    icon: Component;
    tint: string;
}

export const INTEGRATION_TEMPLATES: IntegrationTemplate[] = [
    {
        slug: 'github',
        label: 'GitHub',
        descriptionKey: 'system.integrations.templates.github.description',
        icon: Github,
        tint: 'var(--sp-text-primary)',
    },
];
