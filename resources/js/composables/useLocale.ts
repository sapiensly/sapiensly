import { usePage } from '@inertiajs/vue3';
import { watch } from 'vue';
import { useI18n } from 'vue-i18n';

export function useLocaleSync() {
    const page = usePage();
    const { locale } = useI18n();

    watch(
        () => page.props.locale as string,
        (newLocale) => {
            if (newLocale && newLocale !== locale.value) {
                locale.value = newLocale;
            }
        },
        { immediate: true },
    );
}
