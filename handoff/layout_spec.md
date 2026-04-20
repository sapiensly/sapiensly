# Layout & background spec

Exact specification for page layout, background treatments, and spacing rhythm. Pair this with `design_tokens.md` (colors/type) and `component_map.md` (components).

## 1. Page chrome — layout grid

```
┌─────────────────────────────────────────────────────────────────────┐
│ body  (bg: #00031C, full viewport)                                  │
│ ┌─────────┬───────────────────────────────────────────────────────┐ │
│ │ Sidebar │ Main                                                  │ │
│ │ 240px   │ ┌───────────────────────────────────────────────────┐ │ │
│ │ (64px   │ │ Topbar — sticky, 56px tall, backdrop-blur(10px)   │ │ │
│ │ when    │ ├───────────────────────────────────────────────────┤ │ │
│ │ colla-  │ │ Content                                           │ │ │
│ │ psed)   │ │ max-width: 1440px, centered, 22px/28px padding    │ │ │
│ │         │ │                                                   │ │ │
│ │ fixed   │ │                                                   │ │ │
│ │ height  │ │                                                   │ │ │
│ │ 100vh   │ │                                                   │ │ │
│ │         │ │                                                   │ │ │
│ └─────────┴─┴───────────────────────────────────────────────────┴─┘ │
└─────────────────────────────────────────────────────────────────────┘
```

### Exact values

| Element | Value | Notes |
|---|---|---|
| **Sidebar width** | 240px expanded / 64px collapsed | Transition 180ms ease |
| **Sidebar bg** | `bg-navy` (#0B0F29) with 80% opacity + `backdrop-blur-glass` | Sticky: `position: sticky; top: 0; height: 100vh` |
| **Sidebar right border** | 1px solid `border-soft` | |
| **Sidebar inner padding** | 16px 12px | |
| **Nav item** | 36px tall, 8px 12px padding, 8px border-radius, 10px icon gap | |
| **Nav item active** | `bg-accent-blue/10` + 2px left accent bar (`before:` pseudo) | Bar is 2px wide, inset 8px top/bottom |
| **Topbar height** | 56px | |
| **Topbar bg** | `bg-navy-deep/80` + `backdrop-blur-glass` | Border-bottom 1px `border-soft` |
| **Topbar padding** | 0 24px | |
| **Content max-width** | 1440px | Center with `mx-auto` |
| **Content padding** | 22px top/bottom, 28px left/right | |
| **Content vertical rhythm** | 24px between major sections, 16px within a card | |
| **Card** | `bg-navy` + 1px `border-soft` + `rounded-sm` (15px) | Inner padding 20px |
| **Card on hover** | `-translate-y-1` + `border-accent-blue/30` + `shadow-btn-primary` | 180ms ease |

## 2. Background treatments

The body has a `data-bg` attribute that picks one of four treatments. **Default is `blueprint`** — that's what the landing uses and what admin should use.

All four are implemented as `body::before` and `body::after` pseudo-elements (fixed, `inset: 0`, `pointer-events: none`, `z-index: 0`). `#app` sits at `z-index: 1`.

### Treatment A — `blueprint` (default, use this)

**Two layers stacked:**

1. **Radial glow** (`::before`):
   - Primary: `radial-gradient(ellipse 80% 50% at 60% -10%, rgba(0,150,255,0.15), transparent 60%)` — wide blue glow from top-right edge, pushed slightly off-screen so the brightest point is just above the viewport
   - Secondary: `radial-gradient(ellipse 60% 40% at 0% 100%, rgba(79,70,229,0.08), transparent 60%)` — subtle indigo glow from bottom-left

2. **Grid overlay** (`::after`):
   - `background-image: linear-gradient(rgba(255,255,255,0.03) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,0.03) 1px, transparent 1px)`
   - `background-size: 50px 50px` — 50px square grid
   - `mask-image: radial-gradient(ellipse 80% 60% at 50% 30%, #000, transparent 90%)` — grid fades out toward the edges so it reads as atmospheric rather than literal

**Effect:** a deep-space dashboard feel. The grid is barely visible (3% white lines) but gives the dark field texture; the glow pulls the eye toward the top of the page.

### Treatment B — `flat`
Pure `#00031C`. No decoration. Use when content is already visually dense (e.g. a complex chart page) and the background would compete.

### Treatment C — `spectrum`
Three tinted glows from different corners (magenta top-right, cyan bottom-left, indigo center). Use **sparingly** — only for narrative pages where the three-layer brand story is front and center. Not for generic admin.

### Treatment D — `subtle`
Just the top radial blue glow at 8% opacity. No grid, no secondary glow. Use for pages where a single content column dominates and you want minimal ambient light.

### Copy-paste CSS

The exact CSS for all four lives in `styles/app.css` lines 23–57. Claude Code should copy that block verbatim into `resources/css/admin.css` — it's self-contained and token-free (the rgba values are intentional fixed overlays, not brand tokens).

## 3. Z-index stack

| Layer | z-index | Example |
|---|---|---|
| Background glows/grid | 0 | `body::before/after` |
| App chrome | 1 | `#app` root |
| Sticky header / sidebar | 10 | `<Topbar>`, `<Sidebar>` |
| Dropdowns / popovers | 50 | shadcn `<Popover>` default |
| Sheets / modals | 100 | shadcn `<Sheet>`, `<Dialog>` |
| Command palette | 150 | Above modals because it can be opened from one |
| Tweaks panel (dev only) | 250 | Dev chrome, above everything |
| Toasts | 300 | Always on top |

## 4. Spacing rhythm

Tailwind spacing scale maps directly to CSS pixels (×0.25rem = ×4px). The prototype uses these heavily:

| Use | Tailwind | Pixels |
|---|---|---|
| Within a tight group (icon + label) | `gap-2` | 8px |
| Within a row of controls | `gap-3` | 12px |
| Between related cards in a grid | `gap-4` | 16px |
| Between unrelated sections on a page | `gap-6` | 24px |
| Between major page regions | `gap-10` | 40px |
| Card inner padding | `p-5` | 20px |
| Page content padding | `px-7 py-6` | 28px / 22px |

## 5. Grid patterns by page

| Page | Grid |
|---|---|
| Dashboard — stat row | `grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4` |
| Dashboard — three-layer row | `grid-cols-1 md:grid-cols-3 gap-4` |
| Dashboard — chart + side-column | `grid-cols-1 xl:grid-cols-[1fr_360px] gap-4` |
| Users — table | Full width, no grid. Filters row `flex gap-3 flex-wrap`. |
| Access — settings cards | `grid-cols-1 lg:grid-cols-2 gap-4` |
| AI Defaults — model selectors | `grid-cols-1 lg:grid-cols-3 gap-4` |
| Cloud — storage + database | `grid-cols-1 xl:grid-cols-2 gap-4` |
| Stack — items within a group | `grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3` |

## 6. Breakpoints

Dark-first, desktop-first. No mobile design has been done — but:

- `<1024px` → sidebar auto-collapses to 64px
- `<768px` → sidebar becomes an overlay (off-canvas); topbar grows a hamburger
- `<640px` → stat grids collapse to single column

Don't invent mobile layouts. If the user asks for mobile polish, that's a follow-up project.

## 7. Motion

| Where | What | Duration | Easing |
|---|---|---|---|
| Card hover | `translate-y` + border color + shadow | 180ms | ease |
| Sidebar collapse | Width only — no content fade | 180ms | ease |
| Sheet open (user detail) | Slide from right | 220ms | ease |
| Dialog open | Fade + scale from 0.94 | 180ms | ease |
| Toast in | Fade + rise 12px from bottom | 240ms | ease |
| Command palette open | Fade + scale from 0.96 | 160ms | ease |

No spring physics. No parallax. No scroll-tied animation in admin.

## 8. What to look at for reference

Open `Sapiensly Admin.html` in a browser and use the **Tweaks panel** (bottom-right) to cycle through the four background treatments live. That's the fastest way to understand the visual difference — the spec above describes them, but seeing them side-by-side on the same page is more informative.

Also useful: inspect element on any card in the prototype — the inline styles reveal exact padding/radius/shadow. The port takes those values to Tailwind classes per `design_tokens.md`.
