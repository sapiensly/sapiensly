<script setup lang="ts">
import { computed, inject } from 'vue';
import TrackingBar from '../TrackingBar.vue';

/**
 * Where an author puts the trail control.
 *
 * A block rather than something the chrome shows on every page, because WHICH
 * page this belongs on is the whole judgement: the visit somebody is driving
 * to, not the dashboard, and never a list left open on a desk. An app-wide
 * banner would be the version that follows people around.
 *
 * All the real work is in `useTracking`; this only resolves which record the
 * geofence is drawn around.
 */
interface TrackingBlock {
    id: string;
    type: 'tracking';
    record_id_expression?: string;
}

const props = defineProps<{ block: TrackingBlock; locale: string }>();

/** Same convention as every other block that has to name the app it is in. */
const appSlug = inject<string>('appSlug', '');
const pageParams = inject<Record<string, unknown>>('pageParams', {});

/**
 * `{{params.id}}` on a record's own page, which is the only form worth
 * supporting here: a geofence is drawn around the place the record names, and a
 * record is on screen because a param put it there.
 */
const recordId = computed<string | undefined>(() => {
    const raw = props.block.record_id_expression;
    if (typeof raw !== 'string' || raw === '') return undefined;

    const match = raw.match(/^\{\{\s*params\.([\w-]+)\s*\}\}$/);
    const value = match ? pageParams[match[1]] : raw;

    return typeof value === 'string' && value !== '' ? value : undefined;
});
</script>

<template>
    <TrackingBar :app-slug="appSlug" :locale="locale" :record-id="recordId" />
</template>
