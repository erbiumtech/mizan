# Material 3 theme

The admin and platform panels are skinned in Material Design 3. It is a visual layer only: colour, shape, type and spacing. No screen was rebuilt, and the shell geometry in `resources/css/filament/admin/theme.css` (the domain rail, the contextual column, the flyouts) is unchanged. The layouts it follows are the Material 3 artboards on the design canvas: dashboard, list, form, record detail, board and phone.

| Where | What it holds |
|---|---|
| `resources/css/filament/admin/material.css` | The `--md-sys-*` tokens and every Material rule, imported last from `theme.css` |
| `app/Providers/Filament/AdminPanelProvider.php` | `MATERIAL_PRIMARY` and `MATERIAL_NEUTRAL`, the admin scheme's tonal palettes as Filament's primary and gray ramps; semantic success, warning and danger; the Roboto Flex font; the topbar hooks |
| `app/Providers/Filament/PlatformPanelProvider.php` | The platform panel's teal ramps and font |
| `resources/views/filament/partials/command-palette-trigger.blade.php` | The topbar search bar |
| `resources/views/filament/partials/company-brand.blade.php` | The one logo and the company's name, which is also the company switcher |
| `resources/views/filament/partials/table-context-menu-toggle.blade.php` | The right-click switch row on the settings page |

Filament's own component styles sit in the `components` cascade layer. `material.css` is unlayered, so it wins without `!important`.

## Colour

The palette is chosen for itself and is not taken from the logo. Seed: indigo `#4355B9`, Material's **tonal spot** scheme, generated with Google's [`@material/material-color-utilities`](https://github.com/material-foundation/material-color-utilities) (`SchemeTonalSpot` and `MaterialDynamicColors`) rather than picked by hand. Tonal spot keeps the primary calm and gives cool, slightly indigo greys for the neutrals.

| Token | Light | Dark |
|---|---|---|
| primary / on-primary | `#515B92` / `#FFFFFF` | `#BAC3FF` / `#222C61` |
| primary-container / on- | `#DEE0FF` / `#394379` | `#394379` / `#DEE0FF` |
| secondary-container / on- | `#E0E1F9` / `#434659` | `#434659` / `#E0E1F9` |
| tertiary-container | `#FFD7F1` | `#5D3C55` |
| error / error-container | `#BA1A1A` / `#FFDAD6` | `#FFB4AB` / `#93000A` |
| surface | `#FBF8FF` | `#121318` |
| surface-container lowest / low / – / high / highest | `#FFFFFF` `#F5F2FA` `#EFEDF4` `#E9E7EF` `#E4E1E9` | `#0D0E13` `#1B1B21` `#1F1F25` `#29292F` `#34343A` |
| on-surface / on-surface-variant | `#1B1B21` / `#46464F` | `#E4E1E9` / `#C7C5D0` |
| outline / outline-variant | `#767680` / `#C7C5D0` | `#90909A` / `#46464F` |

**Success, warning and danger are semantic**: Filament's green, amber and red, whatever the seed. The `BRAND_*` constants in `AdminPanelProvider` no longer colour the panel. They stay because the PWA manifest's theme colour, the head's `theme-color` meta and their tests name `BRAND_GREEN`.

**The platform panel** has its own seed, teal `#006A6A`, generated the same way. The full scheme is an override on `.fi-panel-platform`, with every role so nothing falls through to the admin indigo, and teal ramps in `PlatformPanelProvider`. The two panels stay easy to tell apart.

**Contrast** was checked for every text and background pair the layer uses, in both schemes and both modes: at least 4.5:1 for text and 3:1 for outlines. Filament's gray 500, used for secondary text, is neutral-variant tone 45 (`#6A6A73`) rather than the outline's tone 50. That gives 5.4:1 on white and 5.1:1 on the surface.

### Changing the seed

1. Generate the scheme from the new seed: `new SchemeTonalSpot(Hct.fromInt(argbFromHex(seed)), isDark, 0)`, reading each role with `MaterialDynamicColors.<role>.getArgb(scheme)`, light and dark. The package uses extensionless ES imports, so run it in Node with a resolve hook that appends `.js`, or bundle it.
2. Put the colours in the two token blocks at the top of `material.css`.
3. Put the primary and neutral-variant tonal palettes in `MATERIAL_PRIMARY` and `MATERIAL_NEUTRAL` on Filament's shades. Primary: 50 = T96, 100 = T94, 200 = T90, 300 = T80, 400 = T70, 500 = T60, 600 = T40, 700 = T30, 800 = T20, 900 = T15, 950 = T10. Neutral: 50 = T96, 100 = T92, 200 = T87, 300 = T80, 400 = T60, 500 = T45, 600 = T40, 700 = T30, 800 = T20, 900 = T12, 950 = T6.
4. Recheck contrast, rebuild with `npm run build`, and look at a list, a form and a dialog in both modes.

## Top app bar

The order, left to right in English and mirrored in Urdu:

1. Filament's sidebar buttons.
2. **The search bar** fills the free width, up to 720px. It is 48px tall in the 64px bar, fully rounded and on `surface-container-high`. It has a leading search icon, the placeholder "Search employees, invoices, work…" and a trailing `⌘K` / `Ctrl K` chip. It is a real `<button>` that opens the command palette. A printable key pressed while it has focus opens the palette with that character already typed. ⌘K or Ctrl K still opens the palette from anywhere. Below 768px it collapses to a 40px circular search button.
3. **The company's logo and name.** This is the only logo in the panel. It uses the dark-mode variant in dark mode. A person in one company gets a link to the dashboard. A person in several gets a menu with the dashboard, then each other company, so it is the company switcher. Filament's own tenant menu in the column is hidden.
4. **The notifications bell**, Filament's database-notifications button restyled as a 40px icon button.
5. **The avatar**, 40px round, which opens the user menu.

Logos removed: the domain rail's brand mark (the rail now starts with its first destination), Filament's sidebar-header logo and Filament's topbar-start logo. The login and other simple pages keep their logo.

The right-click menus switch moved out of the user menu to **My settings**, the profile page (`EditProfile`, now labelled "My settings"). It is a Material switch row. It uses the same preference key, the same `'off'` literal and the same single persist call as before. See `docs/table-context-menu-plan.md` §4.

## Forms

The design's New employee form:

- **Every field's box** is 56px tall, with a 1px outline, 4px corners, a transparent fill and 16px text. Focus gives a 2px primary outline, invalid a 2px error outline, and disabled text is 38% on-surface. Boxes outside forms, such as table search and filters, stay 44px.
- **Floating, notched label.** The label sits over the box's top-left border (top-right in Urdu), 12px in, with 4px of padding. Its background is `--md-field-notch`, which each container sets to its own surface: the page surface, form sections `surface-container-low`, other sections `surface-container-lowest`, dialogs `surface-container-high` and menus `surface-container`. It is on-surface-variant at rest, primary while focused and error when invalid. The required asterisk is kept. Helper and validation text sits below the box at 12px with a 16px indent.
  - **Gets the floating label:** plain TextInput (including password, email and number), Select (native and searchable), DatePicker, DateTimePicker, TimePicker and Textarea. This applies when the label column holds only the label: no hint, no label prefix or suffix, no prefix or suffix text, no inline label and no nested fields.
  - **Keeps the label above the box**, same outline and colours: fields with prefix or suffix text, inline labels, hidden labels, hints beside the label, rich editors, Markdown editors, file uploads, repeaters and builders, key-value, tags input, checkbox lists, radios and color pickers.
- **Switches.** Toggle fields are Material switches: a 52×32 track and a handle that grows from 16px to 24px when on, primary when on. A toggle given another colour keeps it.
- **Segmented buttons.** Inline ToggleButtons are a single outlined pill split into segments, with the selected one in secondary-container and a leading check. Stacked ToggleButtons keep Filament's grid. **Radios stay radios**: turning Filament's radio markup into segments needs more than CSS can safely do.
- **Form sections** are filled cards on `surface-container-low`, with 16px corners and no outline. The title is 16/24 500 and the description 14px on-surface-variant. Rows are 20px apart and columns 16px. Sections without fields (tables, read-only infolists) stay outlined cards.
- **Form actions** are right-aligned 40px pills. Cancel is a text button, a secondary action such as "Create & create another" is outlined, and the submit is filled.

## Other components

| Filament | Material 3 |
|---|---|
| Domain rail (`.fi-domain-rail-item`) | Navigation rail: icon over a 12px label, active domain in a 56×32 secondary-container pill |
| Contextual column (`.fi-sidebar`) | Navigation drawer: surface-container-low with a rounded trailing edge, pill items, active item in secondary-container |
| `.fi-btn` with a colour | Filled pill. In dark mode, primary uses primary / on-primary |
| `.fi-btn` gray or uncoloured | Tonal pill (secondary-container) |
| `.fi-btn.fi-outlined` | Outlined pill, 1px outline |
| `.fi-icon-btn` | Circular |
| Checkbox, radio | Primary when on |
| `.fi-section` without fields | Outlined card, 12px corners |
| Stats widget | Filled card |
| `.fi-ta-ctn` | Outlined 12px container, header row in surface-container-low, sentence-case header labels |
| `.fi-badge` | Chip: 8px corners, no ring. Uncoloured badges use secondary-container |
| `.fi-tabs` | Primary tabs: hairline under the row, 3px primary indicator |
| `.fi-modal-window` | Dialog: 28px corners, surface-container-high, elevation 2 |
| `.fi-dropdown-panel` | Menu: 4px corners, surface-container, elevation 2 |
| `.fi-no-notification` | Snackbar: inverse surface |

## Left as it was, and why

- **Table row height.** Filament pads cell content per column type. Forcing 52–56px rows would mean overriding every cell type, and dense tables are worth more here than the spec height.
- **Drawer item height** is 48px rather than Material's 56px, because a domain lists up to thirty screens. To use the spec height, change `--md-nav-item-height` to `3.5rem`.
- **Report explorer, board, backlog, hiring pipeline and other custom components** in `theme.css` use the panel's gray and primary ramps. They follow the new colours but keep their own shapes.
- **Floating action button.** Filament has no slot for one. Each page's primary header action is a filled pill instead.
