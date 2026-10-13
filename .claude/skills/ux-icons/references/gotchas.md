# UX Icons Gotchas & Debugging

## Icon Not Found

### Wrong Name Format

```twig
{{ ux_icon('lucide/arrow-right') }}    {# WRONG: slash #}
{{ ux_icon('lucide:arrow-right') }}    {# CORRECT: colon separator #}

{{ ux_icon('lucide:ArrowRight') }}     {# WRONG: camelCase #}
{{ ux_icon('lucide:arrow-right') }}    {# CORRECT: kebab-case #}
```

### Local Icon Path Mismatch

```
assets/icons/header/logo.svg     → ux_icon('header:logo')      CORRECT
assets/icons/header/logo.svg     → ux_icon('header/logo')      WRONG
assets/icons/Header/Logo.svg     → ux_icon('header:logo')      WRONG (case-sensitive)
```

### Missing Iconify Prefix

```twig
{{ ux_icon('arrow-right') }}           {# looks in local icons only #}
{{ ux_icon('lucide:arrow-right') }}    {# fetches from Iconify #}
```

---

## On-Demand Not Working

### Iconify Disabled

```yaml
ux_icons:
    iconify:
        enabled: true      # must be true
        on_demand: true     # must be true for development
```

### Network Issues

On-demand requires HTTP access to `api.iconify.design`. If your dev environment has no internet (Docker without network, VPN restrictions), import icons locally:

```bash
php bin/console ux:icons:import lucide:arrow-right
```

### Cache Issues

Clear the cache after config changes:

```bash
php bin/console cache:clear
```

---

## Default Attributes Conflicts

### fill vs stroke Icons

Lucide and Tabler use `stroke`, not `fill`. Setting `fill: currentColor` globally can break them:

```yaml
# PROBLEM: breaks stroke-based icon sets
ux_icons:
    default_icon_attributes:
        fill: currentColor

# BETTER: use per-set attributes
ux_icons:
    default_icon_attributes: {}
    icon_sets:
        mdi:
            alias: 'mdi'
            icon_attributes:
                fill: currentColor
        lucide:
            alias: 'lucide'
            icon_attributes:
                stroke: currentColor
                fill: none
```

Or keep it simple -- most icons work with just `currentColor` on the parent:

```css
.icon {
    color: currentColor;
    width: 1.25em;
    height: 1.25em;
}
```

---

## Attribute Removal

To remove a default attribute for a specific icon set, set it to `false` in the set's `icon_attributes`. Using `false` removes the attribute entirely; using `''` (empty string) keeps it with an empty value.

See [api.md > Attribute Merge Order](api.md#attribute-merge-order) for the full priority chain and a YAML example.

---

## Production Deployment

### Always Lock Icons Before Deploying

On-demand mode makes HTTP requests. In production, all icons should be local:

```bash
# Before deployment
php bin/console ux:icons:lock

# Or set on_demand to false in production
# config/packages/prod/ux_icons.yaml
ux_icons:
    iconify:
        on_demand: false
```

### ignore_not_found in Production

```yaml
# config/packages/prod/ux_icons.yaml
ux_icons:
    ignore_not_found: true    # renders nothing instead of 500 error
```

---

## SVG File Requirements

Local SVG files must be valid SVG:

```xml
<!-- GOOD -->
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
    <path d="M12 2L2 22h20L12 2z"/>
</svg>

<!-- BAD: missing xmlns -->
<svg viewBox="0 0 24 24">
    <path d="M12 2L2 22h20L12 2z"/>
</svg>

<!-- BAD: includes width/height that override sizing -->
<svg width="512" height="512" viewBox="0 0 24 24">
    ...
</svg>
```

Best practice: SVG files should have `xmlns` and `viewBox` but no hardcoded `width`/`height`.

---

## TwigComponent Required for HTML Syntax

```twig
{# This requires symfony/ux-twig-component #}
<twig:ux:icon name="lucide:check" />

{# This works without it #}
{{ ux_icon('lucide:check') }}
```

If you get an error about `twig:ux:icon` not being recognized, install the TwigComponent package:

```bash
composer require symfony/ux-twig-component
```
