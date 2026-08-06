import { purgeQueue } from '@/runtime/offlineQueue';
import { purgeOfflineCaches } from './serviceWorker';

/**
 * Everything offline left on this device, forgotten at once.
 *
 * There are two stores and they must be cleared together: the caches hold rows
 * this person was allowed to read, and the queue holds writes they had not
 * sent. Clearing one is worse than clearing neither, because it leaves a device
 * that looks signed out and still holds somebody's work.
 *
 * One function so a sign-out path cannot wire up half of it — which is exactly
 * what happened when the cache purge shipped with no caller at all.
 */
export async function purgeOfflineStorage(): Promise<void> {
    await Promise.all([purgeOfflineCaches(), purgeQueue()]);
}
