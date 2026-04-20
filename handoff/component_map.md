# JSX component → Vue SFC map

One row per component. Columns:
- **JSX** — source file in prototype
- **Vue SFC** — suggested new file
- **Built on** — shadcn-vue / reka-ui / pure Tailwind
- **Notes** — anything non-obvious

---

## Shell

| JSX | Vue SFC | Built on | Notes |
|---|---|---|---|
| `components/shell.jsx::Sidebar` | `components/admin/Sidebar.vue` | Pure Tailwind + Lucide | Collapses to 64px. Active item has a 2px left bar (`before:` pseudo). Use `<RouterLink>` / Inertia `<Link>` with `href={route('admin.dashboard')}`. |
| `components/shell.jsx::Topbar` | `components/admin/Topbar.vue` | shadcn-vue `<Button>` + pure Tailwind | Sticky, `backdrop-blur-glass`. Breadcrumb is static (`Admin / {title}`). Density select can use shadcn `<Select>`. |
| `components/shell.jsx::CommandPalette` | `components/admin/CommandPalette.vue` | shadcn-vue `<Command>` (Dialog + Command combo) | Register actions via `provide/inject` or a Pinia store so each page can add its own. ⌘K hotkey in AdminLayout.vue root. |

## Primitives (most map to shadcn-vue directly — don't rebuild)

| JSX | Vue SFC | Built on | Notes |
|---|---|---|---|
| `Icon` | — (delete) | `lucide-vue-next` | Replace `<Icon name="x" />` with `<X class="size-3.5" />`. |
| `LogoMark` | `components/admin/LogoMark.vue` | Inline SVG | Paste the path from `assets/sapiensly-icon-white.svg`. |
| `Avatar` | shadcn-vue `<Avatar>` | shadcn-vue | Use `<AvatarFallback>` for initials. Hash-pick from the spectrum. |
| `Badge` | shadcn-vue `<Badge>` | shadcn-vue | Variants: `default` / `success` / `warning` / `danger` / `soft-neutral`. Extend shadcn's variant config in `components/ui/badge/index.ts`. |
| `Button` (`.btn`, `.btn-primary`, etc.) | shadcn-vue `<Button>` | shadcn-vue | Add a `variant="primary"` that maps to `bg-accent-blue shadow-btn-primary rounded-pill`. |
| `Toggle` | shadcn-vue `<Switch>` | shadcn-vue | Override color to `data-[state=checked]:bg-accent-blue`. |
| `Modal` | shadcn-vue `<Dialog>` | shadcn-vue | Keep `rounded-sm` (15px) on `<DialogContent>`. |
| `ConfirmDialog` | shadcn-vue `<AlertDialog>` | shadcn-vue | Danger variant = `bg-danger` on the confirm button. |
| `Toast` | `vue-sonner` via shadcn-vue `<Sonner>` | shadcn-vue | Replace custom animation with Sonner's default. |
| `Tabs` | shadcn-vue `<Tabs>` | shadcn-vue | Style `<TabsTrigger>` with `data-[state=active]:border-b-accent-blue data-[state=active]:text-white`. |
| `Segmented` | reka-ui `<ToggleGroup>` (type single) | reka-ui | shadcn-vue wraps this as `<ToggleGroup>`. |
| `Alert` | shadcn-vue `<Alert>` | shadcn-vue | Variants: info / warning / danger / success. |
| `Field` | pure Tailwind | — | 5-line wrapper: label + slot + optional help. Don't abstract further. |

## Charts (keep custom — no lib needed)

| JSX | Vue SFC | Built on | Notes |
|---|---|---|---|
| `Sparkline` | `components/admin/Sparkline.vue` | Inline SVG | ~30 lines. Port verbatim; replace inline styles with Tailwind where possible but SVG attrs stay. |
| `BigChart` | `components/admin/BigChart.vue` | Inline SVG | Same. Gridlines + area + stroke + end-dot. |
| `StackedBar` | `components/admin/StackedBar.vue` | Flex divs | Trivial. |

## Dashboard widgets

| JSX | Vue SFC | Built on | Notes |
|---|---|---|---|
| `StatCard` | `components/admin/StatCard.vue` | Tailwind + `<Sparkline>` | Has the radial-glow corner. Props: `label, value, caption?, delta?, deltaDir?, series?, icon, color`. |
| `LayerCard` (inlined in `dashboard.jsx`) | `components/admin/LayerCard.vue` | Tailwind | Three-layer tile. `color` prop drives border + glow + count text. |
| `HealthRow` (inlined) | `components/admin/HealthRow.vue` | Tailwind | 30×30 icon tile + label + detail + dot + timestamp. |
| `AuditRow` (inlined) | `components/admin/AuditRow.vue` | Tailwind | Actor in white, action in muted, target in accent-blue. |

## Users

| JSX | Vue SFC | Built on | Notes |
|---|---|---|---|
| `UsersScreen` | `Pages/Admin/Users/Index.vue` | shadcn-vue `<Table>` + `<Input>` + `<Select>` | Filters in a row above table. Use Inertia's `router.reload({ only: ['users'] })` with `preserveState` on filter change. |
| Selection bar | Inline in `Index.vue` | Tailwind | Shows when `selection.size > 0`. |
| `UserTable` | `components/admin/UserTable.vue` | shadcn-vue `<Table>` | Extract the table only. Parent owns filters + selection state. |
| `UserDetail` (slide-over) | `components/admin/UserDetailSheet.vue` | shadcn-vue `<Sheet>` (side="right") | Actions emit events; parent handles confirm dialogs. |
| `InviteDialog` | `components/admin/InviteUserDialog.vue` | shadcn-vue `<Dialog>` + `<Input>` + `<Select>` | Use `useForm({ email, role })`. |
| `ConfirmDialog` (delete) | shadcn-vue `<AlertDialog>` | shadcn-vue | See primitives row. |

## Access

| JSX | Vue SFC | Built on | Notes |
|---|---|---|---|
| `AccessScreen` | `Pages/Admin/Access.vue` | shadcn-vue `<Switch>` + `<Input>` | Each toggle hits `PATCH admin.access.update` with the single key that changed. Use `router.patch({ only: ['settings'] })`. |
| Domain allowlist chips | Inline | Tailwind | Enter-to-add, x-to-remove. Pure array state. |
| `PostureRow` | `components/admin/PostureRow.vue` | Tailwind | Green check or amber warning + optional "fix" button that calls back up. |

## Global AI

| JSX | Vue SFC | Built on | Notes |
|---|---|---|---|
| `GlobalAI` | `Pages/Admin/Ai/Defaults.vue` + `Ai/Catalog.vue` + `Ai/Usage.vue` | shadcn-vue `<Tabs>` | Each tab is a separate Inertia route for deep-linking. The tab list is in a shared partial. |
| `DefaultsPane` | Content of `Ai/Defaults.vue` | shadcn-vue `<Select>` + `<Input>` + `<Switch>` | Key reveal toggle is local state. "Test connection" hits `POST admin.ai.test` and toasts the result. |
| `CatalogPane` | Content of `Ai/Catalog.vue` | shadcn-vue `<Table>` + `<Switch>` + `<ToggleGroup>` | Filter (chat/embeddings) via ToggleGroup. |
| `UsagePane` | Content of `Ai/Usage.vue` | `<BigChart>` + `<StackedBar>` | Reads from `ai_usage` or similar aggregated table. |
| `DriverChip` | `components/admin/DriverChip.vue` | Tailwind | Hard-code the driver colors (anthropic #d97757, openai #10a37f, etc.). |
| `SettingsCard` | `components/admin/SettingsCard.vue` | Tailwind | Reused by Access + Cloud + AI-defaults. Icon tile + title + desc + badge slot + body slot. |

## Global Cloud

| JSX | Vue SFC | Built on | Notes |
|---|---|---|---|
| `GlobalCloud` | `Pages/Admin/Cloud.vue` | `<SettingsCard>` × 2 | Storage + Database, side-by-side on wide screens. |
| Capacity bar | Inline | Tailwind | `<div class="h-1.5 bg-white/6 rounded-pill overflow-hidden">` with inner `width: 87%`. |
| pgvector status block | Inline | Tailwind | 3-column stats (indexes / vectors / size). |

## Stack

| JSX | Vue SFC | Built on | Notes |
|---|---|---|---|
| `StackScreen` | `Pages/Admin/Stack.vue` | `<StackGroup>` × N | Data from `php artisan about --json` + a few hand-curated rows. |
| `StackGroup` | `components/admin/StackGroup.vue` | Tailwind | Section header + grid of item cards. |
| `StackItemCard` | `components/admin/StackItemCard.vue` | Tailwind | Icon tile + name + version + desc + status dot. |
