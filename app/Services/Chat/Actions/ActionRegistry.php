<?php

namespace App\Services\Chat\Actions;

use App\Ai\Tools\Chat\ProposeBuildTool;
use App\Mcp\Tools\Agents\CreateAgentTool;
use App\Mcp\Tools\Build\CreateAppTool;
use App\Mcp\Tools\Build\ScaffoldAppTool;
use App\Mcp\Tools\Chatbots\CreateChatbotTool;
use App\Mcp\Tools\Data\AddDocumentTool;
use App\Mcp\Tools\Data\CreateKnowledgeBaseTool;
use App\Mcp\Tools\Integrations\CreateIntegrationTool;
use App\Mcp\Tools\Slides\CreatePresentationTool;

/**
 * Maps a synthesized / proposed `action_type` to the handler that can execute it.
 *
 * Beyond the `manual` fallback (a described, unwired close), it knows the
 * platform "build" actions that {@see ProposeBuildTool} can
 * propose — each runs the matching MCP create tool as the chat owner. Register
 * real handlers here as they are wired — the synthesizer's validation and the
 * executor both route through this class, so nothing else changes when the
 * registry grows.
 */
class ActionRegistry
{
    /** @var array<string, ActionHandler> */
    private array $handlers = [];

    public function __construct()
    {
        $this->register(new ManualAction);

        // Platform build actions — must stay in sync with ProposeBuildTool::BUILD_TYPES.
        $this->register(new PlatformBuildAction('create_app', CreateAppTool::class));
        $this->register(new PlatformBuildAction('scaffold_app', ScaffoldAppTool::class));
        $this->register(new PlatformBuildAction('create_chatbot', CreateChatbotTool::class));
        $this->register(new PlatformBuildAction('create_integration', CreateIntegrationTool::class));
        $this->register(new PlatformBuildAction('create_knowledge_base', CreateKnowledgeBaseTool::class));
        $this->register(new PlatformBuildAction('create_agent', CreateAgentTool::class));
        $this->register(new PlatformBuildAction('save_document', AddDocumentTool::class));
        $this->register(new PlatformBuildAction('create_presentation', CreatePresentationTool::class));
    }

    public function register(ActionHandler $handler): void
    {
        $this->handlers[$handler->key()] = $handler;
    }

    public function knows(string $actionType): bool
    {
        return isset($this->handlers[$actionType]);
    }

    /**
     * Resolve the handler for an action type, falling back to the manual handler
     * for anything not (yet) wired.
     */
    public function resolve(string $actionType): ActionHandler
    {
        return $this->handlers[$actionType] ?? $this->handlers[ManualAction::KEY];
    }

    /**
     * The effective action type to persist: a known type as-is, otherwise
     * `manual` (the proposal is described but not wired to a real workflow).
     */
    public function normalizeType(string $actionType): string
    {
        return $this->knows($actionType) ? $actionType : ManualAction::KEY;
    }
}
