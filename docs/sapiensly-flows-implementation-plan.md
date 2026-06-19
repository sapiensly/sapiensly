# Plan de implementación — Flows: construcción conversacional

> **Origen:** plan acordado en sesión del 2026-06-18 (transcript `6b19f2a1`). Guardado
> como archivo el 2026-06-18 con el estado anotado por fase. Deriva de un spec con 5
> invariantes (propose-don't-mutate · AI never handles credentials · reject-before-you-pay ·
> wedge-first · name the blast radius).
>
> **Estado global (2026-06-18):** Fases 0–6 ✅ — **plan completo.**
> Sin commitear aún; suite completa en verde (1523 tests).

## Firma de diseño — el "blast-radius ribbon"

Una gramática de color consistente donde cada toque externo se lee de un vistazo —
**read = frío/seguro (`--color-accent-blue`)**, **write = cálido/con compuerta
(`--color-sp-warning`)**, **irreversible = `--color-sp-danger`** — y esa misma señal
aparece idéntica en la tarjeta de plan, el nodo del canvas, el reporte de verificación y
la compuerta de aprobación. Reusa los tokens admin-v2 existentes (chrome firmado, no se
re-estiliza sin permiso).

---

## 0. Puntos de extensión (verificados en código)

| Qué | Dónde se engancha |
|---|---|
| Nuevo step | `WorkflowEngine::dispatch()` `match()` + handler privado |
| Nuevo trigger | `ListAvailableTriggersTool` (catálogo) + `ManifestValidator` (lista hardcoded) + `WorkflowTriggerDispatcher` (dispatch) |
| Builder tool | implementa `Laravel\Ai\Contracts\Tool` + se agrega al array en `BuilderAiService` |
| Llamada a integración (auth+SSRF+OAuth per-user) | **ya existe**: `IntegrationCaller::send()` + `AuthStrategyFactory` |
| Provisionar integración | **ya existe**: `IntegrationAuthoring` / `CreateIntegrationTool` |
| Canvas nodo nuevo | entrada en `STEP_CATALOG` + reusar `StepNode.vue` + `AppWorkflowNodePanel.vue` |
| Streaming a UI | eventos Reverb en `builder.conversation.{id}` (patrón `BuilderActivity`/`BuilderStreamChunk`) |

**El 70% del substrato ya existe.** Lo nuevo es la capa de contrato, el lazo verify/repair
y la maquinaria de aprobación.

---

## Fase 0 — Substrato de contrato (FR-6/6.1) · ✅ HECHA

- **`ConnectorActionContract`** (DTO): `id`, `integration_id?`, `inputs`, `outputs`, `effect`, `blast_radius`, `safe`.
- **`ConnectorActionResolver`** (service): lee la tabla `tools`; deriva `effect` (GET/read_only → read; resto → write) con override del autor; levanta IO de `config.parameters` para `function`, infiere del resto; marca `untyped` cuando no hay schema.
- **Migración**: `effect` (nullable, override) + `safe` (bool, default false) en `tools` (modelo platform).
- **Enum** `ConnectorEffect` (read/write).
- **Tests**: `tests/Unit/Services/Connectors/` (resolver por `ToolType`).

---

## Fase 1 — `connector.call` + builder tools (FR-3, FR-5) · ✅ HECHA

- `handleConnectorCall()` en `WorkflowEngine`: resuelve integration/action → inputs vía `ExpressionResolver` → `IntegrationCaller::send()` (auth+SSRF) → moldea output. **FR-5.1**: usuario no autorizado → falla con "authorize this integration", nunca skip silencioso.
- Builder tools: `list_available_integrations`, `list_connector_actions`, `provision_integration` (delega en `IntegrationAuthoring`).
- `connector.call` registrado en `ListAvailableStepsTool` + forma estructural en `ManifestValidator`.
- **UI**: nodo en canvas con el *blast-radius ribbon* (chip read frío / write cálido + candado), panel con selector integración → acción → mapeo de inputs con autocomplete `{{…}}`.
- **Tests**: `ConnectorCallStepTest`, `ListConnectorToolsTest`.

---

## Fase 2 — Discovery como propuesta (FR-1) · ✅ HECHA

El builder **no edita el manifest hasta que el usuario aprueba el plan**.

- **Backend**: `BuilderAiService` produce un `PlanProposal` estructurado antes de `applyPatch`: `{ trigger, steps[], integrations[], assumptions[] }` con cada touch marcado read/write. Tool `propose_plan`; columna `plan` en `builder_messages`.
- **UI**: `BuilderPlanCard.vue` — tarjeta de plan con filas numeradas por step + chip de effect, fila "Touches" (blast radius antes de construir, FR-1.3), assumptions como default + `[change]` (FR-1.2, nunca campo en blanco), botón primario `Build it`.
- **Tests**: `ProposePlanToolTest`.

---

## Fase 3 — Provisioning mid-conversation (FR-7/8) · ✅ HECHA

- **Backend**: `provision_integration` emite un *integration proposal* (provider, auth OAuth2 auto-discovery, acciones requeridas). El step dependiente se compone en estado `pending`; la verificación reporta la dependencia, no falla (FR-7.3). FR-8.1: write nunca auto-cableado sin grant explícito. Columna `integration_proposal` en `builder_messages`.
- **UI**: `BuilderIntegrationCard.vue` — tarjeta con máquina de estados streameada (Proposed→Authorize→Authorized→Ready), reusa el flujo OAuth existente. "El modelo nunca toca credenciales" (invariante 4, en copy).
- **Tests**: `CreateIntegrationProposalTest`.

---

## Fase 4 — Build → verify → repair (FR-2) · ✅ HECHA

- Modo **dry-run** en `WorkflowEngine`: reads externos en vivo (side-effect-free), writes externos **simulados** → preview de Proposal (FR-2.3); writes internos contra dataset desechable (nunca rollback transaccional por RLS+triggers). Columna `dry_run` en `workflow_runs`.
- **Assertions cerradas** (FR-2.5): `WorkflowAssertionEvaluator` con `step_reached`, `step_status`, `output_equals/matches`, `proposal_emitted`, `no_external_write` — contra el trace `WorkflowRun`/`WorkflowStepRun`.
- **Lazo de auto-reparación acotado** (FR-2.6): builder tool `verify_workflow` (`VerifyWorkflowTool`) que dry-corre + evalúa assertions contra el manifest *draft* (vía `ProposeChangeTool::currentManifest`). Cuenta intentos por workflow dentro del turno; corta con `stop_repairing=true` al llegar a **máx 3 intentos** o cuando la **firma de fallo se repite** (oscilación = patch que no cambió nada material). Registrado en `BuilderAiService` (sendMessage + streamMessage). Tests: `VerifyWorkflowToolTest`.
- **Reporte UI "Verified"** (FR-2.4): `AppWorkflowEditor.vue` — badge pass/fail, checks por aserción con detalle, writes simulados con chip `write·simulated` y el blast-radius ribbon (write=ámbar/read=azul), error del run.

---

## Fase 5 — Triggers `schedule` + `webhook.inbound` (FR-4) · ✅ HECHA

- **`schedule`**: branch en schema (`cron` + `timezone`) + regla `invalid_cron` en `ManifestValidator` (vía `Cron\CronExpression`) + catálogo. Comando `flows:dispatch-scheduled` (`DispatchScheduledWorkflows`) registrado en `routes/console.php` a `everyMinute()->withoutOverlapping()`: barre apps con versión activa, evalúa el cron en su timezone, e **idempotente por fire** vía lock de cache `(workflow, minuto)`. Cada disparo → `RunScheduledWorkflowJob` (cola `workflows`, scope tenant vía `EstablishTenantContext::fromOwner`).
- **`webhook.inbound`**: branch en schema (`dedupe_path?`, `signature_header?`) + catálogo. Endpoint dedicado firmado `POST /webhooks/flows/{app}/{workflow}` (`FlowWebhookController`, en `routes/webhooks.php`, **no** hereda el path del widget). Admisión antes de encolar (FR-4.1): **HMAC-SHA256** (`WorkflowWebhookSignature`, secreto derivado del app key — estable, mostrable, sin almacenar) → rate limit `flows-webhook` (300/min por workflow) → **dedupe** por `webhook_deliveries` (tabla tenant/RLS, único `(workflow_id, delivery_key)`; insert en savepoint para no abortar la tx). Luego `RunWebhookWorkflowJob`. Retorno 202 / 200-duplicate / 401-bad-sig / 404.
- **UI** (`AppWorkflowNodePanel.vue`): `schedule` → presets de cron + input + **preview en lenguaje natural** + timezone; `webhook.inbound` → URL firmada + secret (reveal/copy) vía endpoint `webhook-info` + `dedupe_path` + payload de ejemplo.
- **Tests**: `ScheduledWorkflowDispatchTest`, `FlowWebhookTest`, casos de cron/webhook en `ManifestValidatorTest`.

---

## Fase 6 — `approval` + propose-don't-mutate (FR-5.3/9.3) · ✅ HECHA (emit-then-stop) · *la pieza cara*

Corte **Phase-1 (emit-then-stop)**, como recomienda el plan — sin maquinaria de resume durable.

- **Gate en `WorkflowEngine`**: en un run real (no dry-run), un `connector.call` con `effect=write` **no marcado `safe`** lanza `WorkflowAwaitingApprovalException` con la acción parametrizada (inputs ya resueltos) + preview, **antes** de tocar el sistema externo. `run()` lo captura: estado `awaiting_approval`, crea un `WorkflowProposal` (pending), y para. Las escrituras `safe` y todas las lecturas pasan directo. Flag interno `gateApprovals` (reset por run).
- **Ejecución al aprobar**: `WorkflowEngine::executeApprovedAction` corre la acción aprobada para real con el gate desactivado (reusa `handleConnectorCall`; inputs ya literales). `WorkflowProposalService::approve/dismiss` — single-shot, marca `approved`/`dismissed`/`failed` + `resolved_by_user_id`.
- **Datos**: `WorkflowProposal` (tabla tenant/RLS: action json + effect + preview + status + resolver) + `workflow_runs.status = awaiting_approval` (sin cambio de schema). `WorkflowAwaitingApprovalException` nunca falla el step — lo marca `awaiting_approval` (solo la hoja lleva el preview).
- **Endpoints** (`AppWorkflowController`): `GET workflow-proposals` (pendientes), `POST .../{proposal}/approve`, `POST .../{proposal}/dismiss`.
- **UI** (`AppWorkflowEditor.vue`): tras un run `awaiting_approval`, carga las propuestas y renderiza la compuerta con effect ribbon (write·🔒) + preview + Approve/Dismiss.
- **Tests**: `WorkflowApprovalGateTest` (gate halts, safe ejecuta, approve ejecuta el write, dismiss descarta, single-shot, endpoints HTTP + forbidden).

### Diferido (Phase-1.5, no-goal del wedge)
- **pause/resume durable** — status `awaiting_approval` ya existe, pero **no** hay continuación serializada (cursor + vars + outputs, incl. posición en `foreach`/`branch`) ni `resume(decision)`: hoy el run se detiene en la primera escritura gated y aprobar ejecuta **esa acción aislada**, no reanuda el resto del workflow.
- **Gate de escrituras internas** (record.create/update/delete): el wedge gatea solo escrituras de **conector** (blast radius externo); las internas siguen ejecutando.
- **Entrega por Reverb + autoridad per-action (tenant admin)** y expiry de proposals.

---

## Modelo de datos / migraciones

| Tabla | Cambio | Esquema | Estado |
|---|---|---|---|
| `tools` | + `effect`, `safe` | platform | ✅ |
| `builder_messages` | + `plan`, `integration_proposal` | platform | ✅ |
| `workflow_runs` | + `dry_run` | tenant | ✅ |
| `workflow_runs` | + `awaiting_approval` status (sin schema) · `continuation` json (Fase 1.5) | tenant | ✅ status · ❌ continuation |
| nueva `workflow_proposals` | action json + effect preview + status + resolver | tenant (RLS) | ✅ |
| nueva `webhook_deliveries` | dedupe único `(workflow_id, delivery_key)` | tenant (RLS) | ✅ |

Toda tabla tenant nueva sigue el patrón `Schemas::TENANT_TABLES` + relocate/RLS/trigger.

## Secuencia recomendada

**Fase 0 → 1 → 2 → 3 → 4 → 5 → 6.** Las 0-3 entregan "describe it → it builds with real
connectors, provisioning what's missing". La 4 lo hace *confiable*. La 6 lo hace *seguro de
auto-ejecutar* — y es donde está el costo de motor; arrancar con emit-then-stop protege runway.
