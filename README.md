# HumHub server health check

A single-file health check for HumHub installations on managed or self-hosted
servers (tested against Apache + mod_php, PHP-FPM and CLI). It verifies the
[HumHub requirements](https://docs.humhub.org/docs/admin/requirements), the
[file permissions](https://docs.humhub.org/docs/admin/installation#file-permissions),
the [cron setup](https://docs.humhub.org/docs/admin/cron-jobs) — including that
every scheduled PHP command uses the same PHP version as the web application —
and the usual server resources.

No database connectivity test and no SSL expiry test (an external monitor such as
Uptime Kuma covers those).

Nothing is host-specific: the same four files drop into any HumHub server, and
everything that differs per site lives in `.env`.

## Install

```bash
cd /path/to/humhub                 # the directory holding index.php + protected/
git clone git@github.com:marc-farre/humhub-server-health-check.git
cd humhub-server-health-check
cp .env.example .env
chmod 600 .env                     # must stay readable by the PHP user (see below)
openssl rand -hex 32               # paste into HEALTH_TOKEN
```

Edit `.env` — at minimum `HEALTH_TOKEN`. `HUMHUB_PATH` is auto-detected when the
script sits in the HumHub root or one level below it; set it explicitly if the
humhub-server-health-check directory lives elsewhere. `BASE_URL` and `HEALTH_LABEL` are worth setting
too (the label identifies the server in alerts; it defaults to the hostname).

Verify:

```bash
/path/to/php humhub-server-health-check/health-check.php -v      # show every check
/path/to/php humhub-server-health-check/health-check.php --list  # list check ids
curl -s "https://example.org/humhub-server-health-check/health-check.php?token=…"
```

### Permissions

`.env` holds `HEALTH_TOKEN`, so it must not be world-readable — but it **must**
be readable by the PHP process. If web PHP runs as a different user than the
owner of `.env`, every HTTP request is refused with a message saying exactly
that (the script fails closed on purpose). Where web and CLI both run as the
site user, `chmod 600` owned by that user is correct; where PHP runs as
`www-data`, use `640` with the appropriate group.

### Web server protection

The bundled `.htaccess` denies everything in the directory except
`health-check.php`. HumHub's own root `.htaccess` already denies dotfiles by name
and that rule is inherited by subdirectories, but relying on it alone breaks as
soon as the humhub-server-health-check directory moves outside the HumHub root, so ship this file
too.

nginx has no `.htaccess`; add this to the server block instead:

```nginx
location ~ /\.                             { deny all; }
location ~ ^/humhub-server-health-check/(?!health-check\.php$) { deny all; }
```

## Cron

HumHub needs **both** console commands scheduled — the check warns when
`cron/run` is missing, which is easy to overlook:

```cron
* * * * * /path/to/php /path/to/humhub/protected/yii cron/run  >/path/to/cron.log 2>&1
* * * * * /path/to/php /path/to/humhub/protected/yii queue/run >/path/to/queue.log 2>&1
```

Point `CRON_LOG_FILE` / `QUEUE_LOG_FILE` at those logs and the check will alert
when a job stops running (log file older than `CRON_LOG_MAX_AGE_MINUTES`).

Schedule the health check itself so it only mails you when something is wrong:

```cron
*/10 * * * * out=$(/path/to/php /path/to/humhub/humhub-server-health-check/health-check.php 2>&1) || echo "$out"
```

Exit codes: `0` = OK, `1` = warnings only, `2` = errors.

## External monitor

- **URL**: `https://example.org/humhub-server-health-check/health-check.php?token=…`, or send the
  token as an `X-Health-Token` / `Authorization: Bearer` header.
- **Accepted status codes**: `200` — errors return `503`. Set
  `HTTP_FAIL_ON_WARNING=true` to make warnings fail too.
- Or use a **keyword monitor** on `Server health check passed`: the first line is
  `Server health check passed`, `Server health check passed with N warning(s)`, or
  `Server health check FAILED: …`, so the keyword matches while warnings stay
  visible in the response body.
- `&format=json` returns structured output; `&verbose=1` includes passing checks.

## How the PHP version comparison works

A cron job running a different PHP version than the web application causes
corrupted caches, broken assets and half-applied migrations. The script attacks
this from two directions.

**1. It parses the crontab** (`crontab -l`, or `CRONTAB_FILE`) and inspects
*every* PHP command, not just the two documented HumHub ones. It understands the
user format, the system format (`/etc/cron.d`, a user name after the schedule —
auto-detected, or forced with `CRONTAB_USER_FIELD`), `@reboot`/`@daily`
shortcuts, backslash line continuations, and `cd <dir> && …` for resolving
relative paths.

Interpreters are recognised however the host names them, and the version pinned
in the path is extracted:

| In the crontab | Detected version |
|---|---|
| `/opt/php8.4/bin/php` | 8.4 |
| `/usr/bin/php8.3`, `php8.2`, `/usr/bin/php8.4-cli` | 8.3 / 8.2 / 8.4 |
| `php84`, `ea-php83` | 8.4 / 8.3 |
| `/opt/plesk/php/8.2/bin/php` | 8.2 |
| `/opt/cpanel/ea-php81/root/usr/bin/php` | 8.1 |
| `/usr/local/php74/bin/php-cli` | 7.4 |
| `php`, `/usr/bin/php` | not pinned → resolved via `php -r`, plus a warning |

Each binary is then asked its real version (`php -r 'echo PHP_VERSION;'`), which
also catches a path that pins one version but is symlinked to another. If `exec()`
is unavailable, the version pinned in the path is used as a fallback. Absolute
paths that no longer exist are reported as errors, since such a job has been
failing silently on every run.

Only jobs belonging to **this** installation are judged: those invoking its
`protected/yii`, or any command running inside `HUMHUB_PATH` (backups, custom
scripts, composer). Other sites on the same server and unrelated tasks
(`mysqldump`, `certbot`, …) are counted and ignored. Two extra cases are flagged:
a `yii` call with no interpreter in front of it, which silently uses whatever PHP
the shebang resolves to, and any job relying on bare `php` from cron's minimal
`PATH` (disable with `CRON_REQUIRE_PINNED_PHP=false`).

`CRON_REQUIRED_ACTIONS` controls which console commands must be present, in case
a job runner such as supervisor or systemd handles the queue instead of cron.

**2. It records each run's own environment.** Every run stores its PHP version,
OS user and timezone per SAPI in a small JSON state file
(`protected/runtime/health-check-state.json` by default). The CLI run then
compares itself against the last web run and vice versa, the same way HumHub's
own *"Web Application and Cron uses the same PHP version"* check does.

So the web reference comes from the HTTP monitor hitting the URL, and the crontab
reference comes from the CLI run. Set `PHP_EXPECTED_VERSION` to pin the expected
version explicitly instead of inferring it.

## Checks

| id | What it covers |
|----|----------------|
| `disk` | Free space per partition (GB *and* percent, warn + error tiers) and inode usage |
| `php_version` | Running PHP against the version range the installed HumHub supports |
| `php_extensions` | Required + optional extensions, GD JPEG/PNG support, cURL SSL, ICU version, `proc_open` |
| `php_settings` | `memory_limit`, `max_execution_time`, upload limits, `display_errors`, `date.timezone` |
| `opcache` | Enabled, memory pressure, out-of-memory restarts |
| `load` | Load average per core, cgroup-quota aware |
| `memory` | Available RAM and swap usage |
| `temp_dirs` | Temp dir and session save path really writable |
| `humhub_install` | HumHub found, version, `vendor/autoload.php`, `dynamic.php`, `.htaccess` |
| `humhub_permissions` | The directories HumHub must be able to write, plus ownership sanity |
| `security` | `protected/.htaccess`, `uploads/file/.htaccess`, `dynamic.php` and `.env` permissions |
| `web_exposure` | Fetches sensitive paths over HTTP to prove they are not served |
| `cron` | Scheduled commands, PHP binaries and their versions, cron log freshness |
| `sapi_consistency` | Web vs CLI PHP version, OS user and timezone |
| `logs` | `protected/runtime/logs` size and recent error volume |

Skip noisy ones with `HEALTH_SKIP_CHECKS` in `.env` or `--skip=id,id`; run a
single one with `--only=id`.

## Notes and caveats

- The PHP support matrix (which PHP minor each HumHub minor allows) is baked into
  the script as `HC_HUMHUB_PHP_MATRIX`; the minimum is also read live from
  `protected/humhub/config/common.php`. Add a row when HumHub publishes a new
  release.
- `web_exposure` runs from CLI only by default: an FPM worker calling its own
  site can deadlock a small pool. `WEB_EXPOSURE_CHECK=always` overrides this.
- Web-only ini checks (upload limits, `display_errors`) are evaluated on HTTP
  runs, since CLI and FPM usually have different `php.ini` files.
- Reading the crontab needs either `exec()` (for `crontab -l`) or `CRONTAB_FILE`.
  `crontab -l` only ever returns the crontab of the user running the script, so
  point `CRONTAB_FILE` at `/etc/cron.d/…` if the jobs live there.
- All write tests actually create and delete a probe file, because
  `is_writable()` returns true on read-only mounts, exhausted quotas and some
  ACL setups.
