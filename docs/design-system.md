# Haman Planner design system

One stylesheet — `public/css/haman.css` — serves the planner app, account/billing/admin pages and the auth pages.
Components use tokens only; layouts add structure, not colours. RTL/LTR share the same rules through logical
properties (`inset-inline-*`, `margin-inline-*`, `padding-inline-*`).

| Token group | Values |
|---|---|
| Brand | `--ink-900` #0f1b2d (primary buttons, headings), `--accent` #4a57d0 (active navigation, links, focus, progress) |
| Meaning | success #147a45 · warning #a35a04 · danger #b42318 · info #1f5fb8 — each with a `-bg` and `-line` tint |
| Neutrals | `--bg` #f5f6f8, `--surface` #fff, `--surface-2/3`, `--line`, `--text`, `--text-2`, `--text-3` |
| Type | Vazirmatn (fa) / Poppins (en) via `--font`; 11 / 12.5 / 14 / 15 / 17 / 21px, metrics 28px, tabular numbers |
| Space | 4px scale `--s1`…`--s8` |
| Radius | 6 (controls, badges) · 8 (buttons, inputs) · 10 (rows) · 12 (cards) · 14 (modals) |
| Elevation | `--shadow-1` on cards only; `--shadow-2` for things that float (menus, modals, toasts) |

Colour carries meaning only: completed = success, at risk / due soon = warning, overdue / conflict = danger,
active = accent. Status badges always include text, never colour alone.

Components: `.btn` (`primary`, `ghost`, `danger`, `small`, `icon`), `.card`, `.list .row`, task row (`.task`, `.check`),
`.pill`/`.badge`, `.progress`, `.ring`, `.field`, `.formgrid`, `.more-fields` (progressive disclosure), `.menu`,
`.modal`, `.toast`, `.empty`, `.skeleton`, `.suggest` (Haman suggestion), `.kvgrid`, `.alert`.

Icons: `resources/views/partials/icons.blade.php`, a single inline SVG sprite (24px grid, 1.7 stroke). No icon font.

Accessibility: visible `:focus-visible` ring, skip link, `aria-current` on navigation, `aria-live` regions, Escape
closes dialogs and the mobile drawer, `prefers-reduced-motion` disables animation.

Dark mode: tokens are prepared under `:root[data-theme="dark"]` but not switched on yet.
