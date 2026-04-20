# Sapiensly Admin — Handoff

Implementation spec for porting the prototype to **Laravel + Inertia v2 + Vue 3 `<script setup lang="ts">` + Tailwind + shadcn-vue + reka-ui**.

Read `CLAUDE.md` first (it tells the agent how to operate). This file tells you *what* to build and *in what order*.

---

## 0. Target file layout

```
resources/
  js/
    Pages/Admin/
      Dashboard.vue
      Users/Index.vue
      Users/Show.vue              (or use a <Sheet> inside Index — see §3.2)
      Access.vue
      Ai/Defaults.vue
      Ai/Catalog.vue
      Ai/Usage.vue
      Cloud.vue
      Stack.vue
    Layouts/
      AdminLayout.vue             (sidebar + topbar + command palette)
    components/admin/
      Sidebar.vue
      Topbar.vue
      CommandPalette.vue
      StatCard.vue
      Sparkline.vue
      BigChart.vue
      StackedBar.vue
      HealthRow.vue
      LayerCard.vue               (3-layer tile — Understand/Discover/Resolve)
      AuditRow.vue
      UserTable.vue
      UserDetailSheet.vue
      InviteUserDialog.vue
      DeleteUserDialog.vue
      SettingsCard.vue            (icon + title + description + slot)
      ToggleRow.vue
      PostureRow.vue              (Access posture summary item)
      DriverChip.vue              (Anthropic/OpenAI/... label pill)
      ModelCatalogTable.vue
      StackGroup.vue
      StackItemCard.vue
      PageHeader.vue
      Toast.vue                   (or use shadcn-vue <Sonner>)
    lib/
      admin/icons.ts              (Lucide re-exports used by admin)
      admin/types.ts               (see data_contracts.md)
  css/
    admin.css                      (background treatments + anything that can't be Tailwind)
routes/
  admin.php                        (new route group)
app/Http/Controllers/Admin/
  DashboardController
  UserController
  AccessSettingsController
  AiModelController
  AiDefaultsController
  CloudSettingsController
  StackController
```

---

## 1. Migration order (recommended)

Do these in order. Each step is small enough to ship and review independently.

1. **Install deps + tokens** — shadcn-vue CLI init, bring Lucide, paste Tailwind config from `design_tokens.md`. Verify `bg-navy-deep text-white font-sans` renders like the prototype.
2. **AdminLayout + Sidebar + Topbar + CommandPalette** — empty page shell behind a feature flag (`/admin2`). Route + breadcrumbs + ⌘K wired, no content.
3. **Dashboard** — static first (hard-code stats from `seed.jsx`), then wire `DashboardController@index` returning real numbers.
4. **Users Index** — table, filters, row selection, pagination. Use existing `User` model + policy.
5. **User detail** — `<Sheet>` slide-over with security actions (resend verification, reset 2FA, impersonate, block, delete). Wire to existing endpoints.
6. **Access** — toggles for registration / email-verification / 2FA-required / IP allowlist + domain chips + session lifetime. These probably already map to existing config or a settings table.
7. **Global AI** — catalog tab reads from `ai_models` (or wherever models are registered). Defaults tab = platform config. Rotation action re-uses existing secret-manager endpoint.
8. **Global Cloud** — read-only status for S3 + Postgres + pgvector. Writes only if settings are editable in your current admin.
9. **Stack** — read from `config()` or a hard-coded composer (`php artisan about --json`).
10. **Cutover** — remove old admin routes, point `/admin` at new layout, delete `Pages/OldAdmin`.

---

## 2. Architecture decisions to make early

### 2.1 Inertia shared props
Expose these on every admin page via `HandleInertiaRequests`:

```ts
// resources/js/types/inertia.d.ts
interface AdminShared {
  currentUser: { id: number; name: string; email: string; role: 'sysadmin' | 'admin' | 'owner' }
  systemHealth: { id: string; status: 'ok' | 'warn' | 'error'; label: string; detail: string; lastCheck: string }[]
  flags: { registrationOpen: boolean; require2fa: boolean }
}
```

Sidebar + Topbar read these without the page passing them down.

### 2.2 Routing names (suggested)
```
admin.dashboard
admin.users.index | .show | .invite | .destroy | .block | .impersonate
admin.access.edit | .update
admin.ai.defaults | .catalog | .usage
admin.ai.models.toggle | .register | .rotate-key
admin.cloud
admin.stack
```

### 2.3 Forms
Every form uses `useForm()` from `@inertiajs/vue3`. No direct `axios` unless polling the dashboard (and even then, prefer `router.reload({ only: ['stats'] })` on an interval).

### 2.4 Command palette
Use shadcn-vue `<Command>` (built on reka-ui's listbox). Actions = array of `{ group, label, icon, perform }`. `perform` calls either `router.visit(...)` or a callback. Register page-local actions via `provide/inject` so each page can add its own commands when mounted.

### 2.5 Toasts
Use `vue-sonner` (shadcn-vue wraps it). Replace the custom `<Toast>` in the prototype.

### 2.6 Charts
Prototype uses inline SVG sparklines. For production keep SVG (no chart lib needed) — see `components/primitives.jsx` → `Sparkline` and `BigChart`. Port verbatim to Vue; they're ~30 lines each.

---

## 3. Things that need product decisions

Don't ship these without asking:

1. **Impersonation audit trail.** The prototype shows active impersonations but doesn't specify retention. Decide: session-scoped log vs permanent audit record.
2. **Default LLM key rotation.** Does "Rotate" create a new secret and invalidate old, or does it just open the secret-manager UI? Reuse existing flow if any.
3. **Deleting a user.** Soft-delete or hard-delete? Tenant data cascades? Prototype says "cannot be undone" — confirm that's accurate.
4. **Embeddings model swap.** The warning banner says this requires re-indexing. Is there an existing re-index job or does this need to be built?
5. **Domain allowlist.** Empty = open, or empty = reject all? Landing copy implies open; confirm with security team.
6. **Dashboard live updates.** Poll every 30s, WebSocket via Reverb, or manual refresh? Prototype shows a refresh button but no live updates.

---

## 4. What's intentionally not in the prototype

- Empty / loading / error states — add using shadcn-vue `<Skeleton>` and your existing error boundary
- Mobile breakpoints — admin is desktop-first (≥1024px). Collapse sidebar earlier on tablet, hide on phone, but don't re-design
- Internationalization — copy is English; hook up i18n once per-string when you port
- Search in Users that crosses organizations — backend query shape not specified
- Bulk CSV export — prototype has the button but no flow. Wire to a job + email-on-ready if needed

---

## 5. Acceptance per screen

See `port_checklist.md`. Each screen has 6–12 checks (visual + behavior + accessibility + integration).

---

## 6. Fidelity to the prototype

When in doubt, open `Sapiensly Admin.html` in a browser. The exact feel (spacing, hover lifts, backdrop blur, stat-card radial glow, pill buttons) matters — it's the Sapiensly brand signature applied to admin. Match it.

Where the prototype and your shadcn-vue default differ: **the prototype wins on visuals; shadcn-vue wins on a11y/keyboard behavior.** Override shadcn class variants to match brand tokens; don't rebuild the primitive.
