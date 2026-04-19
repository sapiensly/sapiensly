# WhatsApp channel

Sapiensly exposes WhatsApp Business via the Meta Cloud API. Each WhatsApp number
is a `Channel` (`channel_type = whatsapp`) that routes inbound messages through
the same `Agent` / `AgentTeam` / `Flow` orchestration as the web widget.

## High-level architecture

```
┌────────────────┐    webhook POST     ┌─────────────────────────────┐
│   Meta Cloud   │ ─────────────────► │ WhatsAppWebhookController     │ 200 OK
└────────────────┘                     └──────────────┬──────────────┘
                                                      │  dispatches
                                                      ▼
                                   ┌───────────────────────────────────┐
                                   │ ProcessWhatsAppWebhookJob          │
                                   │  • idempotent by wamid             │
                                   │  • opt-out detection               │
                                   │  • status progression (monotonic)  │
                                   └─────────────┬─────────────────────┘
                                                 │
                                                 ▼
                                   ┌───────────────────────────────────┐
                                   │ GenerateWhatsAppReplyJob           │
                                   │  • cache lock per conversation     │
                                   │  • escalated → skip                │
                                   └─────────────┬─────────────────────┘
                                                 │
                                                 ▼
                                   ┌───────────────────────────────────┐
                                   │ WhatsAppReplyOrchestrator          │
                                   │  synthetic Conversation bridge     │
                                   │  → LLMService / Team / Flow        │
                                   └─────────────┬─────────────────────┘
                                                 │
                                                 ▼
                                   ┌───────────────────────────────────┐
                                   │ WhatsAppMessageSender              │
                                   │  compliance gates (24h, opt-out,   │
                                   │  paused channel), chunking, queue  │
                                   └─────────────┬─────────────────────┘
                                                 │
                                                 ▼
                                     SendWhatsAppMessageJob → Meta Graph
```

## Onboarding a number

1. Create a WhatsApp Business App in Meta Business Manager and add a phone number.
2. Collect: phone number ID, business account ID, access token, app ID, app secret.
3. In Sapiensly go to **System → WhatsApp → New connection** and fill the form.
4. Copy the **Webhook URL** and **Verify token** shown on the detail page.
5. In Meta Business Manager configure the webhook: paste URL, paste verify token,
   subscribe to `messages` field.
6. Meta calls `GET /webhooks/whatsapp/{id}` — `WhatsAppWebhookController::verify`
   responds with the challenge if the verify token matches (constant-time compare).
7. From then on inbound `POST` webhooks land on the same URL, signed with
   `X-Hub-Signature-256` (HMAC-SHA256 of the raw body against `app_secret`).

## Key invariants

- **Idempotency**: `whatsapp_messages.wamid` is unique. Duplicate webhook
  deliveries are silently ignored.
- **Monotonic status**: `WhatsAppMessage::advanceStatusTo()` refuses downgrades
  (`delivered → sent` no-ops, `failed` is terminal).
- **Session window (24h)**: `WhatsAppMessageSender` blocks free-form outbound
  text when `contact.last_inbound_at` is older than 24 hours. Templates bypass
  this rule.
- **Opt-out**: keywords `STOP`, `UNSUBSCRIBE`, `BAJA`, `PARAR`, `CANCELAR` set
  `contacts.opted_out_at`; subsequent outbounds raise `WhatsAppSendException`.
- **Takeover**: `ConversationStatus::Escalated` suppresses auto-reply until a
  human releases the thread via the inbox UI.
- **Tenant scope**: tenant-aware via parent `Channel` (owns `organization_id` +
  `visibility`). `WhatsAppConnectionPolicy` and `WhatsAppConversationPolicy`
  delegate to the channel's visibility.

## Credentials & encryption

`whatsapp_connections.auth_config` is cast as `encrypted:array`. The model
`$hidden` list prevents serialization. `maskedAuthConfig()` produces UI-safe
projections (last 4 chars only). Access tokens are never written to logs.

## Queues

- `whatsapp-webhooks`: ProcessWhatsAppWebhookJob, DownloadWhatsAppMediaJob.
  High priority — Meta media URLs expire in 5 minutes.
- `whatsapp-outbound`: GenerateWhatsAppReplyJob, SendWhatsAppMessageJob. Reply
  concurrency per conversation is serialised via `Cache::lock('wa_reply_{id}')`.

Horizon supervisors are configured in `config/horizon.php`
(`supervisor-whatsapp-webhooks`, `supervisor-whatsapp-outbound`).

## Rate limiting

Inbound webhook POST is throttled at 600 req/min **per connection** (Meta
rotates source IPs). Configured in `AppServiceProvider::boot()` via
`RateLimiter::for('whatsapp-webhook')`.

## Testing

~40 Pest tests cover webhook verify + signature, inbound parsing + idempotency,
status progression, outbound gates, orchestrator bridge, controllers, and
takeover/reply flows. Run them with:

```
php artisan test --compact tests/Feature/WhatsApp tests/Feature/Channels
```

## Out of scope (MVP)

- Interactive messages (buttons, lists).
- Template submission via Graph API (tenants create templates in Meta Business
  Manager, only the approved metadata is mirrored here).
- Twilio driver (contract ready, impl pending).
- Broadcast lists, catalogs, WhatsApp Pay, stickers/reactions outbound.
- SLA, assignment, tags, routing in inbox.
