<?php

namespace App\Services\Records;

use App\Models\AppFile;
use App\Models\User;
use App\Services\Ai\AiCapabilities;
use App\Services\Ai\OpenRouterClient;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Files;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Transcription;
use Throwable;

/**
 * A photograph of a document, turned into the fields of a record.
 *
 * The one thing on this platform a competitor cannot copy in a quarter. Somebody
 * photographs a supplier invoice and the form fills itself — the form is still
 * there, every value is still editable, and the record still goes through the
 * ordinary write path with its ordinary validation. Nothing is saved from here.
 *
 * That last part is the design. An extraction is a SUGGESTION: the model read a
 * crumpled receipt in bad light, and the person holding it is the one who knows
 * whether it says 1,250 or 7,250. Filling a form they then check is help;
 * writing a record they never saw is a liability with a nice demo.
 *
 * The field list comes from the manifest, so the model is told what it is
 * looking for — names, types, and the exact options a select will accept. A
 * prompt that asks for "the data" gets prose; one that asks for `total` as a
 * number and `estado` as one of four strings gets something a form can use.
 */
class RecordExtractionService
{
    /** Fields described to the model. Past this, the prompt is the problem. */
    private const MAX_FIELDS = 60;

    public function __construct(
        private readonly AiCapabilities $capabilities,
        private readonly OpenRouterClient $openRouter,
    ) {}

    /**
     * @param  array<string, mixed>  $object  the manifest object to fill
     * @return array{values: array<string, mixed>, error: string|null}
     */
    public function extract(AppFile $file, array $object, User $actor): array
    {
        $handler = $this->capabilities->resolve('image_vision')
            ?? $this->capabilities->resolve('ocr_pdf');

        if ($handler === null) {
            return [
                'values' => [],
                // Named plainly: this is a platform setting, and somebody
                // hunting for it in their own app would never find it.
                'error' => 'No vision model is configured for this platform.',
            ];
        }

        $fields = $this->describeFields($object);
        if ($fields === []) {
            return ['values' => [], 'error' => 'This object has nothing to fill.'];
        }

        try {
            $json = $this->ask($file, $this->prompt($object, $fields), $handler, $actor);
        } catch (Throwable $e) {
            report($e);

            return ['values' => [], 'error' => 'The document could not be read.'];
        }

        return ['values' => $this->mapToFields($json, $fields), 'error' => null];
    }

    /**
     * The same, said out loud.
     *
     * Two model calls rather than one: the audio is transcribed, and the
     * transcript is read for fields. Kept apart because they fail differently
     * and a person can act on the difference — "I could not hear that" sends
     * somebody back to a quiet room, while "I heard you but there was no total
     * in it" sends them back to the receipt.
     *
     * @param  array<string, mixed>  $object
     * @return array{values: array<string, mixed>, transcript: string, error: string|null}
     */
    public function extractFromSpeech(AppFile $file, array $object, User $actor): array
    {
        $handler = $this->capabilities->resolve('audio_recognition');

        if ($handler === null) {
            return [
                'values' => [],
                'transcript' => '',
                'error' => 'No speech model is configured for this platform.',
            ];
        }

        try {
            $transcript = trim($this->transcribe($file, $handler, $actor));
        } catch (Throwable $e) {
            report($e);

            return ['values' => [], 'transcript' => '', 'error' => 'The recording could not be understood.'];
        }

        if ($transcript === '') {
            return ['values' => [], 'transcript' => '', 'error' => 'Nothing could be heard in that recording.'];
        }

        $fields = $this->describeFields($object);
        if ($fields === []) {
            return ['values' => [], 'transcript' => $transcript, 'error' => 'This object has nothing to fill.'];
        }

        // The reading is an ordinary text call: the picture is gone by now, and
        // what is left is words on a page like any other.
        $reader = $this->capabilities->resolve('chat') ?? $this->capabilities->resolve('image_vision');
        if ($reader === null) {
            return ['values' => [], 'transcript' => $transcript, 'error' => 'No model is configured for this platform.'];
        }

        $prompt = $this->prompt($object, $fields)
            .'

The document is this transcript of somebody speaking:

'.$transcript;

        try {
            $json = $this->askText($prompt, $reader);
        } catch (Throwable $e) {
            report($e);

            return ['values' => [], 'transcript' => $transcript, 'error' => 'That could not be read.'];
        }

        return [
            'values' => $this->mapToFields($json, $fields),
            // Returned so the person can SEE what was heard. A wrong field with
            // no transcript beside it is a mystery; with one it is obvious.
            'transcript' => $transcript,
            'error' => null,
        ];
    }

    /**
     * @param  array{driver: string, provider: mixed, model: string}  $handler
     */
    private function transcribe(AppFile $file, array $handler, User $actor): string
    {
        if ($handler['driver'] === 'openrouter') {
            $bytes = Storage::disk($file->disk)->get($file->storage_path);
            $format = pathinfo((string) $file->storage_path, PATHINFO_EXTENSION) ?: 'webm';

            $response = $this->openRouter->chat($actor, $handler['model'], [
                OpenRouterClient::textBlock('Transcribe the attached audio verbatim. Output only the transcript.'),
                OpenRouterClient::audioBlock(base64_encode((string) $bytes), $format),
            ]);

            return OpenRouterClient::text($response);
        }

        return (string) Transcription::fromStorage($file->storage_path, $file->disk)
            ->generate($handler['provider'], $handler['model'])
            ->text;
    }

    /**
     * @param  array{driver: string, provider: mixed, model: string}  $handler
     * @return array<string, mixed>
     */
    private function askText(string $prompt, array $handler): array
    {
        $reply = '';

        $stream = (new AnonymousAgent('You extract structured data from text.', [], []))->stream(
            $prompt,
            provider: $handler['provider'],
            model: $handler['model'],
        );

        foreach ($stream as $event) {
            if ($event instanceof TextDelta) {
                $reply .= $event->delta;
            }
        }

        return $this->decode($reply);
    }

    /**
     * What the model is looking for, in the app's own words.
     *
     * @param  array<string, mixed>  $object
     * @return array<string, array<string, mixed>> keyed by slug
     */
    private function describeFields(array $object): array
    {
        $out = [];

        foreach ($object['fields'] ?? [] as $field) {
            // Derived fields are worked out from the others, and a relation
            // needs a record id no photograph contains. Asking for either would
            // be inviting an answer that cannot be used.
            if (in_array($field['type'] ?? '', ['rollup', 'lookup', 'formula', 'relation', 'file'], true)) {
                continue;
            }

            if (($field['readonly'] ?? false) === true) {
                continue;
            }

            $out[$field['slug']] = $field;

            if (count($out) >= self::MAX_FIELDS) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $object
     * @param  array<string, array<string, mixed>>  $fields
     */
    private function prompt(array $object, array $fields): string
    {
        $lines = [];

        foreach ($fields as $slug => $field) {
            $line = "- {$slug} ({$field['type']}): ".($field['name'] ?? $slug);

            // The exact strings a select will accept. Without them the model
            // returns "Paid" for a field whose only options are pagada and
            // pendiente, and every one of those is a value the form drops.
            if (in_array($field['type'], ['single_select', 'multi_select'], true)) {
                $values = array_map(
                    fn (array $o): string => (string) ($o['value'] ?? ''),
                    $field['options'] ?? [],
                );
                $line .= '. One of: '.implode(', ', array_filter($values));
            }

            if (($field['type'] ?? '') === 'date') {
                $line .= '. Format YYYY-MM-DD.';
            }

            $lines[] = $line;
        }

        $name = $object['name'] ?? 'record';

        return <<<PROMPT
            Read the attached document and extract the fields of one {$name}.

            Fields:
            {$this->joined($lines)}

            Answer with a single JSON object keyed by the field name on the left.
            OMIT any field the document does not clearly state — a guess is worse
            than a gap here, because the person will check what is filled in and
            not what is missing. Never invent a value to be helpful. No prose, no
            code fence, JSON only.
            PROMPT;
    }

    /** @param  list<string>  $lines */
    private function joined(array $lines): string
    {
        return implode("\n", $lines);
    }

    /**
     * @param  array{driver: string, provider: mixed, model: string}  $handler
     * @return array<string, mixed>
     */
    private function ask(AppFile $file, string $prompt, array $handler, User $actor): array
    {
        $isImage = str_starts_with((string) $file->mime, 'image/');

        if ($handler['driver'] === 'openrouter') {
            $bytes = Storage::disk($file->disk)->get($file->storage_path);
            $dataUrl = 'data:'.$file->mime.';base64,'.base64_encode((string) $bytes);

            $response = $this->openRouter->chat($actor, $handler['model'], [
                OpenRouterClient::textBlock($prompt),
                $isImage
                    ? OpenRouterClient::imageBlock($dataUrl)
                    : OpenRouterClient::fileBlock($dataUrl, $file->original_name ?: 'document.pdf'),
            ], $isImage ? [] : ['plugins' => OpenRouterClient::pdfPlugins(OpenRouterClient::configuredPdfEngine())]);

            return $this->decode(OpenRouterClient::text($response));
        }

        $attachment = $isImage
            ? Files\Image::fromStorage($file->storage_path, $file->disk)
            : Files\Document::fromStorage($file->storage_path, $file->disk);

        // Streamed and collected rather than a blocking call, exactly as the
        // OCR tool does it: reading a dense document routinely outruns the
        // SDK's total request cap while never going idle.
        $stream = (new AnonymousAgent('You extract structured data from documents.', [], []))->stream(
            $prompt,
            attachments: [$attachment],
            provider: $handler['provider'],
            model: $handler['model'],
        );

        $reply = '';
        foreach ($stream as $event) {
            if ($event instanceof TextDelta) {
                $reply .= $event->delta;
            }
        }

        return $this->decode($reply);
    }

    /**
     * Models fence their JSON, apologise before it, and explain after it,
     * whatever the prompt says. The braces are the only reliable landmark.
     *
     * @return array<string, mixed>
     */
    private function decode(string $reply): array
    {
        $start = strpos($reply, '{');
        $end = strrpos($reply, '}');

        if ($start === false || $end === false || $end < $start) {
            return [];
        }

        $decoded = json_decode(substr($reply, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Keep what the object actually has, drop the rest.
     *
     * A model asked for eight fields sometimes returns nine, or spells one its
     * own way. An unknown key reaching the form would be a value nobody can see
     * and nobody can correct.
     *
     * @param  array<string, mixed>  $json
     * @param  array<string, array<string, mixed>>  $fields
     * @return array<string, mixed>
     */
    private function mapToFields(array $json, array $fields): array
    {
        $values = [];

        foreach ($json as $key => $value) {
            $slug = (string) $key;

            if (! isset($fields[$slug]) || $value === null || $value === '') {
                continue;
            }

            // A select may only carry one of its own options. The model was
            // told them; this is what happens when it answers anyway.
            $field = $fields[$slug];
            if ($field['type'] === 'single_select') {
                $allowed = array_map(fn (array $o): string => (string) ($o['value'] ?? ''), $field['options'] ?? []);
                if (! in_array((string) $value, $allowed, true)) {
                    continue;
                }
            }

            $values[$slug] = $value;
        }

        return $values;
    }
}
