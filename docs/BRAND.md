# Chef brand system

Status: **Locked for product implementation — 16 July 2026**

Chef's identity is **The Shared Table**: a household conversation becoming a
concrete meal plan. The mark shows a table between two chairs. It is deliberately
domestic and collaborative without relying on chef hats, utensils, food emoji,
robots, or decorative AI effects.

The approved visual reference is
[`branding/colorways/shared-table-white-canvas.png`](branding/colorways/shared-table-white-canvas.png).
That board is a presentation reference. The SVG and CSS files listed below are
the production sources of truth.

## Brand mark

The mark comprises three fixed elements:

- a paprika rounded table in the centre;
- a sage chair/conversational arc on the left;
- an oat chair/conversational arc on the right.

Use the full-colour mark on white or very light neutral surfaces. Use the
one-colour mark when colour reproduction is unavailable or when the surrounding
UI already carries significant state colour.

Do not rotate the mark, alter its proportions, recolour individual elements,
add shadows or gradients, place it inside a decorative badge, or substitute a
chef-hat or utensil icon. Keep clear space around the mark equal to at least one
quarter of its width. Do not render it below 20 CSS pixels.

Production masters:

- `web/public/brand/chef-mark.svg` — full-colour mark on white;
- `web/public/brand/chef-mark-mono.svg` — one-colour mark;
- `web/public/brand/chef-mark-maskable.svg` — safe-zone version for maskable app icons;
- `web/resources/js/components/app-logo-icon.tsx` — inline React mark using design tokens.

## Colour

### Core palette

| Role | Value | Use |
| --- | --- | --- |
| Canvas white | `#FFFFFF` | Main workspace and default page background |
| Ink | `#24231F` | Primary text, wordmark, and monochrome mark |
| Paprika | `#C94F32` | Table, primary actions, focus, and selected moments |
| Sage | `#6F806A` | Left chair, completed/supportive states, sparse data accents |
| Oat | `#DCCFB9` | Right chair, gentle highlights, participant or meal metadata |

White is intentionally dominant. A typical product screen should read as
roughly 80% white and quiet neutrals, 15% ink and structure, and no more than 5%
brand colour. Paprika is the primary interactive accent. Sage and oat support
the identity and semantic distinctions; they are not alternative page
backgrounds.

### Functional neutrals

Chef uses a small neutral ramp derived for interface structure:

- soft surface `#F7F7F6`;
- hover/selected neutral `#F2F2F0`;
- border `#E4E4E1`;
- muted text `#6E6D68`.

These neutrals preserve the clean, content-first spatial grammar described in
[`STYLE.md`](STYLE.md). Avoid beige page washes: oat stays an accent so meal
plans, conversations, recipes, and shopping lists remain the visual focus.

### Accessibility and state

Paprika with white text meets WCAG AA contrast for normal text. Sage and oat
must not carry small text on white. Allergy, destructive, unresolved, and other
safety-critical states keep dedicated semantic colours and always include an
icon, label, or other non-colour cue.

Dark mode uses derived neutral surfaces while retaining the three brand colours.
It is a supported interface theme, not a separate identity palette.

## Typography

- **Source Sans 3** is the product face. Use it for navigation, conversation,
  forms, shopping lists, recipe instructions, metadata, and controls.
- **Newsreader** is the brand/editorial face. Reserve it for the Chef wordmark,
  selected landing-page headings, and occasional recipe editorial moments.
- Use tabular numerals for prices, quantities, servings, dates, timers, and
  budget comparisons.
- Use sentence case. Monospace is for developer diagnostics only.

Both families are bundled in the web application through Fontsource so the
identity does not depend on a third-party font request at runtime.

## Shape, iconography, and imagery

- Use moderate radii, thin borders, whitespace, and alignment before shadows.
- Use Lucide's consistent outline family for product actions and navigation.
- The Chef mark represents the product; it is not a general-purpose cooking
  icon. Keep interface icons literal and label unfamiliar actions.
- Photography and illustration should feel useful and natural. Avoid glossy
  food advertising, stock-photo perfection, rustic craft tropes, and ambient
  AI decoration.

## Application assets

The favicon and installable-app assets are generated from the SVG masters:

- `web/public/favicon.svg` and `web/public/favicon.ico`;
- `web/public/apple-touch-icon.png`;
- `web/public/icon-192.png` and `web/public/icon-512.png`;
- `web/public/icon-maskable-512.png`;
- `web/public/site.webmanifest`.

Do not hand-edit generated PNG or ICO files. Change the SVG master, regenerate
the derivatives, and visually check the mark at 16, 20, 32, 180, and 512 pixels.

## Implementation source of truth

The active web tokens live in `web/resources/css/app.css`. Components consume
semantic shadcn/Tailwind roles such as `background`, `foreground`, `primary`,
`muted`, and `border`; brand-specific artwork may consume `brand-paprika`,
`brand-sage`, and `brand-oat` directly. Do not copy raw hex values into feature
components.

Any future native or marketing client should translate these same named roles
into its platform token format rather than inventing a parallel palette.
