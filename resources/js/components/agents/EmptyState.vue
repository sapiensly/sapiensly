<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import * as AgentTeamController from '@/actions/App/Http/Controllers/AgentTeamController';
import { Link } from '@inertiajs/vue3';
import { Bot, Plus } from 'lucide-vue-next';
</script>

<template>
    <Card class="border-dashed">
        <CardContent
            class="flex flex-col items-center justify-center py-16 text-center"
        >
            <Bot class="mb-4 h-12 w-12 text-muted-foreground" />
            <h3 class="mb-2 text-lg font-medium">No agent teams yet</h3>
            <p class="mb-6 max-w-sm text-sm text-muted-foreground">
                Create your first agent team to get started with AI-powered
                customer service automation.
            </p>
            <Button as-child>
                <Link :href="AgentTeamController.create()">
                    <Plus class="mr-2 h-4 w-4" />
                    Create your first team
                </Link>
            </Button>
        </CardContent>
    </Card>
</template>
