import { createI18n } from 'vue-i18n';
import en from './locales/en.json';
import es from './locales/es.json';

export function createI18nInstance(locale: string = 'en') {
    return createI18n({
        legacy: false,
        locale,
        fallbackLocale: 'en',
        messages: {
            en,
            es,
        },
    });
}
