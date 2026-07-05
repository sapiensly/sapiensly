<?php

namespace App\Services\Express\Phases;

use App\Enums\IntegrationAuthType;
use App\Models\Integration;
use App\Models\IntegrationUserToken;
use App\Models\PipelineRun;
use App\Services\Connected\IntegrationCatalog;
use App\Services\Express\Contracts\ExpressPhase;
use App\Services\Express\ExpressContext;
use App\Services\Express\ExpressHalt;

/**
 * F-1: pick the MCP source and load its catalog. Deterministic: exactly one
 * authorized MCP connection → use it; several → the fit-check gate sees all
 * their tools (prefixed) and effectively picks by choosing tools; none → halt
 * with a human explanation (Express only builds over live connected data).
 */
class ResolveSourcePhase implements ExpressPhase
{
    public function __construct(private readonly IntegrationCatalog $catalog) {}

    public function name(): string
    {
        return 'resolve_source';
    }

    public function announce(ExpressContext $context): string
    {
        return '🔌 Localizando la fuente de datos…';
    }

    public function run(ExpressContext $context, PipelineRun $run): void
    {
        $integrations = Integration::query()
            ->forAccountContext($context->user)
            ->where('is_mcp', true)
            ->where('status', '!=', 'draft')
            ->orderBy('name')
            ->get()
            ->filter(fn (Integration $i): bool => $this->authorizedFor($i, $context->user->id))
            ->values();

        if ($integrations->isEmpty()) {
            throw new ExpressHalt(
                'failed',
                'No hay ninguna conexión MCP autorizada para leer datos en vivo. Conecta una integración (y autorízala) y vuelve a intentar.',
            );
        }

        // v1 scope: one integration per run. With several, take the first whose
        // catalog answers; the fit-check gate then narrows to tools.
        foreach ($integrations as $integration) {
            try {
                $tools = $this->catalog->tools($integration, $context->user);
            } catch (\Throwable) {
                continue;
            }
            if ($tools !== []) {
                $context->integration = $integration;
                $context->catalogTools = $tools;
                $context->knownShapes = $this->catalog->knownShapes($integration);

                return;
            }
        }

        throw new ExpressHalt(
            'failed',
            'Ninguna conexión MCP respondió su lista de tools. Revisa que el servidor esté disponible y vuelve a intentar.',
        );
    }

    private function authorizedFor(Integration $integration, int $userId): bool
    {
        if ($integration->auth_type === IntegrationAuthType::None) {
            return true;
        }
        if ($integration->auth_type === IntegrationAuthType::OAuth2AuthorizationCode) {
            return IntegrationUserToken::query()
                ->where('user_id', $userId)
                ->where('integration_id', $integration->id)
                ->get()
                ->contains(fn (IntegrationUserToken $t): bool => $t->isAuthorized());
        }

        return true;
    }
}
