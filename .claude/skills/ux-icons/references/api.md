# UX Icons API Reference

## Twig Function

```twig
{{ ux_icon(name, attributes = {}) }}
```

| Parameter    | Type   | Description |
|-------------|--------|-------------|
| `name`      | string | Icon name: `'close'`, `'header:logo'`, `'lucide:star'` |
| `attributes`| array  | HTML attributes added to the `<svg>` element |

The function returns the raw SVG markup with the given attributes merged onto the root `<svg>` element.

---

## Twig Component

```html
<twig:ux:icon name="icon-name" ...attributes />
```

Requires `symfony/ux-twig-component`. All extra attributes are passed through to the `<svg>` element.

---

## Full Configuration Reference

```yaml
ux_icons:
    # Directory containing local SVG icon files
    # Default: '%kernel.project_dir%/assets/icons'
    icon_dir: '%kernel.project_dir%/assets/icons'

    # Default HTML attributes added to every rendered icon
    # These are merged with per-icon attributes (per-icon wins)
    default_icon_attributes:
        fill: currentColor

    # Icon aliases: alias name => target icon name
    aliases:
        shortcut: 'prefix:full-icon-name'

    # Iconify.design integration
    iconify:
        # Enable/disable Iconify integration entirely
        enabled: true

        # Fetch icons on-demand from the Iconify API during development
        on_demand: true

        # Iconify API endpoint
        endpoint: 'https://api.iconify.design'

    # If true, missing icons render nothing instead of throwing an exception
    ignore_not_found: false

    # Custom icon sets with their own prefix, source, and attributes
    icon_sets:
        prefix:
            # Local directory path (mutually exclusive with "alias")
            path: '%kernel.project_dir%/path/to/icons'

            # Alias to an existing icon set (mutually exclusive with "path")
            alias: 'lucide'

            # Override default_icon_attributes for this set
            icon_attributes:
                class: 'icon-custom'
                fill: false            # removes "fill" from defaults
```

---

## Icon Resolution Order

When `ux_icon('name')` is called, icons are resolved in this order:

1. **Aliases** -- check if `name` is an alias, resolve to target name
2. **Local icons** -- look for `{icon_dir}/{name}.svg` (`:` → `/`)
3. **Custom icon sets** -- if prefix matches a configured set, look in that set's path or resolve its alias
4. **Iconify on-demand** -- if enabled, fetch from the Iconify API

---

## Attribute Merge Order

Attributes are merged with this priority (last wins):

1. `default_icon_attributes` from global config
2. `icon_attributes` from the icon set config
3. Per-icon attributes (from `ux_icon()` second argument or component attributes)

To **remove** a default attribute for a specific set, set it to `false`:

```yaml
ux_icons:
    default_icon_attributes:
        fill: currentColor
        class: 'icon'
    icon_sets:
        flags:
            path: '%kernel.project_dir%/assets/flags'
            icon_attributes:
                fill: false        # removes "fill: currentColor"
                class: 'flag'      # replaces "icon" with "flag"
```

---

## CLI Commands

### `ux:icons:import`

```bash
php bin/console ux:icons:import <icon-name> [<icon-name>...]
```

| Option    | Description |
|-----------|-------------|
| `--force` | Overwrite existing local icon files |

Downloads icons from Iconify into the local `icon_dir`.

### `ux:icons:lock`

```bash
php bin/console ux:icons:lock
```

| Option    | Description |
|-----------|-------------|
| `--force` | Overwrite existing local icon files |
| `-v`      | Verbose output, helps identify invalid icon names |

Scans templates for on-demand icon usage and downloads all matching icons locally. Run this before deploying to production.

---

## Popular Iconify Prefixes

| Prefix       | Set Name          | Icons | Style   |
|-------------|-------------------|-------|---------|
| `lucide`    | Lucide            | 1500+ | Outline |
| `tabler`    | Tabler Icons      | 5000+ | Outline |
| `mdi`       | Material Design   | 7000+ | Filled  |
| `heroicons` | Heroicons         | 300+  | Outline/Solid |
| `bi`        | Bootstrap Icons   | 2000+ | Mixed   |
| `ri`        | Remix Icon        | 2800+ | Mixed   |
| `ph`        | Phosphor Icons    | 7000+ | Multi   |
| `flowbite`  | Flowbite Icons    | 500+  | Mixed   |
| `fa6-solid` | Font Awesome 6    | 1400+ | Solid   |
| `carbon`    | Carbon Icons      | 2000+ | Outline |
| `iconoir`   | Iconoir           | 1500+ | Outline |

Full list: https://icon-sets.iconify.design/
