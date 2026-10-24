# Roadmap: install and update Mailroom like any other plugin

Status: **planned, not started.** Today Mailroom ships as a must-use plugin you copy in by hand. The goal is a
regular plugin that any WordPress site installs from a zip and then updates from wp-admin.

## 1. Package Mailroom as a regular plugin

WordPress never offers updates for mu-plugins, and they can't be deactivated from wp-admin. So Mailroom moves to
`wp-content/plugins/mailroom/` (main file `mailroom/mailroom.php`).

- **Load order.** Mailroom boots on `plugins_loaded` priority 20 (`class-core.php`), after every plugin has loaded,
  so it works the same as a regular plugin. "mailroom" sorts before "wp-mail-smtp", so its file still loads first.
  That means the classes that extend WP Mail SMTP's (`class-native.php`, `class-native-dashboard.php`) must stay
  lazy-required by the bridge after its version, class and signature checks, exactly as now.
- **Lifecycle hooks** replace "tables created on first load" and the manual [Uninstall](README.md#uninstall) steps:
  - activation: create or upgrade the tables, schedule `mailroom_prune`;
  - deactivation: unschedule the cron hooks and **keep all data**;
  - `uninstall.php`: drop `{prefix}mailroom_log` and `{prefix}mailroom_queue`, delete the `mailroom*` options,
    unschedule the hooks. Warn first if the queue still holds mail.

  Keep the "create tables if missing" check on load as a safety net.
- **Double-load guard.** If Mailroom is already loaded (an mu-plugin copy, or a renamed fork of it), the second copy
  must not boot, because two loggers double-log and two failover modules could double-send. Show an admin notice
  instead.
- **Headers:** `Requires at least`, `Requires PHP: 8.0`, `Requires Plugins: wp-mail-smtp` (WordPress 6.5+ plugin
  dependencies), `Update URI` (see below), `Text Domain: mailroom`. Add a `readme.txt` in wordpress.org format (the
  update checker also shows it in the "View details" popup) and a `CHANGELOG.md`.
- **Releases:** bump `Version` on every release (WordPress only offers an update when it increases), using semver.
  The first packaged release would be 2.1.0.
- **Testing before going live.** The staging trick in [Testing](README.md#testing) (`WPMU_PLUGIN_DIR` via
  `auto_prepend_file`) only covers mu-plugins. Proposed for a regular plugin, to verify first: put the candidate
  build at `plugins/mailroom-next/` (inactive, so visitors never load it) and, in a CLI-only prepend, filter
  `option_active_plugins` to swap `mailroom/mailroom.php` for `mailroom-next/mailroom.php`. Then run the tests as
  script files, as today.
- **Moving an existing mu-plugin install over:** drain the queue if rate limiting is on, upload the plugin
  inactive, remove the mu-plugin files, then activate it. The tables and options keep their names, so no
  data moves.

## 2. Update channel

| Option | How sites get updates | Effort | Constraints |
|---|---|---|---|
| **A. GitHub Releases + [plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker)** (MIT, 5.x) | A bundled library checks this repo's latest release; sites get the normal "update available" notice, one-click update and optional auto-updates | Low: about 10 lines plus a CI workflow | Pre-releases are ignored. Call `enableReleaseAssets()` so sites install the built zip rather than the raw source. A private repo would need a token on every site; this one is public |
| B. [Git Updater](https://git-updater.com/) | A separate Git Updater plugin on every site reads a `GitHub Plugin URI:` header (with `Release Asset: true`) | Low in Mailroom, but each site must install and maintain Git Updater | One more plugin per site; check its current licensing first |
| C. wordpress.org directory | Built-in updates, plus search and install from wp-admin | Medium: plugin review, SVN deploys, `readme.txt` rules, a public support forum | [Guideline 8](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/) bans self-updating code, so this build can't include option A. Guideline 17 bars slugs that start with another product's name ("mailroom" is fine). Reviewers may question the upsell cleanup and the subclassing of WP Mail SMTP internals; ask plugins@wordpress.org first |
| D. Self-hosted update server ([wp-update-server](https://github.com/YahnisElsts/wp-update-server)) or Composer / WPackagist | The update checker points at our own metadata URL, or `composer update` on Composer-managed sites | Medium to high: a server to run, or Composer sites only | Not worth it for a handful of sites |

### Recommendation: A

- Least work, no extra plugin on each site, and full control over when a release reaches the sites.
- It keeps C open for later, as a separate build without the update checker (Guideline 8).
- **CI (GitHub Actions) on a `v*` tag:** lint on PHP 8.0–8.4, build `mailroom.zip` without `tests/` and dev files,
  and attach it to the GitHub Release. The test harness needs a real WordPress with WP Mail SMTP, so run it against
  a site before tagging, or later in CI with `wp-env`.
- **Set `Update URI: https://github.com/yorch/mailroom`**, so WordPress (5.8+) never offers an unrelated
  wordpress.org plugin named "mailroom" as an update for this one.
- Turn on auto-updates for a site only after a release has run cleanly on a site you watch.

Choose C only if a public listing is wanted. That's a product decision, not a technical need.

## Open questions

- A, or a public wordpress.org listing (C)?
- Keep the upsell cleanup on by default in the general release, or make it opt-in? It's the part most likely to
  draw objections on wordpress.org.
