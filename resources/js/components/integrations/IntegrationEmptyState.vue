<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Link } from '@inertiajs/vue3';
import { Plug } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
</script>

<template>
    <Card>
        <CardContent class="flex flex-col items-center justify-center gap-4 py-16 text-center">
            <div class="flex h-16 w-16 items-center justify-center rounded-full bg-muted">
                <Plug class="h-8 w-8 text-muted-foreground" />
            </div>
            <div class="space-y-1">
                <h3 class="text-lg font-semibold">
                    {{ t('system.integrations.empty_title') }}
                </h3>
                <p class="max-w-md text-sm text-muted-foreground">
                    {{ t('system.integrations.empty_description') }}
                </p>
            </div>
            <Button as-child>
                <Link href="/system/integrations/create">
                    {{ t('system.integrations.empty_cta') }}
                </Link>
            </Button>
        </CardContent>
    </Card>
</template>
