# Abilities Audit

Audit and govern registered [WordPress Abilities API](https://make.wordpress.org/core/) abilities from a single **Tools** screen.

**Version:** 0.1.0 (beta)  
**Requires:** WordPress 6.9+, PHP 7.4+  
**License:** GPL-2.0-or-later  

This release is a **public beta** on GitHub ahead of a WordPress.org listing. Report issues and suggestions in the repository.

## Features

- View every ability registered on the site, with label, description, and best-effort source (WordPress core, plugin, theme, must-use plugin, or unknown).
- Inspect input/output JSON Schema and annotations where available.
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

### Where is the audit screen?

**Tools → Abilities Audit** in the WordPress admin.

## Development

- Main bootstrap: [`abilities-audit.php`](abilities-audit.php)
- Admin assets: [`assets/css/admin.css`](assets/css/admin.css), [`assets/js/admin.js`](assets/js/admin.js)
- Translations: [`languages/abilities-audit.pot`](languages/abilities-audit.pot)

The canonical plugin metadata for WordPress.org is in [`readme.txt`](readme.txt).

## Changelog

### 0.1.0

- Initial public beta: audit screen, schema inspection, and ability toggles.
