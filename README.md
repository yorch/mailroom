# Mailroom

Email logging, failure alerts, backup-SMTP failover, delivery reports and more for
[WP Mail SMTP](https://wordpress.org/plugins/wp-mail-smtp/) **Lite**, as a WordPress must-use plugin.
Where the installed WP Mail SMTP version allows it, Mailroom's screens render inside WP Mail SMTP's own admin
pages, so the site has one email plugin with working Email Log, Alerts and Reports screens.

Mailroom does **not** unlock, emulate or bypass WP Mail SMTP Pro. It never touches Pro detection, licensing,
the vendor's hosted services or usage tracking. It builds its features from WP Mail SMTP's public hooks and
extension points, and keeps its data in its own tables.

> Not affiliated with or endorsed by WP Mail SMTP or WPForms. "WP Mail SMTP" is a trademark of its owners and
> is used here only to say what this plugin works with.

## Features

| Feature | What it does |
|---|---|
| **Email log** | Every send and every failure: time, status, recipients, subject, mailer, duration, error. Filters, paging, a detail view, CSV export of the filtered set. Retention window with a daily prune |
| **Full storage → Resend / .eml** | Opt-in, off by default. Stores the raw `wp_mail()` arguments and the exact sent MIME for a short window (default 7 days), so an email can be resent (optionally to another address) or downloaded as `.eml` |
| **Failure alerts** | Webhook in the format the service expects (Slack, Discord, Microsoft Teams Workflows, or generic JSON) and/or email through the server's local MTA (so alerts still go out when the site mailer is what's broken). One throttle window across channels. A *Send test alert* button. Payloads carry no recipients or subject |
| **Backup SMTP failover** | On a failed send, one best-effort re-send through a second SMTP host, with a circuit breaker |
| **Reports** | A wp-admin dashboard widget (last 7 days) and an opt-in weekly report email |
| **Email controls** | Tick boxes to stop WordPress core notification emails (comment, new-user, password/email-changed notices, auto-update results). Security mail is never offered |
| **Rate limiting** | At most N emails per M minutes. Over the cap, mail is queued and drained by cron within the cap. An exempt list (default `Password Reset`) is never queued. If queuing fails, the email is sent immediately, so nothing is lost |
| **Smart routing (lite)** | Rules (`subject:`, `to:`, `initiator:`, `header:`) send matching emails through the backup connection |
| **Native dashboard** | WP Mail SMTP's own Dashboard page (4.10+) shows Mailroom's numbers: stat cards, a 12-week chart and the newest log entries |
| **Upsell cleanup** | Hides Lite's upgrade advertising in wp-admin using only WP Mail SMTP's and WordPress's own switches (see below) |

Everything ships **off**. Nothing changes until you configure it.

Deliberately not built: open and click tracking (bad for deliverability and privacy), and anything that needs the
vendor's hosted services.

## Requirements

- WordPress with **WP Mail SMTP Lite** active. Mailroom does nothing at all if WP Mail SMTP is missing.
- PHP 8.0+ (developed and tested on PHP 8.3).
- **Native integration** (screens inside WP Mail SMTP's admin) is enabled only for WP Mail SMTP **4.10.x**
  (`>= 4.10.0, < 4.11.0`). Outside that range, or if any class or method it extends has changed, Mailroom falls
  back to its own standalone *WP Mail SMTP → Email Log* page. The engine (logging, alerts, failover and so on)
  works either way.
- A real system cron for the rate-limit drain and the weekly report is recommended (`DISABLE_WP_CRON` plus a
  crontab entry).

## Install

Copy into `wp-content/mu-plugins/`:

```
wp-content/mu-plugins/mailroom.php     ← the loader
wp-content/mu-plugins/mailroom/        ← one file per feature
```

Must-use plugins load automatically; the database tables (`{prefix}mailroom_log`, `{prefix}mailroom_queue`) are
created on first load. A fatal error in an mu-plugin takes the whole site down, so test before deploying (see
[Testing](#testing)). Upload to a temporary name, `php -l` it, then `mv` it into place, which is atomic on
the same filesystem.

## Configure

With native integration on, settings live in WP Mail SMTP's own screens:

| WP Mail SMTP screen | Mailroom content |
|---|---|
| **Email Log** submenu | the log list and the single-email view (Resend, Download .eml) |
| **Settings → Email Log** | retention, body preview, full storage |
| **Settings → Alerts** | alert channels + *Send test alert* |
| **Settings → Email Controls** | email controls |
| **Settings → Additional Connections** | backup SMTP + failover |
| **Settings → Smart Routing** | routing rules, plus rate limiting and the queue |
| **Tools → Export** | filtered CSV export |
| **Email Reports** | an overview of every feature's status (with warnings for misconfiguration) and the weekly report settings |
| **Dashboard** | stat cards, chart and newest entries from Mailroom's log |

In fallback mode the same settings are tabs on *WP Mail SMTP → Email Log*.

Minimum useful setup: an alert channel (webhook and/or email), then press **Send test alert**. Failover and smart
routing also need a backup SMTP host **and a backup From address**, because reusing the primary's address on
another relay breaks DMARC.

## Switches

| Switch | Effect |
|---|---|
| `define( 'MAILROOM_NATIVE_UI', false );` / filter `mailroom_native_ui` | Native integration off → standalone page |
| `define( 'MAILROOM_NATIVE_DASHBOARD', false );` / filter `mailroom_native_dashboard` | Only the native Dashboard off |
| `define( 'MAILROOM_HIDE_UPSELLS', false );` / filter `mailroom_hide_upsells` | Upsell cleanup off |

Each filter defaults to `true` and can only switch its feature **off**.

### Upsell cleanup: what it hides and how

| Removed | Mechanism |
|---|---|
| "You're using WP Mail SMTP Lite…" bar | WP Mail SMTP's documented `wp_mail_smtp_admin_education_notice_bar` filter |
| Dashboard "features upsell" and "Growth Tools" boxes | its `wp_mail_smtp_admin_dashboard_widget_pipeline_get_widgets` filter |
| Settings "Get Pro" tab | its `wp_mail_smtp_admin_get_pages` filter |
| "Upgrade to Pro" sidebar item | core `remove_submenu_page()` |
| "Get WP Mail SMTP Pro" link on the Plugins screen | core `plugin_action_links_{plugin}` filter |
| Settings Pro banner | its own per-user "dismissed" flag reads as set (what its Dismiss button stores); nothing is written |

No CSS, and no edits to WP Mail SMTP. The licence-key field, the "X is a Pro feature" dialogs on locked features
and upgrade URLs are left alone.

## Hooks into WP Mail SMTP

Logging uses `wp_mail_smtp_mailcatcher_smtp_send_after` (SMTP/mail success),
`wp_mail_smtp_mailcatcher_send_after` (API path, which fires on **every** attempt, so Mailroom only trusts it when
`$mailer->is_email_sent()` is true) and `wp_mail_smtp_mailcatcher_send_failed` (failures on both paths).

Native integration uses WP Mail SMTP's page and tab registries (`wp_mail_smtp_admin_get_pages`,
`wp_mail_smtp_admin_display_get_logs_fqcn`, `wp_mail_smtp_admin_page_tools_tabs`,
`wp_mail_smtp_admin_page_reports_tabs`, `wp_mail_smtp_admin_area_get_dashboard`, the dashboard widget pipeline).
The classes that extend WP Mail SMTP's (`class-native.php`, `class-native-dashboard.php`) are loaded only after a
version-range check, a class/method check and a reflection check of every overridden method's signature. That
way a WP Mail SMTP update that changes them turns the feature off instead of causing a fatal error.

## Safety properties

- **Logging can never break sending.** Every hook callback is wrapped in `try/catch (\Throwable)`. This matters
  because WP Mail SMTP fires `send_failed` inside its own `catch`, so an uncaught error there would kill the request.
- **No secrets in the log.** The backup SMTP password is encrypted with WP Mail SMTP's own `Helpers\Crypto`. That
  is obfuscation, not real protection, because the key lives in the same database. Bodies are stored only when you
  opt in.
- **Admin actions require `manage_options` and a nonce**, and the state-changing ones are POST-only.
- **CSV export is protected against formula injection.**
- **Failover is skipped on partial rejection** (some recipients accepted). It runs at most once per request,
  with bounded timeouts and a 10-minute circuit breaker after a backup failure.
- **Webhook alerts are https-only and leave out recipients and subjects**; email addresses in errors are masked.
- **Log fields are clamped** to their column sizes before insert, because `wpdb` silently drops the whole row
  on "data too long".

## Caveats

- **Failover is best-effort.** The original `wp_mail()` caller still gets `false`; the backup only makes sure
  the message goes out. If the primary reports a failure but actually delivered, the recipient gets a duplicate.
- **Rate limiting delays mail** by up to the window. Put time-sensitive subjects on the exempt list.
- **Smart routing is "lite"**: one alternative route (the backup connection). It works by filtering WP Mail
  SMTP's option getters for the duration of one email.
- **The alert webhook URL is a bearer secret** (Slack and Discord URLs are credentials). It is stored in
  `wp_options` and only shown masked in the UI.
- **Alert emails go through the server's local MTA**, so the server must be authorised in your domain's SPF.
  Press *Send test alert* to check.

## Testing

`tests/test-mailroom.php` is an in-process harness (135 checks) that loads a **real** WordPress install with the
real WP Mail SMTP. It is built to be safe to run on a live site:

- It works on its own test tables (dropped at the end) and holds settings in memory (`pre_option_mailroom`), so
  the live option is never written. Shared transients are snapshotted and restored.
- Last-resort `pre_wp_mail` and `pre_http_request` guards at `PHP_INT_MAX` block any email or HTTP call that no
  test intercepted. The run fails if they had to block anything.
- The few live sends go to a refused local port (`127.0.0.1:1`). They abort unless that override is in effect,
  and WP Mail SMTP's own failure records are restored afterwards.

`tests/e2e-mail-failure.php` drives the real WP Mail SMTP into its failure path once: one `failed` row, one
PII-free alert, a failover attempt (also refused), then everything is cleaned up.

```sh
export MAILROOM_WP_LOAD=/path/to/wordpress/wp-load.php
export MAILROOM_SITE_HOST=www.example.com
php tests/test-mailroom.php
php -d display_errors=0 tests/e2e-mail-failure.php
```

Mailroom must already be loaded by that install, or by a staging copy (below). The first run creates Mailroom's
tables if the plugin hasn't run on that site before.

**Testing new code before it goes live.** Copy `wp-content/mu-plugins` to a staging folder, put the new code
there, and point `WPMU_PLUGIN_DIR` at it for the CLI process only:

```php
<?php // staging-prepend.php
define( 'WPMU_PLUGIN_DIR', '/path/to/mu-staging' );
define( 'WPMU_PLUGIN_URL', 'https://www.example.com/wp-content/mu-plugins' );
```

```sh
php -d auto_prepend_file=/path/to/staging-prepend.php tests/test-mailroom.php
```

Visitors keep getting the live code. Run the tests as script files: `php -r` ignores `auto_prepend_file` and
silently loads the live code.

## Uninstall

1. If rate limiting was used, drain the queue first (*Process queue now*, or turn the limit off and wait one cron
   run). Otherwise queued mail is lost along with the table.
2. Delete `mailroom.php` and `mailroom/` from `wp-content/mu-plugins/`.
3. `DROP TABLE {prefix}mailroom_log, {prefix}mailroom_queue;`
4. Delete the options starting with `mailroom` and unschedule the `mailroom_prune`, `mailroom_weekly_report`
   and `mailroom_drain` cron hooks.

## License

[GPL-3.0-or-later](LICENSE). Mailroom extends classes from WP Mail SMTP, which is GPL-licensed.
