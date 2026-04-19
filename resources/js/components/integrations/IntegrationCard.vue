<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Link } from '@inertiajs/vue3';
import { CheckCircle2, MoreVertical, Plug, XCircle } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

interface Integration {
    id: string;
    name: string;
    slug: string;
    base_url: string;
    auth_type: string;
    visibility: string;
    status: string;
    last_tested_at: string | null;
    last_test_status: string | null;
    request_count: number;
}

const props = defineProps<{ integration: Integration }>();

defineEmits<{
    duplicate: [id: string];
    delete: [id: string];
}>();

const { t } = useI18n();
</script>

<template>
    <Card class="transition hover:shadow-md">
        <CardContent class="pt-6">
            <div class="flex items-start justify-between gap-3">
                <Link
                    :href="`/system/integrations/${integration.id}`"
                    class="flex min-w-0 flex-1 items-start gap-3"
                >
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary">
                        <Plug class="h-5 w-5" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <p class="truncate text-sm font-semibold">
                                {{ integration.name }}
                            </p>
                            <CheckCircle2
                                v-if="integration.last_test_status === 'success'"
                                class="h-4 w-4 text-emerald-500"
                            />
                            <XCircle
                                v-else-if="integration.last_test_status === 'failure'"
                                class="h-4 w-4 text-red-500"
                            />
                        </div>
                        <p class="truncate text-xs text-muted-foreground">
                            {{ integration.base_url }}
                        </p>
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            <Badge variant="secondary" class="text-xs">
                                {{ integration.auth_type }}
                            </Badge>
                            <Badge
                                v-if="integration.visibility !== 'private'"
                                variant="outline"
                                class="text-xs"
                            >
                                {{ integration.visibility }}
                            </Badge>
                            <Badge variant="outline" class="text-xs">
                                {{ t('system.integrations.requests_count', { count: integration.request_count }) }}
                            </Badge>
                        </div>
                    </div>
                </Link>

                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <Button variant="ghost" size="icon">
                            <MoreVertical class="h-4 w-4" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem @select="$emit('duplicate', integration.id)">
                            {{ t('system.integrations.duplicate') }}
                        </DropdownMenuItem>
                        <DropdownMenuItem @select="$emit('delete', integration.id)">
                            {{ t('common.delete') }}
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </CardContent>
    </Card>
</template>
