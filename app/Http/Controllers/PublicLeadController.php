<?php

namespace App\Http\Controllers;

use App\Models\App;
use App\Services\Manifest\AppManifestService;
use App\Services\Records\RecordValidationException;
use App\Services\Records\RecordWriteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The public lead-capture endpoint — the ONE write path an anonymous visitor
 * gets, and the piece that makes a landing "se atiende sola": the created
 * record fires `record.created`, which runs the landing's workflows (agent
 * qualification, replies, CRM updates) through the exact same engine as the
 * authenticated runtime.
 *
 * Hardened by construction:
 *  - BindPublicLandingContext gates to a PUBLISHED landing and binds the
 *    owner's tenant scope (the RLS insert needs it).
 *  - The lead_form BLOCK is the contract: only values for the field_ids it
 *    declares are accepted — a guest can never write arbitrary fields or
 *    other objects (mass-assignment guard at the block level).
 *  - Honeypot: bots that fill the hidden field get a fake success and write
 *    nothing (never tip them off).
 *  - Cloudflare Turnstile when configured (skipped otherwise so local/dev
 *    works keyless); route-level throttling on top.
 *  - RecordWriteService validates values against the manifest's own field
 *    rules — the same boundary as every authenticated write.
 */
class PublicLeadController extends Controller
{
    public function __construct(
        private readonly AppManifestService $manifestService,
        private readonly RecordWriteService $writer,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var App $app */
        $app = $request->attributes->get('publicLandingApp');

        $data = $request->validate([
            'block_id' => ['required', 'string', 'max:80'],
            'values' => ['required', 'array', 'max:12'],
            // The honeypot: humans never see it, bots love it.
            'website' => ['sometimes', 'nullable', 'string'],
            'turnstile_token' => ['sometimes', 'nullable', 'string', 'max:2048'],
        ]);

        $manifest = $this->manifestService->getActiveManifest($app);
        $block = $manifest !== null ? $this->findLeadForm($manifest['pages'] ?? [], $data['block_id']) : null;
        if ($block === null) {
            abort(404);
        }

        // Honeypot tripped → pretend everything worked, write nothing.
        if (trim((string) ($data['website'] ?? '')) !== '') {
            return new JsonResponse(['ok' => true, 'message' => $this->successMessage($block)]);
        }

        if (! $this->passesTurnstile($request, (string) ($data['turnstile_token'] ?? ''))) {
            return new JsonResponse([
                'ok' => false,
                'message' => 'No pudimos verificar que eres humano. Recarga la página e inténtalo de nuevo.',
            ], 422);
        }

        // The block's declared fields are the ONLY accepted keys, and its
        // `required` flags are enforced here (the object's own rules validate
        // types/formats downstream).
        $declared = [];
        foreach ($block['fields'] ?? [] as $field) {
            $declared[(string) $field['field_id']] = $field;
        }

        $values = [];
        foreach ($data['values'] as $key => $value) {
            if (isset($declared[$key]) && (is_scalar($value) || $value === null)) {
                $values[$key] = is_string($value) ? trim($value) : $value;
            }
        }
        $missing = [];
        foreach ($declared as $fieldId => $field) {
            if (($field['required'] ?? false) === true && trim((string) ($values[$fieldId] ?? '')) === '') {
                $missing[] = $field['label'] ?? $fieldId;
            }
        }
        if ($missing !== []) {
            return new JsonResponse([
                'ok' => false,
                'message' => 'Faltan campos requeridos: '.implode(', ', $missing).'.',
            ], 422);
        }

        try {
            // user: null — an anonymous submission. The record.created trigger
            // fires inside create(); agent.invoke steps fall back to the app's
            // organization scope for user-less runs.
            $record = $this->writer->create($app, $manifest, (string) $block['object_id'], $values, null);
        } catch (RecordValidationException $e) {
            return new JsonResponse([
                'ok' => false,
                'message' => 'Revisa los datos: '.collect($e->errors)->flatten()->implode(' '),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Public lead capture failed', [
                'app_id' => $app->id, 'block_id' => $data['block_id'], 'error' => $e->getMessage(),
            ]);

            return new JsonResponse([
                'ok' => false,
                'message' => 'No pudimos registrar tus datos. Inténtalo de nuevo en un momento.',
            ], 500);
        }

        return new JsonResponse([
            'ok' => true,
            'lead_id' => $record->id,
            'message' => $this->successMessage($block),
        ]);
    }

    /**
     * Find the lead_form block by id across the manifest's pages (descending
     * through layout containers) — the block IS the submission contract.
     *
     * @param  array<int, mixed>  $pages
     * @return array<string, mixed>|null
     */
    private function findLeadForm(array $pages, string $blockId): ?array
    {
        foreach ($pages as $page) {
            if (is_array($page) && isset($page['blocks']) && is_array($page['blocks'])) {
                $found = $this->findInBlocks($page['blocks'], $blockId);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<int, mixed>  $blocks
     * @return array<string, mixed>|null
     */
    private function findInBlocks(array $blocks, string $blockId): ?array
    {
        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }
            if (($block['type'] ?? null) === 'lead_form' && ($block['id'] ?? null) === $blockId) {
                return $block;
            }
            foreach (['blocks', 'left_blocks', 'right_blocks'] as $key) {
                if (isset($block[$key]) && is_array($block[$key])) {
                    $found = $this->findInBlocks($block[$key], $blockId);
                    if ($found !== null) {
                        return $found;
                    }
                }
            }
            foreach (['tabs', 'sections'] as $key) {
                foreach ($block[$key] ?? [] as $sub) {
                    if (is_array($sub) && isset($sub['blocks']) && is_array($sub['blocks'])) {
                        $found = $this->findInBlocks($sub['blocks'], $blockId);
                        if ($found !== null) {
                            return $found;
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function successMessage(array $block): string
    {
        return (string) ($block['success_message']
            ?? 'Listo — un agente ya está revisando tu solicitud. Te contactamos en breve.');
    }

    /**
     * Cloudflare Turnstile verification. Skipped entirely when no secret is
     * configured (local/dev), so the honeypot + throttle remain the floor.
     */
    private function passesTurnstile(Request $request, string $token): bool
    {
        $secret = (string) (config('services.turnstile.secret_key') ?? '');
        if ($secret === '') {
            return true;
        }
        if ($token === '') {
            return false;
        }

        try {
            $response = Http::asForm()->timeout(5)->post(
                'https://challenges.cloudflare.com/turnstile/v0/siteverify',
                ['secret' => $secret, 'response' => $token, 'remoteip' => $request->ip()],
            );

            return (bool) ($response->json('success') ?? false);
        } catch (\Throwable) {
            // Verification outage: fail OPEN with the honeypot+throttle floor
            // still in place — a lost lead costs more than a spam row.
            return true;
        }
    }
}
