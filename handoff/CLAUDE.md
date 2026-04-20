# Claude Code — read this first

You are implementing a new admin UI in an existing Laravel + Inertia + Vue 3 + TypeScript app. The design is **fully specified in `handoff/`** — use it as the source of truth. Do not improvise visual decisions.

## Stack you are working with

- **Backend**: Laravel (existing — models, controllers, DB, Horizon, pgvector, Prism already in place)
- **Frontend**: Inertia v2 + Vue 3 `<script setup lang="ts">` + TypeScript
- **Styling**: Tailwind CSS
- **Components**: `shadcn-vue` (primary) + `reka-ui` (underlying primitives for anything shadcn-vue doesn't cover yet)
- **Icons**: use `lucide-vue-next` — the design relies on Lucide-style line icons (2px stroke, rounded joins)
- **Routing**: Ziggy (`route('admin.users.index')` etc.)

## What this replaces

An existing admin UI. When you find the current admin pages (likely `resources/js/Pages/Admin/*` or similar), read them first to understand:
- Current Inertia page shape + props contract
- Layout component in use (replace or extend — ask if unsure)
- Auth / policy gates already applied
- Existing route names in `routes/web.php` / `routes/admin.php`

**Do not delete the old admin in one pass.** Build the new pages alongside (e.g. `Pages/Admin2/`) or behind a feature flag, then cut over screen by screen after review.

## How to read the handoff

| File | What it is | When to read |
|---|---|---|
| `handoff/HANDOFF.md` | Migration plan, order of work, conventions | **First** |
| `handoff/component_map.md` | JSX prototype → Vue SFC mapping (one row per component) | When porting a component |
| `handoff/data_contracts.md` | Inertia prop shapes + TS interfaces per page | When wiring controllers to pages |
| `handoff/design_tokens.md` | Token → Tailwind class translation + `tailwind.config.ts` fragment | Once at setup; re-reference for color/spacing decisions |
| `handoff/port_checklist.md` | Per-screen acceptance checklist | Before marking a screen complete |
| `Sapiensly Admin.html` + `components/*.jsx` | Live prototype + source JSX | Visual/behavioral reference — open in browser to see exactly how interactions feel |
| `styles/colors_and_type.css` | Brand token file (Sapiensly design system) | Keep intact. Tailwind reads tokens from here. |

## Ground rules

1. **Tokens over hex.** Every color, radius, shadow comes from `colors_and_type.css` via Tailwind. Never paste `#0096FF` into a `.vue` file — use `text-accent-blue` or similar.
2. **shadcn-vue first, reka-ui second, custom third.** Reach for a shadcn primitive before hand-rolling. Button, Dialog, Dropdown, Command, Table, Tabs, Switch, Sheet, Toast, Tooltip — all exist. Install the ones you need with the shadcn-vue CLI.
3. **Dark-first.** This UI is dark-only. Use Tailwind's dark-palette directly (slate-950 equivalents via tokens) — do not add a `dark:` prefix, do not implement a light mode unless asked.
4. **Voice matches the landing.** When copy needs to be written, match Sapiensly's tone: short, declarative, "negate-then-assert", em-dashes, no emoji, no "seamlessly". See the brand guide in `styles/colors_and_type.css` header comment.
5. **Inertia over fetch.** Form submissions use `router.post` / `useForm`; page data comes from controller props. Don't add client-side API calls unless the endpoint genuinely needs polling (dashboard live stats).
6. **Stop and ask** when:
   - You can't find a prop in the existing backend and the shape isn't obvious from `data_contracts.md`
   - A design element has no clear Tailwind/shadcn analogue
   - You're about to introduce a new dependency

## What NOT to do

- Don't convert the JSX verbatim. It uses inline styles and a bespoke icon set — that's prototype convenience, not production style. Ports go through Tailwind + Lucide.
- Don't re-implement primitives shadcn-vue already ships.
- Don't add new brand colors. The spectrum (magenta / cyan / indigo) is reserved for the three-layer story; accent-blue is UI chrome. Never leak spectrum colors into generic UI.
- Don't change token values. The design system is version-controlled in the sibling repo.

## Suggested first move

Read `handoff/HANDOFF.md` end-to-end. Then open `Sapiensly Admin.html` in a browser to see the prototype live. Then propose a plan (files you'll touch, order, feature flag strategy) before writing code.
