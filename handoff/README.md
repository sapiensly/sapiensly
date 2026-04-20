# Sapiensly Admin — handoff package

Drop this folder into your Laravel repo (e.g. at `docs/admin-handoff/`) and point Claude Code at it.

## What's inside

| File | Audience | Purpose |
|---|---|---|
| `CLAUDE.md` | Claude Code | Ground rules + "read this first" pointer. Copy to the Laravel repo root. |
| `HANDOFF.md` | You (PM) + Claude Code | Migration plan, file layout, decisions you still need to make |
| `design_tokens.md` | Claude Code | Tailwind + shadcn-vue config derived from the brand token file |
| `component_map.md` | Claude Code | One row per prototype component → Vue SFC + which shadcn primitive to use |
| `data_contracts.md` | Claude Code + backend | TypeScript types for every Inertia page's props |
| `port_checklist.md` | Reviewers | Per-screen acceptance checklist |

## Also in this project (reference material)

- `Sapiensly Admin.html` — live prototype. Open in a browser while porting.
- `components/*.jsx` — prototype source. Treat as behavior spec, not code to copy.
- `styles/colors_and_type.css` — brand token file. Copy to `resources/css/tokens.css`.
- `styles/app.css` — background treatments + animations the prototype relies on.
- `assets/` — logos + decor SVGs the design uses.

## Recommended workflow

1. Copy `handoff/CLAUDE.md` to the Laravel repo root as `CLAUDE.md` (or merge into the existing one).
2. Copy `styles/colors_and_type.css` → `resources/css/tokens.css`.
3. Copy `styles/app.css` → `resources/css/admin.css`.
4. Copy the `assets/` folder into `resources/images/admin/`.
5. Open Claude Code, point it at `docs/admin-handoff/HANDOFF.md`, and let it propose a plan before writing code.

## Things Claude Code should NOT try to guess

See the "Things that need product decisions" section in `HANDOFF.md` — six open questions (impersonation retention, key rotation UX, delete semantics, embeddings re-index, domain allowlist defaults, dashboard live-update transport). Answer these before porting the relevant screen.
