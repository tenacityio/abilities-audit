=== Abilities Audit ===
Contributors: tenacity
Tags: abilities, audit, ai, governance, tools, admin
Requires at least: 6.9
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.6.1
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

== Roadmap ==

We want to hear from you. The items below are things we are considering for future releases—please open an issue on the plugin repository and let us know what you would like us to prioritize.

= WordPress-style pagination =

The current view renders all registered abilities in a single flat list. In a production site that uses the Abilities API heavily—or once more Core, plugin, and theme authors start registering their own abilities—that list could easily grow to hundreds of entries. Future work would add standard WordPress table pagination (first / prev / next / last page links, a “Displaying X–Y of Z” summary, and a per-page screen-option control) so the screen remains fast and scannable regardless of how many abilities are registered.

= WordPress-style search =

A search bar and real-time filtering would let administrators quickly locate a specific ability by name, label, description, namespace, or source component without having to scroll through the full list. The goal is a UX that matches the familiar Posts or Users table search in WordPress core: type to filter, clear to reset, no page reload required.

= WordPress role-based permissions =

Right now the audit screen is restricted to users with the manage_options capability (i.e. site administrators). A role-based permissions layer would let site owners grant finer-grained access—for example allowing editors to view the audit screen without being able to toggle abilities on or off, or delegating toggle rights to a custom role without granting full admin access. This would integrate with the standard WordPress capabilities system and be extensible via map_meta_cap and custom capability filters.

= Namespace, source, and flag-based filtering =

As the number of registered abilities grows, the ability to slice the list by dimension becomes essential. Planned filter controls would let administrators narrow the view by:

* Namespace — show only abilities belonging to a specific namespace slug (e.g. core, my-plugin, woocommerce).
* Source — quickly isolate abilities registered by Core, a particular Plugin, or the active Theme using the same classification logic already used in the Source column.
* Flags — filter by one or more annotation badges already displayed in the Flags column: Read-only, Idempotent, REST-exposed, MCP-exposed, Destructive, or abilities that carry an Instructions hint. This makes it straightforward to audit, for example, all abilities flagged as Destructive or all abilities currently exposed over MCP in one view.

Filters would compose—namespace + source + one or more flags—and persist across pagination so the scoped view stays intact while browsing. A Reset filters control would clear all active filters and return to the full list.

== Screenshots ==

1. The Abilities Audit table listing status, name, label, source, description, flags, and schema actions.
2. Expanded schema row showing Raw Data, annotations, input schema, and output schema.

== Changelog ==

= 0.6.1 =
* Docs: add Roadmap section (WordPress-style pagination and search, role-based permissions, namespace/source/flag filtering).

= 0.6.0 =
* Performance: cache get_plugins() and get_mu_plugins() per request in detect_source() to avoid O(abilities × plugins) filesystem scans.
* Refactor: extract flags_kses_allowed_html() so the wp_kses allowlist is shared between render_admin_page() and ajax_toggle() and cannot drift.
* Refactor: extract build_ability_raw_data() to deduplicate the six-field raw-data array built in both render and AJAX paths; normalises null schema/meta values to arrays.
* Fix: render_admin_page() last-resort fallback now calls ensure_capture() + abilities_snapshot instead of reading wp_get_abilities() directly, avoiding a potential post-unregister registry read.
* Fix: provider meta matching is now case-insensitive (ucfirst/strtolower) so 'core', 'CORE', and 'Core' all resolve correctly.
* Fix: (inactive) plugin label is now a complete translatable string via sprintf(__('%s (inactive)')) instead of an untranslatable appended fragment.
* Removed manual load_plugin_textdomain() call; WordPress 6.9+ loads translations automatically.
* Add CORE_NAMESPACES class constant (was a local variable inside detect_source()).
* Minor: cast printf summary counts as (int); fix alignment in ajax_toggle() disable branch.

= 0.5.1 =
* Security: escape wp_die() output with esc_html__() in render_admin_page().
* Security: apply wp_kses() to flags HTML before sending via AJAX response, consistent with the page-render path.
* Refactor: remove redundant $raw_for_display array rebuild in schema detail row; use existing $raw_data directly.
* Refactor: remove redundant get_meta() call and is_array guard ($meta_for_flags) in ajax_toggle(); reuse $meta.

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
