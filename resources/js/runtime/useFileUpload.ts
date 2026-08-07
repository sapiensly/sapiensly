import axios from 'axios';
import { ref } from 'vue';
import { holdFile } from './offlineFiles';
import { runtimeWord } from './words';

/** What the server hands back once the bytes are stored. */
export interface UploadedFile {
    file_id: string;
    original_name: string;
    mime: string;
    size_bytes: number;
    url: string;
    /**
     * The bytes are on this device and nowhere else.
     *
     * Absent on every real upload, so nothing has to know about offline to
     * behave correctly — but the field that renders a preview MUST read it and
     * say so, or a person sees their photo and believes it is in the record.
     */
    pending?: true;
}

/**
 * Getting bytes to the server, wherever they came from.
 *
 * Extracted when the SECOND thing needed it — a signature, drawn on a canvas —
 * rather than when the first did. At one consumer the shared shape is a guess;
 * at three it is already three copies and a migration. At two it is exactly
 * this: the post, the progress, the error, and the one question of WHERE.
 *
 * What is deliberately NOT in here is how the bytes are obtained. A photo comes
 * from an <input capture>, a signature from a canvas, and a future scan from a
 * video frame — those have nothing in common but their result, so each keeps
 * its own way of producing a Blob and hands it over.
 */
export function useFileUpload(
    appSlug: string,
    options: {
        /** The app's locale, for what this says on its own behalf. */
        locale?: string;
        /**
         * Keep the bytes when they cannot be uploaded, and hand back a
         * stand-in the form can use.
         *
         * OPT-IN, for the same reason queueing a write is: not every upload
         * through here is an attachment. `BlockForm` uploads a document to
         * have a MODEL read it, and bytes held for a reading that will never
         * happen are a photo the person thinks they attached to nothing.
         */
        holdOffline?: boolean;
    } = {},
) {
    const locale = options.locale ?? 'en';
    const progress = ref(0);
    const error = ref<string | null>(null);

    /**
     * Where uploads go. The runtime is mounted at /r/{slug} when signed in and
     * at /a/{public_slug} on a public portal, and only the URL knows which — a
     * form on a portal that posted to /r/ would 401 on every attachment.
     */
    function mount(): string {
        if (typeof window !== 'undefined') {
            const m = window.location.pathname.match(
                /^\/(r|a)\/([a-z0-9][a-z0-9_-]*)/,
            );
            if (m) return `/${m[1]}/${m[2]}`;
        }

        return `/r/${appSlug}`;
    }

    /**
     * @param blob The bytes. A File carries its own name; anything else needs one.
     */
    async function upload(
        blob: Blob,
        filename?: string,
    ): Promise<UploadedFile | null> {
        error.value = null;
        progress.value = 0;

        try {
            const form = new FormData();
            form.append(
                'file',
                blob,
                filename ?? (blob instanceof File ? blob.name : 'upload'),
            );

            const { data } = await axios.post<UploadedFile>(
                `${mount()}/uploads`,
                form,
                {
                    headers: { 'Content-Type': 'multipart/form-data' },
                    onUploadProgress: (e) => {
                        if (e.total) {
                            progress.value = Math.round(
                                (e.loaded / e.total) * 100,
                            );
                        }
                    },
                },
            );

            progress.value = 100;

            return data;
        } catch (e) {
            const err = e as {
                response?: { status?: number; data?: { message?: string } };
                message?: string;
            };

            // Never reached a server. The bytes are the whole point of the
            // form — a work order closes with the photo of the meter and the
            // customer's signature on it — so keep them and hand back
            // something the field can use. The queue uploads them for real
            // just before it sends the write that refers to them.
            if (err.response?.status === undefined && options.holdOffline) {
                const held = await holdFile(
                    blob,
                    filename ?? (blob instanceof File ? blob.name : 'upload'),
                );

                if (held !== null) {
                    progress.value = 100;

                    return held;
                }

                // Nowhere to put it: no IndexedDB, or the device is already
                // holding all it may. Say which, because "upload failed" sends
                // somebody to retry a thing that will fail the same way.
                error.value = runtimeWord(locale, 'offline_no_room');

                return null;
            }

            error.value =
                err.response?.data?.message ?? err.message ?? 'Upload failed.';

            return null;
        }
    }

    function reset(): void {
        progress.value = 0;
        error.value = null;
    }

    return { progress, error, upload, reset };
}
