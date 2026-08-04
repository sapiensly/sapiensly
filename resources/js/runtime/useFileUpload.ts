import axios from 'axios';
import { ref } from 'vue';

/** What the server hands back once the bytes are stored. */
export interface UploadedFile {
    file_id: string;
    original_name: string;
    mime: string;
    size_bytes: number;
    url: string;
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
export function useFileUpload(appSlug: string) {
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
                response?: { data?: { message?: string } };
                message?: string;
            };
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
