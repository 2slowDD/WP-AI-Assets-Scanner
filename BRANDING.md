# Dr. Speed branding

Dr. Speed is the product line; AI Assets Scanner is its first plugin. Planned
siblings follow the same pattern: *Dr. Speed | Advanced Delay JS*, and the
umbrella *Dr. Speed | Performance Polyclinic*.

## Lockup

```
✚ Dr. Speed | AI Assets Scanner
```

- **✚ cross**: the pharmacy cross, in brand green. Decorative: `aria-hidden="true"`.
- **Dr. Speed**: brand green, in the title's own weight (semibold, 600) — not bold. Bold made the name shout next to the product name.
- **Divider**: 1px (2px in marketing art), white at 35% opacity.
- **Product name**: near-white `#d6e4f2` (admin) / `#f1f6fb` (marketing), regular weight.
- Screen readers read "Dr. Speed: AI Assets Scanner" (a visually hidden colon replaces the divider).
- On narrow screens the lockup breaks between the mark and the product name, never inside either.
- The formal plugin name, used in the plugin header and the directory, is **Dr. Speed: AI Assets Scanner**.

## Tagline

> Safely debloat your pages with one push of a button.

Keep claims literal: "safely" is backed by the Safe/Aggressive split and one-click undo.

## Colours

| Role | Hex | Notes |
|---|---|---|
| Brand green | `#22C55E` | 6.4:1 on the navy. Use this, not the true pharmacy green. |
| Pharmacy green (do not use for text) | `#00A651` | 4.5:1 on the navy: passes, but reads dim beside white. |
| Navy (admin header) | `#10294D` | |
| Midnight gradient | `#16243A` → `#1E3A5F` | Icon and banner background. |
| Scanner blue | `#72AEE6` | Rings, sweep, secondary labels. |
| Tagline | `#C9D8E8` | On navy. |

Contrast is measured against `#10294D` (WCAG 2.x relative luminance). Re-measure
before using any colour on a different background.

## Type

- **Admin screens**: the WordPress system font stack. Do not load a web font in wp-admin.
- **Marketing art** (directory banner, social images): Inter, variable weight.
  Download from Google Fonts (SIL Open Font License) as `tools/brand/inter.woff2`;
  the file is not committed.

## WordPress.org directory assets

Sources and outputs live in `.wordpress-org/` (outputs) and `tools/brand/` (banner
source + renderer). They are **not** part of the plugin zip: upload them to the
plugin's SVN `assets/` directory, next to `trunk/` and `tags/`.

| File | Size | Use |
|---|---|---|
| `icon.svg` | vector | Directory icon (PNG fallbacks required alongside) |
| `icon-128x128.png` | 128×128 | Icon, standard |
| `icon-256x256.png` | 256×256 | Icon, high-DPI |
| `banner-772x250.png` | 772×250 | Plugin page banner |
| `banner-1544x500.png` | 1544×500 | Banner, high-DPI |

Re-render after editing `icon.svg` or `tools/brand/banner.html`:

```bash
NODE_PATH=$(npm root -g) node tools/brand/render.js
```

The icon is full-bleed (the directory rounds corners itself) and contains no text,
so it stays legible at 128px. For a sibling plugin, keep the navy, rings and cross,
and change only the product name in the banner.
