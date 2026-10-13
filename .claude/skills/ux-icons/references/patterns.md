# UX Icons Patterns

## Styling Icons with CSS

Icons render as inline `<svg>` elements. They inherit `currentColor` by default, so text color applies:

```twig
{# Icon inherits the text color #}
<span class="text-blue-500">
    {{ ux_icon('lucide:check') }}
</span>

{# Size via class #}
{{ ux_icon('lucide:check', {class: 'w-6 h-6'}) }}

{# Size via attributes #}
{{ ux_icon('lucide:check', {width: '24', height: '24'}) }}
```

```css
/* Style all icons globally */
svg[data-icon] {
    width: 1.25em;
    height: 1.25em;
    vertical-align: middle;
}
```

---

## Dynamic Icon Names

Use a variable for the icon name:

```twig
{# From a controller variable #}
{{ ux_icon(notification.icon) }}

{# Conditional icon #}
{{ ux_icon(is_open ? 'lucide:chevron-up' : 'lucide:chevron-down') }}

{# Loop with icons #}
{% for item in menu_items %}
    <a href="{{ item.url }}">
        {{ ux_icon(item.icon, {class: 'w-4 h-4 mr-2 inline'}) }}
        {{ item.label }}
    </a>
{% endfor %}
```

---

## Icon Buttons

```twig
<button type="button" class="btn btn-icon" aria-label="Close">
    {{ ux_icon('lucide:x', {class: 'w-4 h-4', 'aria-hidden': true}) }}
</button>

<button type="button" class="btn">
    {{ ux_icon('lucide:save', {class: 'w-4 h-4 mr-1 inline', 'aria-hidden': true}) }}
    Save
</button>
```

---

## Icon in Form Labels

```twig
{{ form_label(form.email, null, {
    label_html: true,
    label: ux_icon('lucide:mail', {class: 'w-4 h-4 inline'}) ~ ' Email',
}) }}
```

---

## Icons in LiveComponent

Icons work inside LiveComponents since they are rendered server-side:

```twig
{# components/StatusBadge.html.twig #}
<span {{ attributes }}>
    {% if status == 'active' %}
        {{ ux_icon('lucide:check-circle', {class: 'text-green-500 w-5 h-5 inline'}) }}
    {% elseif status == 'pending' %}
        {{ ux_icon('lucide:clock', {class: 'text-yellow-500 w-5 h-5 inline'}) }}
    {% else %}
        {{ ux_icon('lucide:x-circle', {class: 'text-red-500 w-5 h-5 inline'}) }}
    {% endif %}
    {{ label }}
</span>
```

---

## Design System: Centralize Icon Choices with Aliases

Use aliases to decouple templates from specific icon sets:

```yaml
# config/packages/ux_icons.yaml
ux_icons:
    aliases:
        # Actions
        action:save: 'lucide:save'
        action:delete: 'lucide:trash-2'
        action:edit: 'lucide:pencil'
        action:close: 'lucide:x'
        action:search: 'lucide:search'
        # Status
        status:success: 'lucide:check-circle'
        status:warning: 'lucide:alert-triangle'
        status:error: 'lucide:x-circle'
        status:info: 'lucide:info'
        # Navigation
        nav:home: 'lucide:home'
        nav:back: 'lucide:arrow-left'
        nav:menu: 'lucide:menu'
```

```twig
{# Templates use semantic names -- icon set can change in one place #}
{{ ux_icon('action:save') }}
{{ ux_icon('status:success') }}
{{ ux_icon('nav:home') }}
```

---

## Custom Local Icons

Place custom SVG files in `assets/icons/`:

```xml
<!-- assets/icons/logo.svg -->
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">
    <circle cx="50" cy="50" r="40" fill="currentColor"/>
</svg>
```

```twig
{{ ux_icon('logo') }}
```

The SVG file should:
- Have a `viewBox` attribute (so it scales)
- Use `currentColor` for colors that should inherit from CSS
- Not include `width`/`height` (let the consumer control size)

---

## Mixing Icon Sets

```twig
{# Different sets for different purposes #}
{{ ux_icon('lucide:home') }}        {# UI icons: Lucide (outline style) #}
{{ ux_icon('mdi:language-php') }}    {# Technology icons: MDI #}
{{ ux_icon('flags:fr') }}            {# Custom flags set #}
```

---

## Using Icons on Map Markers (UX Map Integration)

```php
use Symfony\UX\Map\Icon;
use Symfony\UX\Map\Marker;
use Symfony\UX\Map\Point;

$marker = new Marker(
    position: new Point(48.8566, 2.3522),
    title: 'Paris',
    icon: Icon::ux('mdi:map-marker')->width(32)->height(32),
);
```
