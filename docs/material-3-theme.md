# Material 3 theme

The admin and platform panels are skinned in Material Design 3. It is a visual layer only: colour, shape, type and spacing. No screen was rebuilt, and the shell geometry in `resources/css/filament/admin/theme.css` (the domain rail, the contextual column, the flyouts) is unchanged.

| Where | What it holds |
|---|---|
| `resources/css/filament/admin/material.css` | The `--md-sys-*` tokens and every Material rule, imported last from `theme.css` |
| `app/Providers/Filament/AdminPanelProvider.php` | `MATERIAL_PRIMARY` and `MATERIAL_NEUTRAL`, the same scheme as Filament's primary and gray ramps; the Roboto Flex font |
| `app/Providers/Filament/PlatformPanelProvider.php` | The font. Its indigo scheme is a token override in `material.css` |

Filament's own component styles sit in the `components` cascade layer. `material.css` is unlayered, so it wins without `!important`.

## Tokens

Seed: the logo green, `#3E894A`.

| Token | Light | Dark |
|---|---|---|
| primary / on-primary | `#2F6A3A` / `#FFFFFF` | `#96D59B` / `#00390F` |
| primary-container / on- | `#B2F2B6` / `#002109` | `#145120` / `#B2F2B6` |
| secondary-container / on- | `#D4E8D0` / `#0F1F10` | `#3A4B39` / `#D4E8D0` |
| tertiary-container | `#BCEBF2` | `#1F4D54` |
| error / error-container | `#BA1A1A` / `#FFDAD6` | `#FFB4AB` / `#93000A` |
| surface | `#F7FBF2` | `#10140F` |
| surface-container lowest / low / – / high / highest | `#FFFFFF` `#F1F5EC` `#EBEFE6` `#E6E9E1` `#E0E4DB` | `#0B0F0A` `#181D17` `#1C211B` `#262B25` `#31362F` |
| on-surface / on-surface-variant | `#181D17` / `#424940` | `#DFE4DA` / `#C2C8BD` |
| outline / outline-variant | `#72796F` / `#C2C8BD` | `#8C9388` / `#424940` |

The platform panel overrides the colour tokens with an indigo scheme on `.fi-panel-platform`. That panel is meant to look different from a company's panel.

Contrast was checked for every text pair the layer introduces. All are at least 4.5:1. Filament's gray 500, used for secondary text, is `#6E756B`, a step darker than Material's outline tone, so it clears 4.5:1 on both white and the surface.

## What each Filament component becomes

| Filament | Material 3 |
|---|---|
| Domain rail (`.fi-domain-rail-item`) | Navigation rail: icon over a 12px label, active domain in a 56×32 secondary-container pill |
| Contextual column (`.fi-sidebar`) | Navigation drawer: surface-container-low with a rounded trailing edge, pill items, active item in secondary-container |
| Topbar (`.fi-topbar`) | Top app bar on the surface, no shadow |
| ⌘K trigger (`.fi-command-palette-trigger`) | Search bar: fully rounded, surface-container-high |
| `.fi-btn` with a colour | Filled pill. In dark mode, primary uses primary / on-primary |
| `.fi-btn` gray or uncoloured | Tonal pill (secondary-container) |
| `.fi-btn.fi-outlined` | Outlined pill, 1px outline |
| `.fi-icon-btn` | Circular |
| `.fi-input-wrp` | Outlined text field: 4px corners, 1px outline, 2px primary on focus, error when invalid |
| Checkbox, radio, toggle | Primary when on |
| `.fi-section` | Outlined card, 12px corners |
| Stats widget | Filled card |
| `.fi-ta-ctn` | Outlined 12px container, header row in surface-container-low, sentence-case header labels |
| `.fi-badge` | Chip: 8px corners, no ring. Uncoloured badges use secondary-container |
| `.fi-tabs` | Primary tabs: hairline under the row, 3px primary indicator |
| `.fi-modal-window` | Dialog: 28px corners, surface-container-high, elevation 2 |
| `.fi-dropdown-panel` | Menu: 4px corners, surface-container, elevation 2 |
| `.fi-no-notification` | Snackbar: inverse surface |

## Left as it was, and why

- **Table row height.** Filament pads cell content per column type. Forcing 52–56px rows would mean overriding every cell type, and dense tables are worth more here than the spec height.
- **Drawer item height** is 48px rather than Material's 56px. A domain lists up to thirty screens. To use the spec height, change `--md-nav-item-height` to `3.5rem`.
- **The small-screen search icon** keeps its own utility styling. Only the desktop search field carries the `fi-command-palette-trigger` hook.
- **Report explorer, board, backlog, hiring pipeline and other custom components** in `theme.css` already use the panel's gray and primary ramps. They follow the new ramps but keep their own shapes.
- **Floating action button.** Filament has no slot for one. Each page's primary header action is a filled pill instead.

## Changing the seed

1. Generate a Material 3 scheme from the new seed, for example with Material Theme Builder, in light and dark.
2. Put its colours in the two token blocks at the top of `material.css`.
3. Put its primary and neutral tonal palettes in `MATERIAL_PRIMARY` and `MATERIAL_NEUTRAL`, on Filament's shades: 50 ≈ T98, 100 ≈ T95, 200 = T90, 300 = T80, 400 = T70, 500 = T60 (neutral: T50), 600 = T40, 700 = T30, 800 = T20, 900 ≈ T15, 950 = T10.
4. Rebuild the assets with `npm run build` and look at a list, a form and a dialog in both modes.
