# Abilities Audit

Audit and govern registered [WordPress Abilities API](https://make.wordpress.org/core/) abilities from a single **Tools** screen.

**Version:** 0.3.0 (beta)  
**Requires:** WordPress 6.9+, PHP 7.4+  
**License:** GPL-2.0-or-later  

This release is a **public beta** on GitHub ahead of a WordPress.org listing. Report issues and suggestions in the repository.

## Features

- View every ability registered on the site, with label, description, and **Source** (Core, Plugin, or Theme). When the ability namespace matches an installed plugin, active theme, or must-use plugin, the badge shows that component's name (for example `Plugin (AI)`); otherwise it falls back to the namespace slug.
- Inspect input/output JSON Schema, annotations where available, and **Raw Data** (name, label, description, schemas, and meta)—the same consolidated payload shape as the Abilities Explorer detail view in the AI plugin.
- Toggle abilities on or off. Disabled abilities are unregistered at runtime so they are not exposed via the REST API or to integrations that consume abilities.

This plugin is intended for administrators who need visibility and control over which abilities are active on a site.

## Installation

1. Install the plugin folder: from a **GitHub release**, download and unzip the ZIP so `abilities-audit/` exists under `/wp-content/plugins/` (or clone the repo and copy the `abilities-audit` directory there). You can also upload the folder via **Plugins → Add New → Upload Plugin** if you zip the `abilities-audit` folder first.
2. Activate the plugin through the **Plugins** screen.
3. Open **Tools → Abilities Audit** in the WordPress admin.

## Requirements

- WordPress **6.9** or later (Abilities API).
- PHP **7.4** or later.
- Users accessing the screen need the `manage_options` capability (typically administrators).

## Frequently asked questions

### What is the WordPress Abilities API?

The Abilities API is a WordPress feature (6.9+) that lets components register structured “abilities” that can be discovered and invoked in a consistent way—for example by the REST API or AI integrations.

### What happens when I disable an ability?

The ability is unregistered at runtime and the disabled state is stored in the database. It will not appear in ability listings for integrations until you enable it again.

### Why might I still see a button or control for a disabled ability?

Abilities Audit only controls **whether the ability is registered** on the site. Each integration (another plugin, the block editor, an AI feature, and so on) decides **whether to show its own UI** using its own rules: feature toggles, user capabilities, `permission_callback` behavior, and other checks. Those layers are not governed by this plugin.

**The ability itself is still blocked** when disabled here: attempts to run it via the Abilities REST API (or any path that requires a registered ability) will fail—for example with a `404` and `rest_ability_not_found`. If a control still appears, that is a limitation of the integration's UI, not a sign that the ability remains available.

**Recommendation for integrators:** any interface that invokes an ability should also **check that the ability is registered** (for example via `wp_has_ability()` on the server or the equivalent in the block editor / REST) **in addition to** feature flags, user capabilities, and `permission_callback` rules. That way, when an ability is disabled here—or unregistered for any other reason—controls can hide instead of surfacing a dead end.

### Where is the audit screen?

**Tools → Abilities Audit** in the WordPress admin.

## Development

- Main bootstrap: [`abilities-audit.php`](abilities-audit.php)
- Admin assets: [`assets/css/admin.css`](assets/css/admin.css), [`assets/js/admin.js`](assets/js/admin.js)
- Translations: [`languages/abilities-audit.pot`](languages/abilities-audit.pot)

The canonical plugin metadata for WordPress.org is in [`readme.txt`](readme.txt).

## Changelog

### 0.3.0

- Add Raw Data to the expandable schema panel: pretty-printed JSON for name, label, description, input/output schemas, and ability meta (aligned with Abilities Explorer).

### 0.2.0

- Live-update Description and Schema columns when toggling abilities on or off, driven by the AJAX response rather than requiring a page reload.
- Fix re-entrance bug in `capture_and_filter()` that caused the abilities snapshot to lose disabled entries when `wp_get_abilities()` lazy-fired `wp_abilities_api_init`.
- Disabled abilities now show their real description and schema on the audit page instead of a generic fallback message.
- Use event delegation for schema View/Hide buttons so dynamically created buttons work without rebinding.

### 0.1.1

- Update Source display to classify abilities as Core/Plugin/Theme and prefer the plugin/theme name when resolvable.

### 0.1.0

- Initial public beta: audit screen, schema inspection, and ability toggles.
