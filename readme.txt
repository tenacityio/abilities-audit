=== Abilities Audit ===
Contributors: tenacity
Tags: abilities, audit, ai, governance, tools, admin
Requires at least: 6.9
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Audit and govern registered WordPress Abilities API abilities from a single Tools screen. Public beta on GitHub before WordPress.org.

== Description ==

Abilities Audit provides an administrative dashboard for sites using the WordPress Abilities API (WordPress 6.9+). From **Tools > Abilities Audit** you can:

* View every ability registered on the site, with label, description, Flags (annotations and exposure hints from ability meta), and Source (Core, Plugin, or Theme). When the ability namespace matches an installed plugin, active theme, or must-use plugin, the badge shows that component's name (for example Plugin (AI)); otherwise it falls back to the namespace slug.
* Inspect input/output JSON Schema, annotations where available, and **Raw Data** (name, label, description, schemas, and meta)—the same consolidated payload shape as the Abilities Explorer detail view in the AI plugin.
* Toggle abilities on or off. Disabled abilities are unregistered at runtime so they are not exposed via the REST API or to AI agents that consume abilities.

This plugin is intended for administrators who need visibility and control over which abilities are active on a site.

== Installation ==

1. Upload the `abilities-audit` folder to the `/wp-content/plugins/` directory, or install the plugin through the WordPress plugins screen.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Go to **Tools > Abilities Audit** to review and manage abilities.

== Frequently Asked Questions ==

= What is the WordPress Abilities API? =

The Abilities API is a WordPress feature (6.9+) that lets components register structured “abilities” that can be discovered and invoked in a consistent way (for example by the REST API or AI integrations).

= What happens when I disable an ability? =

The ability is unregistered for the current request lifecycle and the disabled state is stored in the database. It will not appear in ability listings for integrations until you enable it again.

= Why might I still see a button or control for a disabled ability? =

Abilities Audit only controls whether the ability is registered on the site. Each integration (another plugin, the block editor, an AI feature, and so on) decides whether to show its own UI using its own rules: feature toggles, user capabilities, permission callbacks, and other checks. Those layers are not governed by this plugin.

The ability itself is still blocked when disabled here: attempts to run it via the Abilities REST API (or any path that requires a registered ability) will fail—for example with a 404 and rest_ability_not_found. If a control still appears, that reflects how that integration builds its UI, not that the ability remains available.

Recommendation for integrators: any interface that invokes an ability should also check that the ability is registered (for example via wp_has_ability() on the server or the equivalent in the block editor or REST) in addition to feature flags, user capabilities, and permission callback rules. That way, when an ability is disabled here—or unregistered for any other reason—controls can hide instead of surfacing a dead end.

= Where do I find the audit screen? =

After activation, open **Tools > Abilities Audit** in the WordPress admin. You need the `manage_options` capability (typically administrators).

== Screenshots ==

1. The Abilities Audit table listing status, name, label, source, description, flags, and schema actions.
2. Expanded schema row showing Raw Data, annotations, input schema, and output schema.

== Changelog ==

= 0.5.0 =
* Add contextual Help tabs to the admin screen: Overview, Flags reference, and Disabling Abilities guidance.
* Update translation template (.pot) with all current translatable strings.

= 0.4.0 =
* Add a Flags column between Description and Schema: color-graded badges for Read-only, Idempotent, REST, MCP, Destructive, Undeclared (all annotation booleans null), and an Instructions hint when meta includes agent guidance.

= 0.3.0 =
* Add Raw Data to the expandable schema panel: pretty-printed JSON for name, label, description, input/output schemas, and ability meta (aligned with Abilities Explorer).

= 0.2.0 =
* Live-update Description and Schema columns when toggling abilities on or off, driven by the AJAX response rather than requiring a page reload.
* Fix re-entrance bug in capture_and_filter() that caused the abilities snapshot to lose disabled entries when wp_get_abilities() lazy-fired wp_abilities_api_init.
* Disabled abilities now show their real description and schema on the audit page instead of a generic fallback message.
* Use event delegation for schema View/Hide buttons so dynamically created buttons work without rebinding.

= 0.1.1 =
* Update Source display to classify abilities as Core/Plugin/Theme and prefer the plugin/theme name when resolvable.

= 0.1.0 =
* Initial public beta: audit screen, schema inspection, and ability toggles.
