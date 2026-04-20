# Port checklist

Per-screen acceptance. Check every box before marking a screen done in the migration tracker.

## Global (applies to every screen)

- [ ] Page uses `AdminLayout.vue` — no rebuilt sidebar/topbar
- [ ] All colors come from Tailwind tokens; no hex in `.vue` files
- [ ] Icons are `lucide-vue-next`, not inline SVG
- [ ] Density toggle (comfortable/dense) responds — verify by switching once
- [ ] ⌘K opens the command palette from this page
- [ ] Page has a route name in Ziggy (`route('admin.xxx')` returns a URL)
- [ ] Typography uses `font-sans` for UI, `font-mono` for numerals in stat cards
- [ ] No `console.error` on mount; no Vue warnings in dev
- [ ] Authorized by `SysadminMiddleware` — non-sysadmin gets 403

## Dashboard

- [ ] 4 StatCards render (Users / Active today / AI calls / Error rate)
- [ ] Each StatCard shows sparkline + delta pill colored by direction
- [ ] Three-layer row renders in magenta / cyan / indigo (spectrum colors)
- [ ] Usage chart has gridlines + area fill + stroke + end dot
- [ ] System health list shows last-check timestamp and status dot
- [ ] Audit list links each target to the relevant screen (`admin.users.show`, etc.)
- [ ] Refresh button triggers `router.reload()` without full page nav
- [ ] Empty state on audit when no entries

## Users

- [ ] Table header row is sortable (name, last-seen, created-at)
- [ ] Filters reload via `router.reload({ only: ['users'], preserveState: true })`
- [ ] Row checkbox selects; "select all" checkbox exists
- [ ] Selection bar appears when any row selected, with bulk actions
- [ ] Clicking a row opens `<UserDetailSheet>` (not a full-page nav)
- [ ] Sheet actions: resend verification, reset 2FA, impersonate, block, delete
- [ ] Delete opens `<AlertDialog>` — confirm requires typing the email
- [ ] Block toggles status in place, no reload
- [ ] Invite dialog validates email, selects role, submits via `useForm`
- [ ] Invited users show a "resend invite" action in the sheet

## Access

- [ ] Every toggle PATCHes one key — no "save" button
- [ ] Optimistic UI: toggle flips immediately, reverts on server error with a toast
- [ ] Domain allowlist: Enter adds, X removes, duplicates rejected silently
- [ ] Session-lifetime input in minutes; min 15, max 10080 (1 week)
- [ ] Posture row shows green check OR amber warning with a "fix" button
- [ ] Toggling `twoFactorRequired` on shows a warning modal listing users without 2FA

## Global AI

- [ ] Tabs: Defaults / Catalog / Usage — each is a separate Inertia route
- [ ] Defaults: primary chat + embeddings + fallback selectable from enabled models only
- [ ] Temperature slider + numeric input stay in sync
- [ ] Key list shows masked key, last rotated, last used
- [ ] "Rotate" opens confirmation; success toasts with new masked value
- [ ] "Test connection" calls `POST admin.ai.test`, shows spinner, toasts result
- [ ] Catalog table filters by kind (chat/embedding/vision/reasoning) via ToggleGroup
- [ ] Enable/disable toggle on catalog row hits `admin.ai.models.toggle`
- [ ] Embedding model swap warns about re-indexing
- [ ] Usage tab: date-range picker works, chart re-renders, totals update

## Global Cloud

- [ ] Storage card shows driver + bucket + region + capacity bar (used/total)
- [ ] Capacity bar color shifts to warning when >80%, danger when >95%
- [ ] Database card shows engine + version + size + connection usage
- [ ] pgvector section shows enabled state, version, index count, vector count
- [ ] Per-index table: name / table / dim / metric / row count
- [ ] If pgvector disabled, section collapses to a single "not enabled" row with install hint

## Stack

- [ ] Groups render in order: Runtime / Frontend / Data / AI / Infra
- [ ] Each item shows name + version + status dot + description
- [ ] `outdated` items have an amber dot + tooltip showing latest version
- [ ] `missing` items have a red dot + link to install docs
- [ ] Data comes from `php artisan about --json` + curated augmentation

## Command palette

- [ ] ⌘K opens, Esc closes, ⌘K again toggles
- [ ] Navigation group: every sidebar destination
- [ ] Users group: most-recent 5 users
- [ ] Actions group: "Invite user", "Rotate API key", "Run health check"
- [ ] Typing filters across groups
- [ ] Arrow keys + Enter work without touching the mouse
- [ ] Uses shadcn-vue `<Command>` (reka-ui under the hood) — a11y labels correct
