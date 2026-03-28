<script setup lang="ts">
import * as ChatbotController from '@/actions/App/Http/Controllers/ChatbotController';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import type {
    AnalyticsOverview,
    DailyData,
    RatingDistribution,
    ResponseTimeDistribution,
    TopTopic,
} from '@/types/chatbot';
import { Head, router } from '@inertiajs/vue3';
import {
    ArcElement,
    BarElement,
    CategoryScale,
    Chart as ChartJS,
    Filler,
    Legend,
    LinearScale,
    LineElement,
    PointElement,
    Title,
    Tooltip,
} from 'chart.js';
import {
    ArrowDown,
    ArrowUp,
    Clock,
    MessageCircle,
    MessageSquare,
    Star,
    Target,
    TrendingUp,
    Users,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { Bar, Doughnut, Line } from 'vue-chartjs';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

// Register Chart.js components
ChartJS.register(
    CategoryScale,
    LinearScale,
    PointElement,
    LineElement,
    BarElement,
    ArcElement,
    Title,
    Tooltip,
    Legend,
    Filler,
);

interface ChatbotBasic {
    id: string;
    name: string;
    status: string;
}

interface Props {
    chatbot: ChatbotBasic;
    dateRange: {
        start: string;
        end: string;
    };
    overview: AnalyticsOverview;
    dailyData: DailyData[];
    ratingDistribution: RatingDistribution;
    responseTimeDistribution: ResponseTimeDistribution;
    topTopics: TopTopic[];
}

const props = defineProps<Props>();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: t('chatbots.index.heading'), href: ChatbotController.index().url },
    {
        title: props.chatbot.name,
        href: ChatbotController.show({ chatbot: props.chatbot.id }).url,
    },
    { title: t('chatbots.analytics.title'), href: '#' },
]);

// Date range form
const startDate = ref(props.dateRange.start);
const endDate = ref(props.dateRange.end);

const applyDateRange = () => {
    router.get(
        window.location.pathname,
        { start_date: startDate.value, end_date: endDate.value },
        { preserveState: true },
    );
};

// Chart data
const conversationsChartData = computed(() => ({
    labels: props.dailyData.map((d) => formatDateLabel(d.date)),
    datasets: [
        {
            label: 'Conversations',
            data: props.dailyData.map((d) => d.conversations),
            fill: true,
            borderColor: 'rgb(59, 130, 246)',
            backgroundColor: 'rgba(59, 130, 246, 0.1)',
            tension: 0.4,
        },
        {
            label: 'Messages',
            data: props.dailyData.map((d) => d.messages),
            fill: false,
            borderColor: 'rgb(34, 197, 94)',
            borderDash: [5, 5],
            tension: 0.4,
        },
    ],
}));

const conversationsChartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: {
            position: 'top' as const,
        },
    },
    scales: {
        y: {
            beginAtZero: true,
        },
    },
};

const ratingsChartData = computed(() => ({
    labels: [
        t('chatbots.conversation.star_1'),
        t('chatbots.conversation.star_2'),
        t('chatbots.conversation.star_3'),
        t('chatbots.conversation.star_4'),
        t('chatbots.conversation.star_5'),
    ],
    datasets: [
        {
            label: 'Ratings',
            data: [
                props.ratingDistribution[1] || 0,
                props.ratingDistribution[2] || 0,
                props.ratingDistribution[3] || 0,
                props.ratingDistribution[4] || 0,
                props.ratingDistribution[5] || 0,
            ],
            backgroundColor: [
                'rgba(239, 68, 68, 0.8)',
                'rgba(249, 115, 22, 0.8)',
                'rgba(234, 179, 8, 0.8)',
                'rgba(34, 197, 94, 0.8)',
                'rgba(22, 163, 74, 0.8)',
            ],
        },
    ],
}));

const ratingsChartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: {
            display: false,
        },
    },
    scales: {
        y: {
            beginAtZero: true,
        },
    },
};

const responseTimeChartData = computed(() => ({
    labels: ['< 1s', '1-3s', '3-5s', '> 5s'],
    datasets: [
        {
            data: [
                props.responseTimeDistribution.fast,
                props.responseTimeDistribution.normal,
                props.responseTimeDistribution.slow,
                props.responseTimeDistribution.very_slow,
            ],
            backgroundColor: [
                'rgba(34, 197, 94, 0.8)',
                'rgba(59, 130, 246, 0.8)',
                'rgba(249, 115, 22, 0.8)',
                'rgba(239, 68, 68, 0.8)',
            ],
        },
    ],
}));

const responseTimeChartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: {
            position: 'right' as const,
        },
    },
};

// Helpers
const formatDateLabel = (date: string): string => {
    const d = new Date(date);
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
};

const formatResponseTime = (ms: number): string => {
    if (ms < 1000) return `${ms}ms`;
    return `${(ms / 1000).toFixed(1)}s`;
};

const hasRatings = computed(() => {
    return Object.values(props.ratingDistribution).some((v) => v > 0);
});

const hasResponseTimes = computed(() => {
    return Object.values(props.responseTimeDistribution).some((v) => v > 0);
});
</script>

<template>
    <Head :title="`${t('chatbots.analytics.title')} - ${chatbot.name}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="px-4 py-6">
            <div class="mx-auto max-w-6xl">
                <!-- Header -->
                <div class="mb-8 flex items-start justify-between">
                    <Heading
                        :title="`Analytics: ${chatbot.name}`"
                        description="Track conversations, performance, and engagement"
                    />
                </div>

                <!-- Date Range Filter -->
                <Card class="mb-6">
                    <CardContent class="pt-6">
                        <form
                            class="flex flex-wrap items-end gap-4"
                            @submit.prevent="applyDateRange"
                        >
                            <div class="flex-1 space-y-2">
                                <Label for="start_date">Start Date</Label>
                                <Input
                                    id="start_date"
                                    v-model="startDate"
                                    type="date"
                                />
                            </div>
                            <div class="flex-1 space-y-2">
                                <Label for="end_date">End Date</Label>
                                <Input
                                    id="end_date"
                                    v-model="endDate"
                                    type="date"
                                />
                            </div>
                            <Button type="submit">Apply</Button>
                        </form>
                    </CardContent>
                </Card>

                <!-- Overview Stats -->
                <div class="mb-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Card>
                        <CardHeader
                            class="flex flex-row items-center justify-between pb-2"
                        >
                            <CardTitle class="text-sm font-medium"
                                >Conversations</CardTitle
                            >
                            <MessageSquare
                                class="h-4 w-4 text-muted-foreground"
                            />
                        </CardHeader>
                        <CardContent>
                            <div class="text-2xl font-bold">
                                {{ overview.total_conversations }}
                            </div>
                            <div class="flex items-center text-xs">
                                <component
                                    :is="
                                        overview.conversations_trend >= 0
                                            ? ArrowUp
                                            : ArrowDown
                                    "
                                    :class="[
                                        'mr-1 h-3 w-3',
                                        overview.conversations_trend >= 0
                                            ? 'text-green-500'
                                            : 'text-red-500',
                                    ]"
                                />
                                <span
                                    :class="
                                        overview.conversations_trend >= 0
                                            ? 'text-green-500'
                                            : 'text-red-500'
                                    "
                                >
                                    {{
                                        Math.abs(overview.conversations_trend)
                                    }}%
                                </span>
                                <span class="ml-1 text-muted-foreground"
                                    >vs previous period</span
                                >
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader
                            class="flex flex-row items-center justify-between pb-2"
                        >
                            <CardTitle class="text-sm font-medium"
                                >Messages</CardTitle
                            >
                            <MessageCircle
                                class="h-4 w-4 text-muted-foreground"
                            />
                        </CardHeader>
                        <CardContent>
                            <div class="text-2xl font-bold">
                                {{ overview.total_messages }}
                            </div>
                            <p class="text-xs text-muted-foreground">
                                {{ overview.messages_per_conversation }} avg per
                                conversation
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader
                            class="flex flex-row items-center justify-between pb-2"
                        >
                            <CardTitle class="text-sm font-medium"
                                >Unique Sessions</CardTitle
                            >
                            <Users class="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div class="text-2xl font-bold">
                                {{ overview.unique_sessions }}
                            </div>
                            <p class="text-xs text-muted-foreground">
                                Unique visitors
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader
                            class="flex flex-row items-center justify-between pb-2"
                        >
                            <CardTitle class="text-sm font-medium"
                                >Avg Response Time</CardTitle
                            >
                            <Clock class="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div class="text-2xl font-bold">
                                {{
                                    formatResponseTime(
                                        overview.avg_response_time_ms,
                                    )
                                }}
                            </div>
                            <p class="text-xs text-muted-foreground">
                                Per response
                            </p>
                        </CardContent>
                    </Card>
                </div>

                <!-- Second Row Stats -->
                <div class="mb-8 grid gap-4 sm:grid-cols-3">
                    <Card>
                        <CardHeader
                            class="flex flex-row items-center justify-between pb-2"
                        >
                            <CardTitle class="text-sm font-medium"
                                >Avg Rating</CardTitle
                            >
                            <Star class="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div class="text-2xl font-bold">
                                {{
                                    overview.avg_rating
                                        ? overview.avg_rating.toFixed(1)
                                        : '-'
                                }}
                            </div>
                            <p class="text-xs text-muted-foreground">
                                {{ overview.total_ratings }} total ratings
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader
                            class="flex flex-row items-center justify-between pb-2"
                        >
                            <CardTitle class="text-sm font-medium"
                                >Resolution Rate</CardTitle
                            >
                            <Target class="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div class="text-2xl font-bold">
                                {{ overview.resolution_rate }}%
                            </div>
                            <p class="text-xs text-muted-foreground">
                                {{ overview.resolved_count }} resolved,
                                {{ overview.abandoned_count }} abandoned
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader
                            class="flex flex-row items-center justify-between pb-2"
                        >
                            <CardTitle class="text-sm font-medium"
                                >Engagement</CardTitle
                            >
                            <TrendingUp class="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div class="text-2xl font-bold">
                                {{ overview.messages_per_conversation }}
                            </div>
                            <p class="text-xs text-muted-foreground">
                                Messages per conversation
                            </p>
                        </CardContent>
                    </Card>
                </div>

                <!-- Charts -->
                <div class="mb-8 grid gap-6 lg:grid-cols-2">
                    <!-- Conversations Over Time -->
                    <Card class="lg:col-span-2">
                        <CardHeader>
                            <CardTitle>Conversations Over Time</CardTitle>
                            <CardDescription
                                >Daily conversation and message
                                counts</CardDescription
                            >
                        </CardHeader>
                        <CardContent>
                            <div class="h-[300px]">
                                <Line
                                    :data="conversationsChartData"
                                    :options="conversationsChartOptions"
                                />
                            </div>
                        </CardContent>
                    </Card>

                    <!-- Rating Distribution -->
                    <Card>
                        <CardHeader>
                            <CardTitle>Rating Distribution</CardTitle>
                            <CardDescription
                                >How visitors rate their
                                experience</CardDescription
                            >
                        </CardHeader>
                        <CardContent>
                            <div v-if="hasRatings" class="h-[250px]">
                                <Bar
                                    :data="ratingsChartData"
                                    :options="ratingsChartOptions"
                                />
                            </div>
                            <div
                                v-else
                                class="flex h-[250px] items-center justify-center text-muted-foreground"
                            >
                                No ratings yet
                            </div>
                        </CardContent>
                    </Card>

                    <!-- Response Time Distribution -->
                    <Card>
                        <CardHeader>
                            <CardTitle>Response Time Distribution</CardTitle>
                            <CardDescription
                                >How fast the chatbot responds</CardDescription
                            >
                        </CardHeader>
                        <CardContent>
                            <div v-if="hasResponseTimes" class="h-[250px]">
                                <Doughnut
                                    :data="responseTimeChartData"
                                    :options="responseTimeChartOptions"
                                />
                            </div>
                            <div
                                v-else
                                class="flex h-[250px] items-center justify-center text-muted-foreground"
                            >
                                No response data yet
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <!-- Top Topics -->
                <Card>
                    <CardHeader>
                        <CardTitle>Top Topics</CardTitle>
                        <CardDescription
                            >Most common conversation starters</CardDescription
                        >
                    </CardHeader>
                    <CardContent>
                        <div v-if="topTopics.length > 0" class="space-y-3">
                            <div
                                v-for="(topic, index) in topTopics"
                                :key="index"
                                class="flex items-center justify-between rounded-lg border p-3"
                            >
                                <div class="flex items-center gap-3">
                                    <span
                                        class="flex h-6 w-6 items-center justify-center rounded-full bg-primary/10 text-xs font-medium text-primary"
                                    >
                                        {{ index + 1 }}
                                    </span>
                                    <span class="text-sm">{{
                                        topic.topic
                                    }}</span>
                                </div>
                                <span
                                    class="text-sm font-medium text-muted-foreground"
                                >
                                    {{ topic.count }} conversations
                                </span>
                            </div>
                        </div>
                        <div
                            v-else
                            class="py-8 text-center text-muted-foreground"
                        >
                            No conversation data yet
                        </div>
                    </CardContent>
                </Card>
            </div>
        </div>
    </AppLayout>
</template>
