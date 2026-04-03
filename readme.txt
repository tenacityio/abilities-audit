=== Abilities Audit ===
Contributors: tenacity
Tags: abilities, audit, ai, governance, tools, admin
Requires at least: 6.9
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Audit and govern registered WordPress Abilities API abilities from a single Tools screen. Public beta on GitHub before WordPress.org.

== Description ==

Abilities Audit provides an administrative dashboard for sites using the WordPress Abilities API (WordPress 6.9+). From **Tools > Abilities Audit** you can:

* View every ability registered on the site, with label, description, and best-effort source (core, plugin, theme, must-use plugin, or unknown).
* Inspect input/output JSON Schema and annotations where available.
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

1. The Abilities Audit table listing status, name, label, source, description, and schema actions.
2. Expanded schema row showing annotations, input schema, and output schema.

== Changelog ==

= 0.1.0 =
* Initial public beta: audit screen, schema inspection, and ability toggles.
