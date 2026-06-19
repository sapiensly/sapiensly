# Plan de migración — Bot Flows: el flow como dueño del comportamiento conversacional

> **Origen:** sesión del 2026-06-19. Reestructura el dominio conversacional para que
> el **Bot Flow** (antes `Flow`) sea la única fuente del comportamiento de un AI Bot,
> conteniendo a los agentes como nodos. El **Chatbot** (UI: "AI Bot") deja de apuntar a
> Agent/AgentTeam y pasa a poseer **un** Bot Flow.
>
> **Decisiones bloqueadas (2026-06-19):**
> 1. **Rename completo de código** — `Flow`→`BotFlow`, tabla `flows`→`bot_flows`, rutas, Vue, tipos.
> 2. **Corte limpio (dev)** — sin datos en producción; las migraciones sueltan/recrean FKs sin backfill.
> 3. **Disolver `AgentTeam`** — el roster (triage/knowledge/action) vive en los nodos del Bot Flow; `TeamOrchestrationService` lo lee de ahí. `AgentTeam` se retira como acoplamiento del chatbot y como fuente de orquestación.

---

## La visión — antes vs. después

**Antes:**
```
Chatbot ──belongsTo──▶ Agent           Agent ──hasMany──▶ Flow
        ──belongsTo──▶ AgentTeam        (el flow vive en el cerebro)
Channel ──belongsTo──▶ Agent/AgentTeam  (target duplicado)
```

**Después:**
```
Chatbot (AI Bot) ──hasOne──▶ BotFlow ──nodos de agente──▶ Agent(s)
 (superficie de despliegue)  (guion + orquestación)        (cerebros reutilizables)
```

- **Agent** = cerebro reutilizable (persona, tools, knowledge). Ya **no** posee flows ni pertenece a un team.
- **BotFlow** = el diseño conversacional completo: el guion *y* qué agentes participan y cómo se coordinan. Pertenece a un Chatbot (`bot_flows.chatbot_id` único).
- **Chatbot** ("AI Bot" en UI) = dónde se publica el Bot Flow (widget/canal, tokens, analítica). Sin `agent_id`/`agent_team_id`.
- **AgentTeam** = se disuelve. El roster pasa a ser topología del grafo del Bot Flow.

---

## Nombres (código vs. UI)

| Concepto | Código / modelo | UI (usuario) |
|---|---|---|
| Cerebro reutilizable | `Agent` (sin cambio) | Agent |
| Guion + orquestación | `Flow` → **`BotFlow`** · `flows` → `bot_flows` · `/flows` → `/bot-flows` | **Bot Flow** |
| Superficie de despliegue | `Chatbot` (**sin cambio de código**) | **AI Bots** |
| Equipo multi-agente | `AgentTeam` → **retirado** | (desaparece) |

---

## 0. Puntos de extensión (verificados en código)

| Qué | Dónde se engancha hoy |
|---|---|
| Resolución del agente (widget) | `Chatbot::getTarget()` → `chatbot->agent ?? chatbot->agentTeam` (`Chatbot.php:97`) → `WidgetStreamService` / `TeamOrchestrationService::orchestrate()` |
| Activación de flow | `FlowExecutorService::shouldActivateFlow(Agent, msg, state)` → `Agent::activeFlow()` (`flows WHERE agent_id=? AND status=active`) |
| Roster multi-agente | `AgentTeam::triageAgent()/knowledgeAgent()/actionAgent()` (HasOne por `type`) |
| Handoff entre agentes | nodo `agent_handoff`, `data.target_agent` = **rol string** (`triage_llm`/`knowledge`/`action`), resuelto en `TeamOrchestrationService::emitFlowAction()` |
| Estado del flow | `conversation.metadata['flow_state']` (`{flow_id, current_node_id, history[], completed}`) |
| Target del canal | `Channel.agent_id`/`agent_team_id` (duplicado del chatbot; usado por scope WhatsApp) |
| Editor de agentes-en-flow | **ya existe parcialmente**: `FlowController::createAgentForLayer()` crea Agents triage/knowledge/action; `AgentCreateModal.vue`, `CapabilityModal.vue` |

**Hallazgo clave:** el flow **global** (`/flows`) ya es *agent-less* y crea agentes adentro. La pieza nueva es atarlo al Chatbot y mover la orquestación de `AgentTeam` al roster del flow.

---

## Fase 0 — Rename `Flow` → `BotFlow` (código) + rebrand Chatbot → "AI Bots" (UI)

Puramente mecánico, **sin cambio de comportamiento**. Baseline renombrado y verificable (tests verdes) antes del refactor estructural.

- **Backend rename:** `Flow`→`BotFlow`, `FlowController`→`BotFlowController`, `FlowExecutorService`→`BotFlowExecutorService`, `FlowAction`/`FlowActionType`/`FlowStatus`, `ValidFlowDefinition`, `FlowPolicy`, `Flow/StoreFlowRequest`+`UpdateFlowRequest`. Migración de **rename de tabla** `flows`→`bot_flows` (`Schema::rename`). Rutas `/flows`→`/bot-flows`, `flows.*`→`bot-flows.*`.
- **Frontend rename:** `pages/flows/`→`pages/bot-flows/`, `components/flows/`→`components/bot-flows/`, `types/flows.d.ts`→`botFlows.d.ts`. Regenerar Wayfinder (`@/actions/.../BotFlowController`).
- **i18n Bot Flow:** claves `flows.*`→`botFlows.*` + copy "Bot Flow".
- **Rebrand UI "AI Bots":** valores de las 88 claves `chatbots.*` + `nav.chatbots` en `en.json`/`es.json` → "AI Bots" / "AI Bot". **El código `Chatbot` no se toca.**
- **Tests:** renombrar `FlowCrudTest`→`BotFlowCrudTest`, `FlowExecutorTest`→`BotFlowExecutorTest`; suite verde sin cambios de aserción.

---

## Fase 1 — Reestructura del modelo de datos (corte limpio)

Sin backfill: `migrate:fresh` o migraciones que sueltan/recrean FKs. Datos existentes descartables.

- **Migración `bot_flows`:** drop `agent_id` (+FK +índice); add `chatbot_id` (string 36, **nullable**, **unique**, FK→`chatbots` `nullOnDelete`) + índice. *(nullable: un Bot Flow puede ser borrador/plantilla sin AI Bot aún.)*
- **Migración `chatbots`:** drop `agent_id`, `agent_team_id` (+FKs).
- **Migración `channels`:** drop `agent_id`, `agent_team_id` (+FKs). El target se deriva ahora vía `chatbot → botFlow → roster`.
- **Migración `agents`:** drop `agent_team_id` (+FK). *(disolución de AgentTeam)*
- **Drop `agent_teams`** (+ modelo `AgentTeam`, `AgentTeamController`, rutas, vistas).
- **Modelos:**
  - `Chatbot`: quitar `agent()`/`agentTeam()`/`getTarget()`; añadir `botFlow(): HasOne` (`bot_flows.chatbot_id`).
  - `BotFlow`: quitar `agent()`; añadir `chatbot(): BelongsTo`.
  - `Agent`: quitar `flows()`/`activeFlow()`/`team()`; quitar `agent_team_id` de `$fillable`.
- **Tests:** migración aplica limpia; relaciones nuevas cubiertas en `BotFlowCrudTest`.

---

## Fase 2 — Autoría: el AI Bot posee su Bot Flow; agentes como nodos

- **Punto de entrada unificado:** crear un AI Bot crea un `Chatbot` + un `BotFlow` en blanco (`chatbot_id` seteado). Editar el AI Bot abre el **canvas del Bot Flow** (reusa el actual `pages/bot-flows/Edit.vue`) en ruta `chatbots/{chatbot}/flow/edit`.
- **Retirar** las rutas `/bot-flows` globales y `agents/{agent}/flows` (legacy por-agente). La autoría vive bajo el AI Bot.
- **`chatbots/Create.vue`:** eliminar el paso "Select Agent / Single Agent / Multi-Agent" (FKs ya no existen). Crear AI Bot = nombre + descripción + (luego) su Bot Flow.
- **Nodo `agent`:** añadir tipo de nodo `agent` al `definition` que **bincula un `agent_id` real** + rol (`triage`/`knowledge`/`action`). Reusa `createAgentForLayer()` (ya crea Agents tipados). Actualizar `ValidFlowDefinition::$validTypes` para incluir `agent`; nuevo `AgentNode.vue` (o repurpose `AgentHandoffNode.vue`).
- **`agent_handoff`:** `data.target_agent` (rol string) se mantiene, pero su **resolución** pasa a hacerse contra el **roster de nodos del Bot Flow**, no contra el team.
- **Tests:** `BotFlowCrudTest` (crear AI Bot ⇒ Bot Flow asociado), validación del nodo `agent` en `ValidFlowDefinition`.

---

## Fase 3 — Repunteo del runtime (el núcleo riesgoso)

Mover la resolución de agente de `Chatbot→Agent/Team` a `Chatbot→BotFlow→roster`.

- **Roster derivado del flow:** nuevo helper `BotFlow::roster()` que lee los nodos `agent` y devuelve `{triage, knowledge, action} => Agent`. Reemplaza `AgentTeam::triageAgent()/knowledgeAgent()/actionAgent()`.
- **`TeamOrchestrationService`:** firma `orchestrate(AgentTeam, ...)` → `orchestrate(BotFlow, ...)` (o un `BotFlowRoster`). Call sites a repuntear (del mapa de runtime):
  - `:52` cargar roster → desde `flow->roster()`.
  - `:54-62` `shouldActivateFlow($team->triageAgent, ...)` → `shouldActivateFlow($flow, ...)`.
  - `:181/:204/:274` consolidación/triage → `roster.triage`.
  - `:363` knowledge → `roster.knowledge`; `:415` action → `roster.action`.
  - `:492/:539` `activeFlow()` → el flow ya es conocido (es el del chatbot).
  - `:549-576` `emitFlowAction` role-map → resuelve contra `roster`.
- **`BotFlowExecutorService::shouldActivateFlow`:** firma `(Agent, ...)` → `(BotFlow $flow, ...)`. Elimina la dependencia de `Agent::activeFlow()`; el flow del chatbot se pasa directo y se evalúa el trigger de su nodo `start`.
- **Path widget/preview:** `Chatbot::getTarget()` desaparece. `ChatbotPreviewController:188-189` y `Api/Widget/ChatController` → `chatbot->botFlow` → si el roster tiene **1 agente** ⇒ comportamiento single-agent; si **varios** ⇒ orquestación. `WidgetStreamService::streamAgentResponse/streamTeamResponse` se unifican detrás del Bot Flow.
- **Path canal (WhatsApp):** el scope/target que hoy sale de `Channel.agent_id` → `channel->chatbot->botFlow->roster`.
- **Path agente standalone (`agents/{agent}/chat`):** ya **no** tiene Bot Flow (los flows pertenecen a chatbots). Queda como chat LLM directo. Quitar el branch de flow en `ChatStreamController:302` y `ProcessAgentChat:139` (`agent->activeFlow()`), que dejan de aplicar.
- **Tests:** `TeamOrchestrationTest` (roster desde el flow, handoff resuelto contra nodos), `ChatbotPreviewTest` (target = bot flow), widget tests (`Api/Widget/*`), `BotFlowExecutorTest`.

---

## Fase 4 — UI: editor de AI Bot + canvas de Bot Flow unificados; limpieza

- **Nav (`AppSidebar.vue`):** "Chatbots"→"AI Bots"; **eliminar** la entrada separada "Flows" (`nav.flows` + `FlowController.globalIndex`) — se pliega dentro de AI Bots.
- **Páginas chatbots:** relabel a "AI Bot"; `Create.vue` sin selector de agente/team; `Edit.vue`/`Show.vue` exponen el Bot Flow (link/embed al canvas).
- **Eliminar** `pages/bot-flows/GlobalIndex.vue`, `pages/bot-flows/Index.vue` (índice por-agente) y usos de `agents/{agent}/flows`.
- **Eliminar** UI de AgentTeam (selección de team, vistas de `AgentTeamController`).
- **Tests:** smoke de las páginas de AI Bot + canvas (sin errores JS).

---

## Fase 5 — Cierre

- Retirar restos de `AgentTeam` (modelo/migración/controlador/tests `AgentTeamTest`).
- Verificar que no queden referencias a `chatbot->agent`, `chatbot->agentTeam`, `agent->flows`, `flow->agent`, `Channel.agent_id` (grep limpio).
- Suite completa verde.

---

## Modelo de datos / migraciones

| Tabla | Cambio | Esquema | Estado |
|---|---|---|---|
| `flows` → `bot_flows` | rename de tabla | platform | ❌ |
| `bot_flows` | − `agent_id` · + `chatbot_id` (unique, nullable, FK) | platform | ❌ |
| `chatbots` | − `agent_id`, − `agent_team_id` (+FKs) | platform | ❌ |
| `channels` | − `agent_id`, − `agent_team_id` (+FKs) | platform | ❌ |
| `agents` | − `agent_team_id` (+FK) | platform | ❌ |
| `agent_teams` | **drop table** | platform | ❌ |

*(Todas platform — ninguna toca el split tenant/RLS. Corte limpio: sin backfill.)*

---

## Sub-decisiones residuales (resolver al implementar)

1. **`bot_flows.chatbot_id` nullable vs. obligatorio.** Propuesto **nullable** para permitir Bot Flows borrador/plantilla sin AI Bot. Alternativa: obligatorio (1:1 estricto, todo Bot Flow nace de un AI Bot).
2. **Single-agent sin orquestación.** Un Bot Flow con un solo nodo `agent` debe cortocircuitar a chat directo (sin pasar por `TeamOrchestrationService`), por rendimiento. Confirmar el umbral (1 agente ⇒ directo).
3. **`agents/{agent}/chat` standalone.** ¿Se conserva como chat LLM directo (recomendado) o se retira en favor de AI Bots?
4. **Nodo `agent` vs. `agent_handoff`.** ¿Un solo tipo `agent` (declara el roster) + edges de routing, o se mantiene `agent_handoff` como nodo de transferencia explícita? Define la gramática del canvas.

---

## Secuencia recomendada

**Fase 0 → 1 → 2 → 3 → 4 → 5.**

- **0** entrega un baseline renombrado y verde (mecánico, bajo riesgo).
- **1** voltea el modelo de datos (corte limpio, rápido).
- **2** mueve la autoría al AI Bot y formaliza los agentes como nodos.
- **3** es el costo de ingeniería: repuntar la resolución de agente y la orquestación del team al roster del flow — la fase a blindar con tests antes de tocar UI.
- **4-5** consolidan UI y retiran AgentTeam.
