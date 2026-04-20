# Design tokens → Tailwind

The brand tokens live in `styles/colors_and_type.css` as CSS custom properties (`--sp-*`). For the Vue/Tailwind project, **keep the CSS file and extend Tailwind to read from it.** That way a token change in the design system flows through without a rebuild.

## 1. Drop the CSS into your app

Copy `styles/colors_and_type.css` into `resources/css/tokens.css` and import it at the top of your main stylesheet (before Tailwind's base layer):

```css
/* resources/css/app.css */
@import "./tokens.css";
@import "tailwindcss";

@layer base {
  body { @apply bg-navy-deep text-white font-sans antialiased; }
}
```

Also copy `styles/app.css` (background treatments + a few things that are awkward in pure Tailwind — the `::before/::after` grid overlay) as `resources/css/admin.css` and import it in `AdminLayout.vue`.

## 2. Tailwind config fragment

Paste into `tailwind.config.ts` (Tailwind v4 uses `@theme` in CSS, v3 uses the JS config — pick the right one for your repo):

```ts
// tailwind.config.ts — Tailwind 3 form
import type { Config } from 'tailwindcss'

export default {
  content: ['./resources/**/*.{vue,ts,js}', './vendor/**/*.blade.php'],
  darkMode: 'class', // dark-first, but keep hook for future
  theme: {
    extend: {
      colors: {
        // surfaces
        'navy-deep':     'var(--sp-bg-primary)',    // #00031C — page
        'navy':          'var(--sp-bg-secondary)',  // #0B0F29 — cards
        'navy-elevated': 'var(--sp-bg-elevated)',   // #1a2255
        'navy-footer':   'var(--sp-bg-footer)',     // #000210

        // text
        'ink': {
          DEFAULT:   'var(--sp-text-primary)',   // #fff
          muted:     'var(--sp-text-secondary)', // #8890A6
          subtle:    'var(--sp-text-tertiary)',  // rgba(255,255,255,0.4)
          faint:     'rgba(255,255,255,0.25)',
        },

        // accent (chrome)
        'accent-blue': {
          DEFAULT: 'var(--sp-accent-blue)',       // #0096FF
          hover:   'var(--sp-accent-blue-hover)', // #007acc
        },
        'accent-cyan': 'var(--sp-accent-cyan)',   // #00FFFF

        // spectrum (three-layer brand story — use ONLY for narrative moments)
        'spectrum-magenta': 'var(--sp-spectrum-magenta)', // #D946EF — Understand / Sales
        'spectrum-cyan':    'var(--sp-spectrum-cyan)',    // #00E5FF — Discover / Shopping
        'spectrum-indigo':  'var(--sp-spectrum-indigo)',  // #4F46E5 — Resolve / Service

        // semantic
        'success': 'var(--sp-success)', // #22c55e
        'warning': 'var(--sp-warning)', // #f59e0b
        'danger':  'var(--sp-danger)',  // #ef4444
      },
      borderColor: {
        'soft':   'var(--sp-border-soft)',   // rgba(255,255,255,0.05)
        'medium': 'var(--sp-border-medium)', // rgba(255,255,255,0.10)
        'strong': 'var(--sp-border-strong)', // rgba(255,255,255,0.20)
      },
      borderRadius: {
        'xs':   'var(--sp-radius-xs)',   // 8px  — inputs, chips
        'sm':   'var(--sp-radius-sm)',   // 15px — floating cards, FAQ
        'md':   'var(--sp-radius-md)',   // 20px — benefit cards
        'lg':   'var(--sp-radius-lg)',   // 30px — value cards, CTA
        'pill': 'var(--sp-radius-pill)', // 50px — buttons, badges, inputs
      },
      fontFamily: {
        sans:    ['Poppins', 'system-ui', 'sans-serif'],
        display: ['Montserrat', 'Poppins', 'sans-serif'], // logo only
        mono:    ['JetBrains Mono', 'ui-monospace', 'monospace'],
      },
      fontSize: {
        // admin scale (smaller than marketing scale)
        '2xs': ['10px', { lineHeight: '1.3' }],
        'xs':  ['11px', { lineHeight: '1.4' }],
        'sm':  ['12px', { lineHeight: '1.5' }],
        'base':['13px', { lineHeight: '1.55' }],
        'md':  ['14px', { lineHeight: '1.55' }],
        'lg':  ['16px', { lineHeight: '1.4' }],
        'xl':  ['22px', { lineHeight: '1.15' }],
      },
      boxShadow: {
        'btn-primary': 'var(--sp-shadow-btn-primary)', // 0 0 20px rgba(0,150,255,0.30)
        'float':       'var(--sp-shadow-float)',
        'image':       'var(--sp-shadow-image)',
      },
      backdropBlur: {
        'glass': '10px',
      },
      spacing: {
        // Sapiensly section rhythm — rarely used in admin, keep for consistency
        '15': '60px',
        '20': '80px',
        '30': '120px',
      },
      animation: {
        'fade-up':   'sp-fade-up 0.3s ease',
        'scale-in':  'sp-scale-in 0.18s ease',
        'slide-in-right': 'sp-slide-in-right 0.22s ease',
        'toast-up':  'sp-toast-up 0.24s ease',
      },
      keyframes: {
        'sp-fade-up':   { '0%': { opacity: '0', transform: 'translateY(10px)' }, '100%': { opacity: '1', transform: 'translateY(0)' } },
        'sp-scale-in':  { '0%': { opacity: '0', transform: 'translate(-50%, -50%) scale(0.94)' }, '100%': { opacity: '1', transform: 'translate(-50%, -50%) scale(1)' } },
        'sp-slide-in-right': { '0%': { transform: 'translateX(100%)' }, '100%': { transform: 'translateX(0)' } },
        'sp-toast-up':  { '0%': { opacity: '0', transform: 'translate(-50%, 12px)' }, '100%': { opacity: '1', transform: 'translate(-50%, 0)' } },
      },
    },
  },
} satisfies Config
```

## 3. shadcn-vue theme variables

shadcn-vue generates CSS variables in `resources/css/app.css` under `@layer base { :root { ... } }`. Override them to point at Sapiensly tokens so every shadcn primitive picks up the brand automatically:

```css
@layer base {
  :root {
    --background: var(--sp-bg-primary);
    --foreground: var(--sp-text-primary);

    --card: var(--sp-bg-secondary);
    --card-foreground: var(--sp-text-primary);

    --popover: var(--sp-bg-secondary);
    --popover-foreground: var(--sp-text-primary);

    --primary: var(--sp-accent-blue);
    --primary-foreground: #fff;

    --secondary: rgba(255,255,255,0.04);
    --secondary-foreground: var(--sp-text-primary);

    --muted: rgba(255,255,255,0.03);
    --muted-foreground: var(--sp-text-secondary);

    --accent: rgba(0,150,255,0.10);
    --accent-foreground: #fff;

    --destructive: var(--sp-danger);
    --destructive-foreground: #fff;

    --border: var(--sp-border-soft);
    --input:  var(--sp-border-medium);
    --ring:   var(--sp-accent-blue);

    --radius: 0.875rem; /* 14px — card default */
  }
}
```

With these in place, `<Button>`, `<Card>`, `<Input>`, etc. render in Sapiensly colors without per-component overrides.

## 4. Class recipes

Common patterns used across the prototype, as Tailwind snippets:

| Prototype pattern | Tailwind |
|---|---|
| Default card | `bg-navy border border-soft rounded-sm` |
| Card on hover (lift + colored border + glow) | `transition hover:-translate-y-1 hover:border-accent-blue/30 hover:shadow-btn-primary` |
| Pill button primary | `inline-flex items-center gap-1.5 px-4 py-2 rounded-pill bg-accent-blue text-white font-medium shadow-btn-primary hover:-translate-y-0.5 transition` |
| Stat number | `font-mono text-xl font-semibold text-white` |
| Section eyebrow | `text-2xs uppercase tracking-wider text-ink-subtle font-semibold` |
| Sidebar nav item | `flex items-center gap-2.5 px-3 py-2 rounded-xs text-sm text-ink-muted hover:bg-white/5 hover:text-white` |
| Sidebar nav item (active) | add `bg-accent-blue/10 text-white relative before:content-[''] before:absolute before:left-0 before:top-2 before:bottom-2 before:w-0.5 before:bg-accent-blue` |
| Glass header | `bg-navy-deep/80 backdrop-blur-glass border-b border-soft` |
| Backdrop overlay | `bg-navy-deep/65 backdrop-blur-[4px]` |

## 5. Icon set

Install `lucide-vue-next`. Every icon in the prototype maps to a Lucide name:

| Prototype (custom) | Lucide (Vue) |
|---|---|
| `dashboard` | `LayoutDashboard` |
| `users` | `Users` |
| `shield` | `Shield` |
| `brain` | `Brain` |
| `cloud` | `Cloud` |
| `layers` | `Layers` |
| `sparkles` | `Sparkles` |
| `bot` | `Bot` |
| `db` | `Database` |
| `hd` | `HardDrive` |
| `zap` | `Zap` |
| `radio` | `Radio` |
| `cpu` | `Cpu` |
| `server` | `Server` |
| `lock` | `Lock` |
| `key` | `Key` |
| `eye` / `eye-off` | `Eye` / `EyeOff` |
| `plug` | `Plug` |
| `sliders` | `SlidersHorizontal` |
| `check2` | `Check` |
| `ban` | `Ban` |
| `alert` | `AlertTriangle` |
| `info` | `Info` |
| `mail` | `Mail` |
| `trash` | `Trash2` |
| `more` | `MoreVertical` |
| `trending` | `TrendingUp` |
| `activity` | `Activity` |
| `star` | `Star` |
| `refresh` | `RefreshCw` |
| `download` | `Download` |
| `search` | `Search` |
| `bell` | `Bell` |
| `plus` | `Plus` |
| `x` | `X` |
| `chevronRight` / `chevronDown` | `ChevronRight` / `ChevronDown` |
| `menu` | `Menu` |
| `logout` | `LogOut` |
| `back` | `ArrowLeftToLine` |
| `library` | `Library` |

Default size: `16` for nav, `14` for inline badges, `12` for small buttons.
