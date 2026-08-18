<?php

/**
 * =============================================================================
 * Server / HumHub Health Check
 * -----------------------------------------------------------------------------
 * Verifies that the server still satisfies the HumHub requirements and that the
 * installation itself is sane (permissions, cron/PHP consistency, resources).
 *
 * Configuration lives in a `.env` file next to this script (see .env.example).
 * The `.env` MUST be protected from web access — ship the provided .htaccess.
 *
 * USAGE
 *   CLI:   php health-check.php [-v] [--json] [--quiet] [--only=id,id]
 *                              [--skip=id,id] [--env=/path/.env] [--list]
 *          Exit codes: 0 = OK, 1 = warnings only, 2 = errors
 *
 *   HTTP:  https://<your-site>/health/health-check.php?token=SECRET
 *          200 = OK / warnings, 503 = errors (configurable)
 *          Add &format=json for machine-readable output.
 *          The first line always contains "Server health check passed" when
 *          there is no error, so an Uptime Kuma keyword monitor keeps working.
 *
 * References
 *   https://docs.humhub.org/docs/admin/requirements
 *   https://docs.humhub.org/docs/admin/installation#file-permissions
 *   https://docs.humhub.org/docs/admin/cron-jobs
 * =============================================================================
 */

declare(strict_types=1);

const HC_VERSION = '2.0.0';

// -----------------------------------------------------------------------------
// PHP version support matrix per HumHub minor release.
// Source: https://docs.humhub.org/docs/admin/requirements (PHP Environment)
// 'max' is the highest PHP minor HumHub declares as supported; 'best' are the
// versions the docs mark as recommended (bold).
// -----------------------------------------------------------------------------
const HC_HUMHUB_PHP_MATRIX = [
    '1.19' => ['min' => '8.2', 'max' => '8.5', 'best' => ['8.3', '8.4']],
    '1.18' => ['min' => '8.2', 'max' => '8.4', 'best' => ['8.2', '8.3']],
    '1.17' => ['min' => '8.1', 'max' => '8.3', 'best' => ['8.2', '8.3']],
    '1.16' => ['min' => '8.0', 'max' => '8.3', 'best' => ['8.1', '8.2', '8.3']],
    '1.15' => ['min' => '7.4', 'max' => '8.3', 'best' => ['8.1', '8.2']],
    '1.14' => ['min' => '7.4', 'max' => '8.2', 'best' => ['8.1', '8.2']],
];

// =============================================================================
// Small helpers
// =============================================================================

final class Env
{
    /** @var array<string,string> */
    private array $vars = [];
    private ?string $file = null;

    public function load(string $file): bool
    {
        if (!is_file($file) || !is_readable($file)) {
            return false;
        }
        $this->file = $file;
        $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || $line[0] === ';') {
                continue;
            }
            if (str_starts_with($line, 'export ')) {
                $line = substr($line, 7);
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $key = strtoupper(trim(substr($line, 0, $pos)));
            $val = trim(substr($line, $pos + 1));
            // Quoted value: keep as-is. Unquoted: strip trailing comment.
            if (strlen($val) > 1 && ($val[0] === '"' || $val[0] === "'") && substr($val, -1) === $val[0]) {
                $val = substr($val, 1, -1);
            } elseif (($hash = strpos($val, ' #')) !== false) {
                $val = rtrim(substr($val, 0, $hash));
            }
            $this->vars[$key] = $val;
        }
        return true;
    }

    public function file(): ?string
    {
        return $this->file;
    }

    public function raw(string $key): ?string
    {
        // A real environment variable always wins (handy for one-off overrides).
        $fromEnv = getenv($key);
        if ($fromEnv !== false && $fromEnv !== '') {
            return $fromEnv;
        }
        return $this->vars[$key] ?? null;
    }

    public function str(string $key, string $default = ''): string
    {
        $v = $this->raw($key);
        return $v === null ? $default : $v;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $v = $this->raw($key);
        if ($v === null || $v === '') {
            return $default;
        }
        return in_array(strtolower($v), ['1', 'true', 'yes', 'on', 'enabled'], true);
    }

    public function int(string $key, int $default): int
    {
        $v = $this->raw($key);
        return ($v === null || $v === '' || !is_numeric($v)) ? $default : (int) $v;
    }

    public function float(string $key, float $default): float
    {
        $v = $this->raw($key);
        return ($v === null || $v === '' || !is_numeric($v)) ? $default : (float) $v;
    }

    /** @return string[] */
    public function list(string $key, array $default = []): array
    {
        $v = $this->raw($key);
        if ($v === null || trim($v) === '') {
            return $default;
        }
        $parts = preg_split('/[,\s]+/', trim($v)) ?: [];
        return array_values(array_filter(array_map('trim', $parts), static fn($p) => $p !== ''));
    }
}

final class Report
{
    public const OK = 'OK';
    public const WARNING = 'WARNING';
    public const ERROR = 'ERROR';
    public const SKIPPED = 'SKIPPED';

    /** @var array<int,array{check:string,state:string,message:string,hint:?string}> */
    private array $items = [];
    private string $current = 'general';

    public function group(string $id): void
    {
        $this->current = $id;
    }

    public function add(string $state, string $message, ?string $hint = null): void
    {
        $this->items[] = [
            'check' => $this->current,
            'state' => $state,
            'message' => $message,
            'hint' => $hint,
        ];
    }

    public function ok(string $m, ?string $h = null): void
    {
        $this->add(self::OK, $m, $h);
    }

    public function warn(string $m, ?string $h = null): void
    {
        $this->add(self::WARNING, $m, $h);
    }

    public function error(string $m, ?string $h = null): void
    {
        $this->add(self::ERROR, $m, $h);
    }

    public function skip(string $m, ?string $h = null): void
    {
        $this->add(self::SKIPPED, $m, $h);
    }

    /** @return array<int,array{check:string,state:string,message:string,hint:?string}> */
    public function all(): array
    {
        return $this->items;
    }

    /** @return array<int,array{check:string,state:string,message:string,hint:?string}> */
    public function withState(string ...$states): array
    {
        return array_values(array_filter($this->items, static fn($i) => in_array($i['state'], $states, true)));
    }

    public function count(string $state): int
    {
        return count($this->withState($state));
    }

    public function hasErrors(): bool
    {
        return $this->count(self::ERROR) > 0;
    }

    public function hasWarnings(): bool
    {
        return $this->count(self::WARNING) > 0;
    }
}

/** Convert a php.ini size value ("512M", "1G", "-1") to bytes. */
function hc_ini_bytes(?string $value): ?int
{
    if ($value === null) {
        return null;
    }
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    if (!preg_match('/^(-?\d+(?:\.\d+)?)\s*([kmgt])?b?$/i', $value, $m)) {
        return null;
    }
    $num = (float) $m[1];
    $mult = ['' => 1, 'K' => 1024, 'M' => 1024 ** 2, 'G' => 1024 ** 3, 'T' => 1024 ** 4];
    return (int) round($num * $mult[strtoupper($m[2] ?? '')]);
}

function hc_bytes_human(float $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return sprintf($i === 0 ? '%d %s' : '%.2f %s', $bytes, $units[$i]);
}

/** Can we actually shell out? (exec may exist but be disabled) */
function hc_can_exec(): bool
{
    static $can = null;
    if ($can !== null) {
        return $can;
    }
    if (!function_exists('exec')) {
        return $can = false;
    }
    $disabled = array_map('trim', explode(',', strtolower((string) ini_get('disable_functions'))));
    return $can = !in_array('exec', $disabled, true);
}

/** Run a command, capture combined output. Returns exit code, or null if exec is unavailable. */
function hc_exec(string $cmd, ?string &$output = null): ?int
{
    if (!hc_can_exec()) {
        return null;
    }
    $lines = [];
    $code = 0;
    @exec($cmd . ' 2>&1', $lines, $code);
    $output = trim(implode("\n", $lines));
    return $code;
}

/** Effective CPU count, cgroup-quota aware (managed/containerised hosts lie via /proc/cpuinfo). */
function hc_cpu_count(): int
{
    $physical = 1;
    if (is_readable('/proc/cpuinfo')) {
        $info = (string) @file_get_contents('/proc/cpuinfo');
        $physical = max(1, preg_match_all('/^processor\s*:/mi', $info));
    } elseif (hc_can_exec() && hc_exec('nproc', $out) === 0 && is_numeric($out)) {
        $physical = max(1, (int) $out);
    }

    // cgroup v2
    if (is_readable('/sys/fs/cgroup/cpu.max')) {
        $parts = preg_split('/\s+/', trim((string) @file_get_contents('/sys/fs/cgroup/cpu.max'))) ?: [];
        if (count($parts) === 2 && $parts[0] !== 'max' && (int) $parts[1] > 0) {
            $quota = (int) ceil((int) $parts[0] / (int) $parts[1]);
            return max(1, min($physical, $quota));
        }
    }
    // cgroup v1
    $q = '/sys/fs/cgroup/cpu/cpu.cfs_quota_us';
    $p = '/sys/fs/cgroup/cpu/cpu.cfs_period_us';
    if (is_readable($q) && is_readable($p)) {
        $quota = (int) trim((string) @file_get_contents($q));
        $period = (int) trim((string) @file_get_contents($p));
        if ($quota > 0 && $period > 0) {
            return max(1, min($physical, (int) ceil($quota / $period)));
        }
    }
    return $physical;
}

/** Real write test — is_writable() lies about read-only mounts, ACLs and full quotas. */
function hc_write_test(string $dir): ?string
{
    if (!is_dir($dir)) {
        return 'directory does not exist';
    }
    if (!is_writable($dir)) {
        return 'not writable by ' . hc_process_user();
    }
    $probe = rtrim($dir, '/') . '/.hc_write_test_' . bin2hex(random_bytes(5));
    $bytes = @file_put_contents($probe, "healthcheck\n");
    if ($bytes === false) {
        return 'write test failed (quota, ACL or read-only mount?)';
    }
    @unlink($probe);
    return null;
}

/**
 * Read a request header robustly. Apache with mod_php and many FastCGI setups
 * drop `Authorization` before it reaches $_SERVER unless CGIPassAuth is on, so
 * check the redirected copy and the raw header list as well.
 */
function hc_request_header(string $name): string
{
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    foreach ([$key, 'REDIRECT_' . $key] as $candidate) {
        if (isset($_SERVER[$candidate]) && is_string($_SERVER[$candidate]) && $_SERVER[$candidate] !== '') {
            return $_SERVER[$candidate];
        }
    }
    foreach (['apache_request_headers', 'getallheaders'] as $fn) {
        if (function_exists($fn)) {
            $headers = @$fn();
            if (is_array($headers)) {
                foreach ($headers as $header => $value) {
                    if (strcasecmp((string) $header, $name) === 0 && is_string($value)) {
                        return $value;
                    }
                }
            }
        }
    }
    return '';
}

function hc_process_user(): string
{
    if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
        $pw = @posix_getpwuid(posix_geteuid());
        if (is_array($pw) && isset($pw['name'])) {
            return (string) $pw['name'];
        }
    }
    $user = get_current_user();
    return $user !== '' ? $user : 'unknown';
}

function hc_owner_name(string $path): string
{
    $uid = @fileowner($path);
    if ($uid === false) {
        return 'unknown';
    }
    if (function_exists('posix_getpwuid')) {
        $pw = @posix_getpwuid($uid);
        if (is_array($pw) && isset($pw['name'])) {
            return (string) $pw['name'];
        }
    }
    return '#' . $uid;
}

function hc_perms(string $path): ?int
{
    $p = @fileperms($path);
    return $p === false ? null : ($p & 0777);
}

/** "8.4.7" => "8.4" */
function hc_minor(string $version): string
{
    if (preg_match('/^(\d+)\.(\d+)/', $version, $m)) {
        return $m[1] . '.' . $m[2];
    }
    return $version;
}

function hc_session_path(): ?string
{
    $path = (string) session_save_path();
    if ($path === '') {
        return null;
    }
    if (str_contains($path, '://')) {
        return null; // redis/memcached/etc — nothing to stat
    }
    if (str_contains($path, ';')) {
        $path = substr($path, strrpos($path, ';') + 1); // "3;/var/lib/php/sessions"
    }
    return $path !== '' ? $path : null;
}

/** Expand a leading ~ in a path found in crontab. */
function hc_expand_home(string $path): string
{
    if (!str_starts_with($path, '~')) {
        return $path;
    }
    $home = getenv('HOME') ?: '';
    if ($home === '' && function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
        $pw = @posix_getpwuid(posix_geteuid());
        $home = is_array($pw) ? (string) ($pw['dir'] ?? '') : '';
    }
    return $home !== '' ? $home . substr($path, 1) : $path;
}

// -----------------------------------------------------------------------------
// Crontab parsing
// -----------------------------------------------------------------------------

/**
 * Split a crontab into jobs, dropping comments and variable assignments.
 *
 * Handles both the user format (5 schedule fields) and the system format
 * (/etc/cron.d, /etc/crontab: 5 fields + a user field), plus @reboot/@daily
 * style shortcuts. Also picks up a leading `cd <dir> &&` so that relative
 * paths later in the command can be resolved.
 *
 * @return array<int,array{raw:string,command:string,cwd:?string,tokens:string[]}>
 */
function hc_cron_jobs(string $crontab, bool $userField = false): array
{
    $jobs = [];
    $continued = '';

    foreach (preg_split('/\R/', $crontab) ?: [] as $line) {
        $line = rtrim($line);
        if ($continued !== '') {
            $line = $continued . ' ' . ltrim($line);
            $continued = '';
        }
        if (str_ends_with($line, '\\')) {
            $continued = rtrim(substr($line, 0, -1));
            continue;
        }
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        // MAILTO=..., PATH=..., SHELL=... are settings, not jobs.
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*\s*=/', $line)) {
            continue;
        }

        $fields = preg_split('/\s+/', $line) ?: [];
        if ($fields === []) {
            continue;
        }
        $skip = str_starts_with($fields[0], '@') ? 1 : 5;
        if (count($fields) <= $skip) {
            continue;
        }
        if ($userField) {
            $skip++; // /etc/cron.d style: the user runs after the schedule
        }
        $tokens = array_values(array_slice($fields, $skip));
        if ($tokens === []) {
            continue;
        }
        $command = implode(' ', $tokens);

        // `cd /some/dir && php protected/yii cron/run`
        $cwd = null;
        foreach ($tokens as $i => $tok) {
            if ($tok === 'cd' && isset($tokens[$i + 1])) {
                $cwd = hc_expand_home(trim($tokens[$i + 1], "\"';"));
                break;
            }
        }

        $jobs[] = [
            'raw' => $line,
            'command' => $command,
            'cwd' => $cwd,
            'tokens' => $tokens,
        ];
    }

    return $jobs;
}

/**
 * Find every PHP interpreter invoked in a command, whatever the naming scheme.
 *
 * Recognised: php, php8, php8.4, php84, php-8.4, php8.4-cli, /usr/bin/php8.3,
 * /opt/php8.4/bin/php, /opt/plesk/php/8.2/bin/php, ea-php83, /usr/local/php74/bin/php-cli.
 * `pinned` is the version explicitly written into the path or file name, or null
 * when the command just relies on whatever `php` resolves to.
 *
 * @param string[] $tokens
 * @return array<int,array{bin:string,pinned:?string}>
 */
function hc_cron_php_binaries(array $tokens): array
{
    $found = [];
    foreach ($tokens as $token) {
        $token = trim($token, "\"';");
        if ($token === '' || str_starts_with($token, '-')) {
            continue;
        }
        // Skip redirections and other shell noise.
        if (str_contains($token, '>') || str_contains($token, '|') || str_contains($token, '=')) {
            continue;
        }
        $base = basename($token);

        // Must be a php interpreter, not phpize/phpunit/php.ini/composer.
        if (!preg_match('/^(?:ea-)?php[-_]?(\d+(?:\.\d+)?)?(?:-?cli)?$/i', $base, $m)) {
            continue;
        }
        $pinned = hc_normalise_php_version($m[1] ?? '');
        if ($pinned === null) {
            // Version may live in the directory instead: /opt/php8.4/bin/php,
            // /opt/plesk/php/8.2/bin/php, /usr/local/php74/bin/php
            $dir = str_contains($token, '/') ? dirname($token) : '';
            if (preg_match_all('~(?:php[-_/]?|/)(\d+(?:\.\d+)?)(?:/|$)~i', $dir, $dm)) {
                $pinned = hc_normalise_php_version((string) end($dm[1]));
            }
        }
        $found[$token] = ['bin' => $token, 'pinned' => $pinned];
    }

    return array_values($found);
}

/** "84" => "8.4", "8.4" => "8.4", "8" => null (too vague to compare), "" => null */
function hc_normalise_php_version(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    if (str_contains($raw, '.')) {
        return preg_match('/^\d+\.\d+/', $raw) ? $raw : null;
    }
    if (strlen($raw) >= 2) {
        return $raw[0] . '.' . substr($raw, 1); // 84 => 8.4, 704 => 7.04 (unlikely)
    }
    return null; // bare major such as "php8": no minor to compare against
}

/**
 * Find HumHub console calls (protected/yii, or a bare `yii`) in a command.
 *
 * @param string[] $tokens
 * @return array<int,array{raw:string,path:?string,action:string,interpreted:bool}>
 */
function hc_cron_yii_calls(array $tokens, ?string $cwd = null): array
{
    $calls = [];
    foreach ($tokens as $i => $token) {
        $clean = trim($token, "\"';");
        $base = basename($clean);
        if ($base !== 'yii' && $base !== 'yii.bat') {
            continue;
        }
        $path = hc_expand_home($clean);
        if (!str_starts_with($path, '/')) {
            $path = ltrim($path, './');
            $path = $cwd !== null ? rtrim($cwd, '/') . '/' . $path : null;
        }
        // Is an interpreter given in front of it, or does it rely on its shebang?
        $interpreted = false;
        if ($i > 0) {
            $prev = trim($tokens[$i - 1], "\"';");
            $interpreted = preg_match('/^(?:ea-)?php[-_]?\d*(?:\.\d+)?(?:-?cli)?$/i', basename($prev)) === 1;
        }
        $calls[] = [
            'raw' => $clean,
            'path' => $path,
            'action' => isset($tokens[$i + 1]) && !str_starts_with($tokens[$i + 1], '-') ? trim($tokens[$i + 1], "\"';") : '',
            'interpreted' => $interpreted,
        ];
    }

    return $calls;
}

/**
 * Ask a PHP binary for its version; fall back to the version pinned in its path.
 *
 * @return array{version:string,from:string}|null
 */
function hc_php_binary_version(string $bin, ?string $pinned = null): ?array
{
    $resolvable = str_contains($bin, '/') ? (is_file($bin) && is_executable($bin)) : true;
    if ($resolvable && hc_exec(escapeshellarg($bin) . ' -r ' . escapeshellarg('echo PHP_VERSION;'), $out) === 0) {
        if (preg_match('/^\d+\.\d+\.\d+/', trim((string) $out), $m)) {
            return ['version' => $m[0], 'from' => 'exec'];
        }
    }
    if ($pinned !== null) {
        return ['version' => $pinned, 'from' => 'path'];
    }
    return null;
}

// =============================================================================
// Bootstrap: CLI arguments + .env
// =============================================================================

$isCli = PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';
$started = microtime(true);

$opt = ['verbose' => false, 'quiet' => false, 'json' => false, 'color' => true, 'env' => null, 'only' => [], 'skip' => [], 'list' => false];

if ($isCli) {
    foreach (array_slice($argv ?? [], 1) as $arg) {
        if ($arg === '-v' || $arg === '--verbose') {
            $opt['verbose'] = true;
        } elseif ($arg === '-q' || $arg === '--quiet') {
            $opt['quiet'] = true;
        } elseif ($arg === '--json') {
            $opt['json'] = true;
        } elseif ($arg === '--no-color') {
            $opt['color'] = false;
        } elseif ($arg === '--list') {
            $opt['list'] = true;
        } elseif (str_starts_with($arg, '--env=')) {
            $opt['env'] = substr($arg, 6);
        } elseif (str_starts_with($arg, '--only=')) {
            $opt['only'] = array_filter(array_map('trim', explode(',', substr($arg, 7))));
        } elseif (str_starts_with($arg, '--skip=')) {
            $opt['skip'] = array_filter(array_map('trim', explode(',', substr($arg, 7))));
        } elseif ($arg === '-h' || $arg === '--help') {
            fwrite(STDOUT, "Server / HumHub health check " . HC_VERSION . "\n\n"
                . "Usage: php health-check.php [options]\n\n"
                . "  -v, --verbose     show passing and skipped checks too\n"
                . "  -q, --quiet       no output, exit code only\n"
                . "      --json        JSON output\n"
                . "      --no-color    disable ANSI colours\n"
                . "      --env=PATH    path to the .env file (default: .env next to this script)\n"
                . "      --only=IDS    run only these check ids (comma separated)\n"
                . "      --skip=IDS    skip these check ids\n"
                . "      --list        list available check ids and exit\n\n"
                . "Exit codes: 0 = OK, 1 = warnings only, 2 = errors\n");
            exit(0);
        }
    }
    $opt['color'] = $opt['color'] && stream_isatty(STDOUT);
} else {
    $opt['json'] = (($_GET['format'] ?? '') === 'json');
    $opt['verbose'] = isset($_GET['verbose']);
    $opt['color'] = false;
}

$env = new Env();
$envFile = $opt['env'] ?? (__DIR__ . '/.env');
$envExists = is_file($envFile);
$envLoaded = $env->load($envFile);
// Exists but unreadable is a different problem from "not there at all": it
// usually means the file is owned by another user than the PHP process.
$envUnreadable = $envExists && !$envLoaded;

$report = new Report();

// =============================================================================
// HTTP access gate
// =============================================================================

if (!$isCli) {
    header('Content-Type: ' . ($opt['json'] ? 'application/json' : 'text/plain') . '; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    header('X-Robots-Tag: noindex, nofollow');
    header('X-Health-Check: instance');

    $token = $env->str('HEALTH_TOKEN');
    $allowed = $env->list('HEALTH_ALLOW_IPS');
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

    $ipOk = $allowed === [] || in_array($remote, $allowed, true);
    $provided = '';
    foreach ([$_GET['token'] ?? null, hc_request_header('X-Health-Token')] as $candidate) {
        if (is_string($candidate) && $candidate !== '') {
            $provided = $candidate;
            break;
        }
    }
    if ($provided === '' && preg_match('/^Bearer\s+(.+)$/i', hc_request_header('Authorization'), $m)) {
        $provided = trim($m[1]);
    }

    if ($token === '') {
        // Fail closed: an unconfigured token must never mean "open to the world".
        $why = $envUnreadable
            ? sprintf('%s exists but is not readable by %s (check owner/permissions)', $envFile, hc_process_user())
            : 'HEALTH_TOKEN is not configured in ' . $envFile;
        http_response_code(403);
        echo $opt['json']
            ? json_encode(['status' => 'forbidden', 'message' => $why], JSON_PRETTY_PRINT) . "\n"
            : "Forbidden: $why (HTTP access disabled)\n";
        exit(1);
    }
    // hash_equals over hashes so token length is not leaked. The reason is spelled
    // out because a bare 403 is painful to debug from a monitoring tool.
    if (!$ipOk) {
        $why = "client IP $remote is not in HEALTH_ALLOW_IPS";
    } elseif ($provided === '') {
        $why = 'no token supplied — send ?token=..., an X-Health-Token header, or Authorization: Bearer';
    } elseif (!hash_equals(hash('sha256', $token), hash('sha256', $provided))) {
        $why = 'token mismatch';
    } else {
        $why = null;
    }
    if ($why !== null) {
        http_response_code(403);
        echo $opt['json'] ? json_encode(['status' => 'forbidden', 'message' => $why]) . "\n" : "Forbidden: $why\n";
        exit(1);
    }
}

// =============================================================================
// Resolve the application: HumHub-aware, or generic for any other PHP app
// =============================================================================

// APP_TYPE=humhub  -> HumHub-specific checks are active (default)
// APP_TYPE=generic -> everything HumHub-specific is either skipped or driven
//                     purely by configuration, so the script works for any app.
$appType = strtolower($env->str('APP_TYPE', 'humhub'));
if (in_array($appType, ['none', 'other', 'plain', 'generic'], true)) {
    $appType = 'generic';
} elseif ($appType !== 'humhub') {
    $appType = 'humhub';
}
$isHumhubMode = $appType === 'humhub';

// APP_PATH is the generic name; HUMHUB_PATH is kept as an alias so existing
// .env files keep working.
$appPath = $env->str('APP_PATH');
if ($appPath === '') {
    $appPath = $env->str('HUMHUB_PATH');
}
$appPathConfigured = $appPath !== '';
if ($appPath === '') {
    // This script normally sits in the application root or one level below it
    // (e.g. <root>/health/health-check.php).
    foreach ([__DIR__, dirname(__DIR__), dirname(__DIR__, 2)] as $candidate) {
        if ($isHumhubMode ? is_file($candidate . '/protected/yii') : is_file($candidate . '/index.php')) {
            $appPath = $candidate;
            break;
        }
    }
}
$appPath = $appPath !== '' ? rtrim(hc_expand_home($appPath), '/') : '';

// Kept under the old names so the check closures read unchanged.
$humhubPath = $appPath;
$hasApp = $appPath !== '' && is_dir($appPath);
$hasHumhub = $isHumhubMode && $appPath !== '' && is_file($appPath . '/protected/yii');
$appLabel = $env->str('APP_NAME', $isHumhubMode ? 'HumHub' : 'application');

// HumHub version + declared PHP requirements, read from the installation itself.
$humhubVersion = null;
$humhubMinPhp = null;
$humhubRecommendedPhp = null;
if ($hasHumhub) {
    $commonConfig = $humhubPath . '/protected/humhub/config/common.php';
    if (is_readable($commonConfig)) {
        $src = (string) @file_get_contents($commonConfig);
        if (preg_match("/'version'\s*=>\s*'([^']+)'/", $src, $m)) {
            $humhubVersion = $m[1];
        }
        if (preg_match("/'minSupportedPhpVersion'\s*=>\s*'([^']+)'/", $src, $m)) {
            $humhubMinPhp = $m[1];
        }
        if (preg_match("/'minRecommendedPhpVersion'\s*=>\s*'([^']+)'/", $src, $m)) {
            $humhubRecommendedPhp = $m[1];
        }
    }
}
$humhubSeries = $humhubVersion !== null ? hc_minor($humhubVersion) : null;

// State file: lets the CLI run and the web run compare notes (PHP version, user,
// timezone) the same way HumHub's own SelfTest compares web vs cron.
$stateFile = $env->str('HEALTH_STATE_FILE');
if ($stateFile === '') {
    $stateDir = $env->str('HEALTH_STATE_DIR');
    if ($stateDir === '' && $hasHumhub && is_writable($humhubPath . '/protected/runtime')) {
        $stateDir = $humhubPath . '/protected/runtime';
    }
    $stateFile = ($stateDir !== '' && is_dir($stateDir) && is_writable($stateDir))
        ? rtrim(hc_expand_home($stateDir), '/') . '/health-check-state.json'
        : sys_get_temp_dir() . '/health-check-state-' . substr(sha1($appPath . __DIR__), 0, 10) . '.json';
}

// =============================================================================
// Checks
// =============================================================================

/** @var array<string,callable(Report):void> $checks */
$checks = [];

// --- Disk ---------------------------------------------------------------------
$checks['disk'] = function (Report $r) use ($env, $humhubPath): void {
    $paths = $env->list('DISK_PATHS', [$humhubPath !== '' ? $humhubPath : __DIR__]);
    $errorGb = $env->float('DISK_ERROR_FREE_GB', 5.0);
    $warnGb = $env->float('DISK_WARN_FREE_GB', 10.0);
    $errorPct = $env->float('DISK_ERROR_FREE_PERCENT', 3.0);
    $warnPct = $env->float('DISK_WARN_FREE_PERCENT', 8.0);

    foreach ($paths as $path) {
        $path = hc_expand_home($path);
        $free = @disk_free_space($path);
        $total = @disk_total_space($path);
        if ($free === false || $total === false || $total <= 0) {
            $r->error("Disk: cannot read free space for '$path'.", 'Check the path and open_basedir restrictions.');
            continue;
        }
        $freeGb = $free / (1024 ** 3);
        $pct = $free / $total * 100;
        $desc = sprintf("'%s': %.2f GB free of %.2f GB (%.1f%%)", $path, $freeGb, $total / (1024 ** 3), $pct);

        if ($freeGb < $errorGb || $pct < $errorPct) {
            $r->error("Low disk space on $desc.", sprintf('Error thresholds: %.1f GB / %.1f%%.', $errorGb, $errorPct));
        } elseif ($freeGb < $warnGb || $pct < $warnPct) {
            $r->warn("Disk space getting low on $desc.", sprintf('Warning thresholds: %.1f GB / %.1f%%.', $warnGb, $warnPct));
        } else {
            $r->ok("Disk space $desc.");
        }
    }

    // Inodes: a full inode table breaks uploads/assets while df -h looks fine.
    if ($env->bool('DISK_CHECK_INODES', true) && hc_can_exec()) {
        $path = hc_expand_home($paths[0] ?? __DIR__);
        if (hc_exec('df -Pi ' . escapeshellarg($path), $out) === 0) {
            $lines = explode("\n", (string) $out);
            $last = array_pop($lines);
            if ($last !== null && preg_match('/(\d+)%\s+\S*$/', $last, $m)) {
                $used = (int) $m[1];
                $max = $env->int('DISK_MAX_INODE_PERCENT', 90);
                if ($used >= $max) {
                    $r->error("Inode usage is $used% on '$path' (threshold: $max%).", 'Look for millions of small files (runtime/cache, logs, sessions).');
                } else {
                    $r->ok("Inode usage $used% on '$path'.");
                }
            }
        }
    }
};

// --- PHP version vs HumHub ----------------------------------------------------
$checks['php_version'] = function (Report $r) use ($env, $humhubVersion, $humhubSeries, $humhubMinPhp, $humhubRecommendedPhp, $isHumhubMode, $appLabel): void {
    $current = PHP_VERSION;
    $minor = hc_minor($current);

    // The HumHub support matrix only applies in HumHub mode; otherwise the range
    // comes entirely from PHP_MIN_VERSION / PHP_MAX_VERSION.
    $matrix = ($isHumhubMode && $humhubSeries !== null && isset(HC_HUMHUB_PHP_MATRIX[$humhubSeries])) ? HC_HUMHUB_PHP_MATRIX[$humhubSeries] : null;
    $configuredMin = $env->str('PHP_MIN_VERSION');
    $min = $isHumhubMode
        ? ($humhubMinPhp ?? ($matrix['min'] ?? ($configuredMin !== '' ? $configuredMin : '8.2')))
        : $configuredMin;
    $max = ($isHumhubMode ? ($matrix['max'] ?? null) : null) ?? ($env->str('PHP_MAX_VERSION') ?: null);
    $best = $matrix['best'] ?? [];
    if (!$isHumhubMode) {
        $humhubRecommendedPhp = null;
        if ($min === '' && $max === null) {
            $r->skip("PHP $current — no PHP_MIN_VERSION/PHP_MAX_VERSION configured, so no range to check.");
            return;
        }
        if ($min === '') {
            $min = '0';
        }
    }
    $label = $isHumhubMode
        ? ($humhubVersion !== null ? "HumHub $humhubVersion" : 'HumHub (version unknown)')
        : $appLabel;

    if (version_compare($minor, hc_minor($min), '<')) {
        $r->error("PHP $current is too old for $label (minimum: PHP $min).", 'https://docs.humhub.org/docs/admin/requirements');
        return;
    }
    if ($max !== null && version_compare($minor, hc_minor($max), '>')) {
        $r->error("PHP $current is newer than $label supports (maximum: PHP $max).", 'Downgrade the PHP version of the site or upgrade HumHub.');
        return;
    }
    if ($humhubRecommendedPhp !== null && version_compare($minor, hc_minor($humhubRecommendedPhp), '<')) {
        $r->warn("PHP $current is supported but below the version $label recommends (PHP $humhubRecommendedPhp).");
        return;
    }
    if ($best !== [] && !in_array($minor, $best, true)) {
        $r->warn("PHP $current is supported by $label, but " . implode('/', $best) . ' is recommended.');
        return;
    }
    $r->ok("PHP $current (SAPI " . PHP_SAPI . ") is supported by $label.");
};

// --- Required + recommended extensions ---------------------------------------
$checks['php_extensions'] = function (Report $r) use ($env, $isHumhubMode, $appLabel): void {
    // HumHub defaults from https://docs.humhub.org/docs/admin/requirements#extensions
    // In generic mode nothing is assumed: list what your app needs in
    // PHP_REQUIRED_EXTENSIONS.
    $required = $env->list('PHP_REQUIRED_EXTENSIONS', $isHumhubMode ? [
        'gd', 'curl', 'mbstring', 'pdo', 'pdo_mysql', 'zip',
        'exif', 'intl', 'fileinfo', 'json', 'iconv',
    ] : []);
    $recommended = $env->list('PHP_RECOMMENDED_EXTENSIONS', $isHumhubMode
        ? ['openssl', 'sodium', 'xml', 'apcu', 'ldap', 'Zend OPcache']
        : ['Zend OPcache']);

    $missing = array_values(array_filter($required, static fn($e) => !extension_loaded($e)));
    if ($required === []) {
        $r->skip('No PHP_REQUIRED_EXTENSIONS configured — extension presence not checked.');
    } elseif ($missing !== []) {
        $r->error('Missing required PHP extension(s): ' . implode(', ', $missing) . '.', "$appLabel will not work correctly without these.");
    } else {
        $r->ok('All required PHP extensions are loaded (' . implode(', ', $required) . ').');
    }

    $missingOpt = array_values(array_filter($recommended, static fn($e) => !extension_loaded($e)));
    if ($missingOpt !== []) {
        $r->warn('Optional PHP extension(s) not loaded: ' . implode(', ', $missingOpt) . '.', 'sodium = Mercure push, apcu = caching, ldap = LDAP auth, OPcache = performance.');
    }

    $wants = static fn(string $ext): bool => in_array($ext, $required, true) || in_array($ext, $recommended, true);

    // Image processing: GD needs JPEG + PNG support, not just to be present.
    if ($wants('gd') && extension_loaded('gd')) {
        $lacking = [];
        if (!function_exists('imagecreatefromjpeg')) {
            $lacking[] = 'JPEG';
        }
        if (!function_exists('imagecreatefrompng')) {
            $lacking[] = 'PNG';
        }
        if ($lacking !== []) {
            $r->error('GD is loaded but lacks ' . implode(' and ', $lacking) . ' support.', 'HumHub needs GD with JPEG and PNG support.');
        } else {
            $r->ok('GD has JPEG and PNG support.');
        }
    }
    if ($wants('gd') && !class_exists('Imagick', false) && !class_exists('Gmagick', false)) {
        $r->warn('Neither ImageMagick nor GraphicsMagick is available.', 'Optional, but gives better image processing than GD.');
    }

    // cURL must have SSL support.
    if (function_exists('curl_version')) {
        $v = curl_version();
        if (is_array($v) && defined('CURL_VERSION_SSL') && !($v['features'] & CURL_VERSION_SSL)) {
            $r->error('cURL is compiled without SSL support.');
        }
    }

    // intl / ICU
    if ($wants('intl') && extension_loaded('intl')) {
        $icu = defined('INTL_ICU_VERSION') ? INTL_ICU_VERSION : '0';
        if (version_compare($icu, '4.8.1', '<')) {
            $r->error("ICU version $icu is too old (minimum 4.8.1).");
        } elseif (version_compare($icu, '49', '<')) {
            $r->warn("ICU version $icu is below the ICU 49 recommended for Yii i18n.");
        } else {
            $r->ok("intl with ICU $icu.");
        }
    }

    // proc_open is needed for HumHub's isolated queue worker.
    if ($isHumhubMode && !function_exists('proc_open')) {
        $r->warn('proc_open() is disabled.', 'HumHub needs it for isolated queue jobs; otherwise run queue/run with --isolate=0.');
    }
};

// --- PHP ini settings ---------------------------------------------------------
$checks['php_settings'] = function (Report $r) use ($env, $isCli): void {
    $limit = (string) ini_get('memory_limit');
    $bytes = hc_ini_bytes($limit);
    $minMb = $env->int('PHP_MIN_MEMORY_LIMIT_MB', 128);
    $hardMinMb = 64; // HumHub's own minimum
    if ($limit === '-1' || $bytes === -1) {
        $r->ok('PHP memory_limit is unlimited.');
    } elseif ($bytes === null) {
        $r->warn("Could not parse memory_limit value '$limit'.");
    } elseif ($bytes < $hardMinMb * 1024 * 1024) {
        $r->error("PHP memory_limit is $limit, below HumHub's minimum of {$hardMinMb}M.");
    } elseif ($bytes < $minMb * 1024 * 1024) {
        $r->warn("PHP memory_limit is $limit (recommended at least {$minMb}M).", 'Module installation and updates can need more.');
    } else {
        $r->ok("PHP memory_limit is $limit.");
    }

    if (!$isCli) {
        $maxExec = (int) ini_get('max_execution_time');
        $minExec = $env->int('PHP_MIN_MAX_EXECUTION_TIME', 30);
        if ($maxExec !== 0 && $maxExec < $minExec) {
            $r->warn("max_execution_time is {$maxExec}s (recommended at least {$minExec}s).", 'Too low breaks uploads, migrations and module installs.');
        } else {
            $r->ok('max_execution_time is ' . ($maxExec === 0 ? 'unlimited' : $maxExec . 's') . '.');
        }

        if (!ini_get('file_uploads')) {
            $r->error('file_uploads is disabled — file and image uploads will fail.');
        }
        $upload = hc_ini_bytes((string) ini_get('upload_max_filesize'));
        $post = hc_ini_bytes((string) ini_get('post_max_size'));
        $minUploadMb = $env->int('PHP_MIN_UPLOAD_MB', 32);
        if ($upload !== null && $upload < $minUploadMb * 1024 * 1024) {
            $r->warn('upload_max_filesize is ' . ini_get('upload_max_filesize') . " (recommended at least {$minUploadMb}M).");
        }
        if ($upload !== null && $post !== null && $post > 0 && $post < $upload) {
            $r->warn('post_max_size (' . ini_get('post_max_size') . ') is smaller than upload_max_filesize (' . ini_get('upload_max_filesize') . ').', 'Large uploads will be silently truncated.');
        }
        if ($env->bool('PHP_CHECK_DISPLAY_ERRORS', true) && filter_var(ini_get('display_errors'), FILTER_VALIDATE_BOOL)) {
            $r->warn('display_errors is On for the web SAPI.', 'Leaks paths and stack traces to visitors — turn it off in production.');
        }
    }

    if ($isCli) {
        $r->skip('Web-only ini checks (max_execution_time, upload limits, display_errors) are only evaluated over HTTP.');
    }

    if ((string) ini_get('date.timezone') === '') {
        $r->warn('date.timezone is not set in php.ini (falling back to ' . date_default_timezone_get() . ').');
    }
};

// --- OPcache ------------------------------------------------------------------
$checks['opcache'] = function (Report $r) use ($env, $isCli): void {
    if (!extension_loaded('Zend OPcache')) {
        $r->warn('OPcache is not installed.', 'Strongly recommended for PHP performance.');
        return;
    }
    if (!filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOL)) {
        $r->warn('OPcache is installed but disabled (opcache.enable=0).');
        return;
    }
    if ($isCli && !filter_var(ini_get('opcache.enable_cli'), FILTER_VALIDATE_BOOL)) {
        $r->skip('OPcache runtime stats unavailable from CLI (opcache.enable_cli=0).');
        return;
    }
    if (!function_exists('opcache_get_status')) {
        $r->ok('OPcache is enabled (status function disabled).');
        return;
    }
    $status = @opcache_get_status(false);
    if (!is_array($status)) {
        $r->ok('OPcache is enabled.');
        return;
    }
    $mem = $status['memory_usage'] ?? [];
    $used = (float) ($mem['used_memory'] ?? 0);
    $free = (float) ($mem['free_memory'] ?? 0);
    $wasted = (float) ($mem['wasted_memory'] ?? 0);
    $total = $used + $free + $wasted;
    $pct = $total > 0 ? $used / $total * 100 : 0.0;
    $full = !empty($status['cache_full']);
    $oom = (int) ($status['opcache_statistics']['oom_restarts'] ?? 0);
    $maxPct = $env->float('OPCACHE_MAX_USED_PERCENT', 90.0);

    if ($full || $oom > 0) {
        $r->warn(sprintf('OPcache pressure: cache_full=%s, out-of-memory restarts=%d, %.1f%% used of %s.', $full ? 'yes' : 'no', $oom, $pct, hc_bytes_human($total)), 'Increase opcache.memory_consumption / opcache.max_accelerated_files.');
    } elseif ($pct > $maxPct) {
        $r->warn(sprintf('OPcache memory %.1f%% used of %s (threshold %.0f%%).', $pct, hc_bytes_human($total), $maxPct));
    } else {
        $r->ok(sprintf('OPcache enabled, %.1f%% of %s used.', $pct, hc_bytes_human($total)));
    }
};

// --- System load --------------------------------------------------------------
$checks['load'] = function (Report $r) use ($env): void {
    if (!function_exists('sys_getloadavg')) {
        $r->skip('Load average not available on this platform.');
        return;
    }
    $load = sys_getloadavg();
    if (!is_array($load) || !isset($load[0])) {
        $r->skip('Load average could not be read.');
        return;
    }
    $cores = hc_cpu_count();
    $perCore = $load[0] / $cores;
    $warnAt = $env->float('LOAD_WARN_PER_CORE', 2.0);
    $errorAt = $env->float('LOAD_ERROR_PER_CORE', 4.0);
    $desc = sprintf('%.2f / %.2f / %.2f over %d core(s) = %.2f per core', $load[0], $load[1], $load[2], $cores, $perCore);

    if ($perCore > $errorAt) {
        $r->error("Very high system load: $desc.", sprintf('Error threshold: %.1f per core.', $errorAt));
    } elseif ($perCore > $warnAt) {
        $r->warn("High system load: $desc.", sprintf('Warning threshold: %.1f per core.', $warnAt));
    } else {
        $r->ok("Load average $desc.");
    }
};

// --- System memory ------------------------------------------------------------
$checks['memory'] = function (Report $r) use ($env): void {
    if (!is_readable('/proc/meminfo')) {
        $r->skip('/proc/meminfo not readable (restricted environment).');
        return;
    }
    $data = (string) @file_get_contents('/proc/meminfo');
    if (!preg_match('/MemTotal:\s+(\d+)/', $data, $t) || !preg_match('/MemAvailable:\s+(\d+)/', $data, $a) || (int) $t[1] === 0) {
        $r->skip('Could not parse /proc/meminfo.');
        return;
    }
    $totalKb = (int) $t[1];
    $availKb = (int) $a[1];
    $pct = $availKb / $totalKb * 100;
    $warnAt = $env->float('MEMORY_WARN_FREE_PERCENT', 10.0);
    $errorAt = $env->float('MEMORY_ERROR_FREE_PERCENT', 5.0);
    $desc = sprintf('%.1f%% available (%s of %s)', $pct, hc_bytes_human($availKb * 1024), hc_bytes_human($totalKb * 1024));

    if ($pct < $errorAt) {
        $r->error("Very low system memory: $desc.");
    } elseif ($pct < $warnAt) {
        $r->warn("Low system memory: $desc.");
    } else {
        $r->ok("System memory $desc.");
    }

    // Swap thrashing is often the real cause of "the site is slow".
    if (preg_match('/SwapTotal:\s+(\d+)/', $data, $st) && preg_match('/SwapFree:\s+(\d+)/', $data, $sf) && (int) $st[1] > 0) {
        $swapUsedPct = ((int) $st[1] - (int) $sf[1]) / (int) $st[1] * 100;
        $swapWarn = $env->float('SWAP_WARN_USED_PERCENT', 50.0);
        if ($swapUsedPct > $swapWarn) {
            $r->warn(sprintf('Swap is %.1f%% used (threshold %.0f%%).', $swapUsedPct, $swapWarn));
        }
    }
};

// --- Temp / session directories ----------------------------------------------
$checks['temp_dirs'] = function (Report $r) use ($env): void {
    $paths = [sys_get_temp_dir()];
    $session = hc_session_path();
    if ($session !== null) {
        $paths[] = $session;
    }
    foreach ($env->list('EXTRA_WRITABLE_PATHS') as $extra) {
        $paths[] = hc_expand_home($extra);
    }
    foreach (array_unique($paths) as $path) {
        $problem = hc_write_test($path);
        if ($problem !== null) {
            $r->error("Temp/session path '$path': $problem.");
        } else {
            $r->ok("Writable: $path");
        }
    }
};

// --- HumHub installation present ---------------------------------------------
$checks['app_install'] = function (Report $r) use ($env, $humhubPath, $hasApp, $hasHumhub, $humhubVersion, $isHumhubMode, $appLabel, $appPathConfigured): void {
    // Generic mode: only verify what the .env actually declares.
    if (!$isHumhubMode) {
        // No APP_PATH at all is a legitimate configuration: the script is being
        // used for server-level checks only (disk, load, memory, PHP, cron).
        if (!$appPathConfigured && !$hasApp) {
            $r->skip('No APP_PATH configured — application-level checks skipped (server checks still run).');
            return;
        }
        if ($humhubPath === '' || !$hasApp) {
            $r->error('Application directory not found' . ($humhubPath !== '' ? " at '$humhubPath'" : '') . '.', 'Set APP_PATH in .env to the application root.');
            return;
        }
        $r->ok("$appLabel found at $humhubPath.");
        $required = $env->list('APP_REQUIRED_FILES');
        if ($required === []) {
            $r->skip('No APP_REQUIRED_FILES configured — nothing else to verify.');
            return;
        }
        foreach ($required as $rel) {
            if (file_exists($humhubPath . '/' . ltrim($rel, '/'))) {
                $r->ok("Present: $rel");
            } else {
                $r->error("Missing required file or directory: $rel");
            }
        }
        return;
    }

    if (!$hasHumhub) {
        $r->error('HumHub installation not found' . ($humhubPath !== '' ? " at '$humhubPath'" : '') . '.', 'Set APP_PATH in .env to the directory containing index.php and protected/ — or set APP_TYPE=generic if this is not a HumHub server.');
        return;
    }
    $r->ok('HumHub ' . ($humhubVersion ?? 'version unknown') . " found at $humhubPath.");

    foreach (['index.php', 'protected/yii', 'protected/vendor/autoload.php'] as $rel) {
        if (!file_exists($humhubPath . '/' . $rel)) {
            $r->error("Missing $rel in the HumHub directory.", $rel === 'protected/vendor/autoload.php' ? 'Run composer install.' : null);
        }
    }
    if (!is_file($humhubPath . '/protected/config/dynamic.php')) {
        $r->warn('protected/config/dynamic.php is missing — HumHub is not installed yet (installer never completed) or the config was lost.');
    }
    if (is_file($humhubPath . '/protected/config/common.php')) {
        $r->ok('Custom configuration protected/config/common.php is present.');
    }
    // Forgetting to copy .htaccess.dist is a classic: the site works, then every
    // pretty URL 404s.
    if (is_file($humhubPath . '/.htaccess.dist') && !is_file($humhubPath . '/.htaccess')) {
        $r->warn('.htaccess.dist exists but .htaccess does not.', 'On Apache, copy .htaccess.dist to .htaccess or pretty URLs will 404. On nginx, ignore (set HEALTH_SKIP_CHECKS if noisy).');
    }
};

// --- HumHub writable directories (file permissions) ---------------------------
$checks['app_permissions'] = function (Report $r) use ($env, $humhubPath, $hasApp, $hasHumhub, $isHumhubMode): void {
    if (!$hasApp) {
        $r->skip('Application path unknown — permission checks skipped.');
        return;
    }
    // In HumHub mode the defaults come from
    // https://docs.humhub.org/docs/admin/installation#file-permissions plus the
    // directories HumHub's own SelfTest verifies. In generic mode there are no
    // defaults: list your own in APP_WRITABLE_DIRS.
    $defaults = ($isHumhubMode && $hasHumhub) ? [
        'assets',
        'uploads',
        'uploads/file',
        'uploads/profile_image',
        'protected/config',
        'protected/modules',
        'protected/runtime',
    ] : [];
    $dirs = $env->list('APP_WRITABLE_DIRS', $env->list('HUMHUB_WRITABLE_DIRS', $defaults));
    if ($dirs === []) {
        $r->skip('No APP_WRITABLE_DIRS configured — writability of application directories not checked.');
        return;
    }
    $bad = [];
    foreach ($dirs as $rel) {
        $path = $humhubPath . '/' . trim($rel, '/');
        $problem = hc_write_test($path);
        if ($problem !== null) {
            $bad[$problem][] = $rel;
        }
    }
    if ($bad !== []) {
        $parts = [];
        foreach ($bad as $problem => $rels) {
            $parts[] = implode(', ', $rels) . " ($problem)";
        }
        $r->error(
            'Application directories are not writable: ' . implode('; ', $parts) . '.',
            $hasHumhub ? 'https://docs.humhub.org/docs/admin/installation#file-permissions' : 'They must be writable by the PHP user (' . hc_process_user() . ').'
        );
    } else {
        $r->ok('All required directories are writable (' . implode(', ', $dirs) . ').');
    }

    // Ownership mismatch: works today, breaks on the next file the app creates.
    $ownerRef = $humhubPath . '/' . ltrim((string) ($dirs[0] ?? ''), '/');
    if ($hasHumhub) {
        $ownerRef = $humhubPath . '/protected/runtime';
    }
    $owner = hc_owner_name($ownerRef);
    $user = hc_process_user();
    if ($owner !== 'unknown' && $user !== 'unknown' && $owner !== $user) {
        $r->warn(basename($ownerRef) . " is owned by '$owner' but PHP runs as '$user'.", 'Writability then depends on group/other bits — fragile. chown the tree to the PHP user.');
    }

    if ($env->bool('CHECK_ROOT_WRITABLE', $env->bool('CHECK_UPDATER_WRITABLE', false))) {
        $problem = hc_write_test($humhubPath);
        if ($problem !== null) {
            $r->warn("The application root is not writable ($problem)" . ($hasHumhub ? ' — HumHub\'s built-in updater will not work.' : '.'));
        }
    }
};

// --- Security-relevant permissions & web exposure -----------------------------
$checks['security'] = function (Report $r) use ($env, $humhubPath, $hasApp, $hasHumhub, $envLoaded, $envUnreadable, $envFile): void {
    // Files whose absence means the web server is not blocking a sensitive path.
    // HumHub ships these; other apps declare their own in REQUIRED_DENY_FILES.
    $denyFiles = $hasHumhub
        ? ['protected/.htaccess' => 'blocks web access to protected/', 'uploads/file/.htaccess' => 'blocks PHP execution in uploads/file']
        : [];
    foreach ($env->list('REQUIRED_DENY_FILES') as $rel) {
        $denyFiles[$rel] = 'declared in REQUIRED_DENY_FILES';
    }
    if ($hasApp) {
        foreach ($denyFiles as $rel => $why) {
            if (!is_file($humhubPath . '/' . ltrim((string) $rel, '/'))) {
                $r->error("Missing $rel ($why).", 'Restore it, or replicate the rule in your nginx/Apache configuration.');
            }
        }

        // Config files holding credentials must not be readable by everyone.
        // dynamic.php holds HumHub's DB password; add your own with
        // SENSITIVE_FILES=config/db.php,.env.local
        $sensitive = $env->list('SENSITIVE_FILES', $hasHumhub ? ['protected/config/dynamic.php'] : []);
        foreach ($sensitive as $rel) {
            $path = $humhubPath . '/' . ltrim($rel, '/');
            if (!is_file($path)) {
                continue;
            }
            $perms = hc_perms($path);
            if ($perms !== null && ($perms & 0002)) {
                $r->error(sprintf('%s is world-writable (%04o).', $rel, $perms), "chmod 600 $rel");
            } elseif ($perms !== null && ($perms & 0004)) {
                $r->warn(sprintf('%s is world-readable (%04o) and may contain credentials.', $rel, $perms), 'chmod 600 (or 640 with the web group) is safer on shared hosting.');
            } else {
                $r->ok(sprintf('%s permissions are %04o.', $rel, $perms ?? 0));
            }
        }
    }

    // This script's own .env.
    if ($envUnreadable) {
        $r->error("$envFile exists but is not readable by " . hc_process_user() . ' — all defaults in use.', 'chown it to the PHP user (or use group-readable 640). The web request will otherwise always be refused.');
    } elseif (!$envLoaded) {
        $r->warn("No .env file loaded (looked for $envFile) — all defaults in use.", 'Copy .env.example to .env and adjust it.');
    } else {
        $perms = hc_perms((string) $envFile);
        if ($perms !== null && ($perms & 0044)) {
            $r->warn(sprintf('%s is readable beyond its owner (%04o) and holds HEALTH_TOKEN.', basename((string) $envFile), $perms), 'chmod 600 ' . $envFile);
        } else {
            $r->ok('.env permissions are ' . sprintf('%04o', $perms ?? 0) . '.');
        }
    }
    if ($env->str('HEALTH_TOKEN') !== '' && strlen($env->str('HEALTH_TOKEN')) < 24) {
        $r->warn('HEALTH_TOKEN is shorter than 24 characters.', 'Generate one with: openssl rand -hex 32');
    }

    // If this script lives inside the webroot (so a monitor can reach it), the
    // directory needs its own dotfile deny rule. HumHub's root .htaccess only
    // blocks dotfiles at the root level, not in subdirectories.
    if ($hasHumhub && str_starts_with(__DIR__ . '/', $humhubPath . '/') && __DIR__ !== $humhubPath) {
        if (!is_file(__DIR__ . '/.htaccess')) {
            $r->warn('No .htaccess in ' . __DIR__ . ' — .env may be downloadable.', 'Ship the provided .htaccess (Apache) or add a deny rule for .env in the nginx config.');
        } else {
            $rules = (string) @file_get_contents(__DIR__ . '/.htaccess');
            if (!str_contains($rules, '.env')) {
                $r->warn('The .htaccess in ' . __DIR__ . ' does not mention .env.', 'Make sure it denies access to the .env file.');
            } else {
                $r->ok('Local .htaccess denies access to .env.');
            }
        }
    }
};

// --- Web exposure self-test (outbound HTTP) -----------------------------------
$checks['web_exposure'] = function (Report $r) use ($env, $isCli, $humhubPath, $hasHumhub): void {
    $mode = strtolower($env->str('WEB_EXPOSURE_CHECK', 'cli')); // off | cli | always
    if ($mode === 'off') {
        $r->skip('Web exposure self-test disabled.');
        return;
    }
    if ($mode === 'cli' && !$isCli) {
        // Calling ourselves over HTTP from inside an FPM worker can deadlock a
        // small pool, so by default this only runs from cron.
        $r->skip('Web exposure self-test runs from CLI only (WEB_EXPOSURE_CHECK=always to change).');
        return;
    }
    $baseUrl = rtrim($env->str('BASE_URL'), '/');
    if ($baseUrl === '') {
        $r->skip('BASE_URL not set — web exposure self-test skipped.');
        return;
    }
    if (!function_exists('curl_init')) {
        $r->skip('cURL unavailable — web exposure self-test skipped.');
        return;
    }
    $paths = $env->list('WEB_EXPOSURE_PATHS', $hasHumhub ? [
        '/protected/config/dynamic.php',
        '/protected/humhub/config/common.php',
    ] : []);
    // Also probe this script's own .env. If the path is not configured, derive it
    // from the script location relative to the HumHub root (= the webroot).
    $selfEnvPath = $env->str('WEB_EXPOSURE_ENV_PATH');
    if ($selfEnvPath === '' && $humhubPath !== '' && str_starts_with(__DIR__ . '/', $humhubPath . '/')) {
        $selfEnvPath = rtrim(substr(__DIR__, strlen($humhubPath)), '/') . '/.env';
    }
    if ($selfEnvPath !== '') {
        $paths[] = $selfEnvPath;
    }

    $paths = array_values(array_unique(array_filter($paths)));
    if ($paths === []) {
        $r->skip('No WEB_EXPOSURE_PATHS configured — nothing to probe.');
        return;
    }
    $timeout = $env->int('WEB_EXPOSURE_TIMEOUT', 5);
    foreach ($paths as $path) {
        $url = $baseUrl . '/' . ltrim($path, '/');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT => 'humhub-health-check/' . HC_VERSION,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false && $code === 0) {
            $r->warn("Web exposure test could not reach $url ($err).");
            continue;
        }
        $isPhp = str_ends_with(strtolower(parse_url($path, PHP_URL_PATH) ?: ''), '.php');

        if ($code === 200 && trim((string) $body) !== '') {
            $r->error("$path is publicly downloadable (HTTP 200, " . strlen((string) $body) . ' bytes)!', 'Fix the web server rules now — this can leak credentials.');
        } elseif ($code === 200 && $isPhp) {
            // Empty body = PHP executed it. Nothing leaked this time, but the
            // deny rule is not active, so any non-PHP file there would be served.
            $r->warn("$path is reachable (HTTP 200, executed by PHP) instead of returning 403/404.", 'The deny rule for protected/ is not active — other files in that directory would be served as plain text.');
        } elseif (in_array($code, [403, 404, 410], true)) {
            $r->ok("$path is not served (HTTP $code).");
        } else {
            $r->warn("$path returns HTTP $code instead of 403/404 — verify the web server rules.");
        }
    }
};

// --- Crontab: entries + PHP binary consistency --------------------------------
$checks['cron'] = function (Report $r) use ($env, $humhubPath, $hasHumhub, $stateFile): void {
    $source = null;
    $crontab = null;
    $file = $env->str('CRONTAB_FILE');
    if ($file !== '' && is_readable(hc_expand_home($file))) {
        $crontab = (string) @file_get_contents(hc_expand_home($file));
        $source = hc_expand_home($file);
    } elseif (hc_can_exec() && hc_exec('crontab -l', $out) === 0 && (string) $out !== '') {
        $crontab = (string) $out;
        $source = 'crontab -l';
    }
    if ($crontab === null) {
        $r->warn('Could not read the crontab.', 'Set CRONTAB_FILE in .env, or allow exec() so `crontab -l` can be used.');
        return;
    }

    $userField = $env->bool('CRONTAB_USER_FIELD', $source !== null && str_contains($source, '/etc/cron'));
    $jobs = hc_cron_jobs($crontab, $userField);
    if ($jobs === []) {
        $r->warn("No cron jobs found in $source.");
        return;
    }

    // Which PHP version is the reference? The web SAPI, because that is what
    // serves the application — every scheduled PHP command should match it.
    $reference = $env->str('PHP_EXPECTED_VERSION');
    $refSource = 'PHP_EXPECTED_VERSION';
    if ($reference === '') {
        $state = is_readable($stateFile) ? json_decode((string) @file_get_contents($stateFile), true) : null;
        foreach (['fpm-fcgi', 'cgi-fcgi', 'apache2handler', 'litespeed', 'cli-server'] as $sapi) {
            if (is_array($state) && isset($state[$sapi]['version'])) {
                $reference = (string) $state[$sapi]['version'];
                $refSource = "web SAPI ($sapi, last seen " . date('Y-m-d H:i', (int) ($state[$sapi]['ts'] ?? 0)) . ')';
                break;
            }
        }
    }
    if ($reference === '') {
        $reference = PHP_VERSION;
        $refSource = 'this process (' . PHP_SAPI . ')';
    }

    $target = $hasHumhub ? realpath($humhubPath . '/protected/yii') : false;
    $ownJobs = [];
    $foreignJobs = [];
    $yiiActions = [];

    foreach ($jobs as $job) {
        $job['php'] = hc_cron_php_binaries($job['tokens']);
        $job['yii'] = hc_cron_yii_calls($job['tokens'], $job['cwd']);

        // Does this job belong to the installation we are checking?
        $isOwn = false;
        if (!$hasHumhub) {
            $isOwn = $job['yii'] !== []; // no HumHub path known: treat any yii job as ours
        } else {
            foreach ($job['yii'] as $call) {
                if ($call['path'] !== null && $target !== false && realpath($call['path']) === $target) {
                    $isOwn = true;
                }
            }
            // Jobs that merely operate inside the installation (backups, custom
            // scripts, composer) count as ours too — they must use the same PHP.
            if (!$isOwn && (str_starts_with((string) $job['cwd'], $humhubPath) || str_contains($job['command'], $humhubPath))) {
                $isOwn = true;
            }
        }

        if ($isOwn) {
            $ownJobs[] = $job;
            foreach ($job['yii'] as $call) {
                if ($call['action'] !== '') {
                    $yiiActions[] = $call['action'];
                }
            }
        } else {
            $foreignJobs[] = $job;
        }
    }

    if ($ownJobs === []) {
        $known = [];
        foreach ($jobs as $job) {
            foreach ($job['yii'] as $call) {
                $known[] = (string) $call['raw'];
            }
        }
        $hint = $known === []
            ? ($hasHumhub ? 'https://docs.humhub.org/docs/admin/cron-jobs' : 'If this application has no scheduled tasks, skip this check with HEALTH_SKIP_CHECKS=cron.')
            : 'Other yii commands found: ' . implode(', ', array_unique($known)) . '. Stale path after a rename or move?';
        $message = 'No cron jobs found for this installation' . ($humhubPath !== '' ? " ($humhubPath)" : '') . '.';
        // Without cron HumHub is broken; another app may legitimately have none.
        if ($hasHumhub) {
            $r->error($message, $hint);
        } else {
            $r->warn($message, $hint);
        }
        return;
    }
    if ($foreignJobs !== []) {
        $r->ok(count($foreignJobs) . ' unrelated cron job(s) ignored (other sites or non-HumHub tasks).');
    }

    // --- Both HumHub commands must be scheduled -------------------------------
    foreach ($env->list('CRON_REQUIRED_ACTIONS', $hasHumhub ? ['cron/run', 'queue/run'] : []) as $needed) {
        if (in_array($needed, $yiiActions, true)) {
            $r->ok("Cron job for `$needed` found.");
            continue;
        }
        $hint = $needed === 'queue/run'
            ? 'Unless a job runner (supervisor/systemd) handles the queue, add it to the crontab.'
            : 'Without cron/run, summary mails, notifications and scheduled cleanups never run.';
        $r->warn("No cron job for `$needed`.", $hint . ' https://docs.humhub.org/docs/admin/cron-jobs');
    }

    // --- Every PHP interpreter used by our jobs must match the web version ----
    $requirePinned = $env->bool('CRON_REQUIRE_PINNED_PHP', true);
    $seen = [];      // binary => resolved version
    $unpinned = [];  // jobs relying on whatever `php` is in cron's PATH

    foreach ($ownJobs as $job) {
        $label = $job['yii'] !== []
            ? implode('/', array_filter(array_column($job['yii'], 'action')))
            : 'cron job';
        if ($label === '') {
            $label = 'cron job';
        }

        if ($job['php'] === []) {
            // A yii call with no interpreter in front of it (./protected/yii) relies
            // on the shebang, which points at whichever php the system default is.
            foreach ($job['yii'] as $call) {
                if ($call['interpreted'] === false) {
                    $r->warn("`{$call['raw']}` is executed without an explicit PHP binary ($label).", 'The shebang resolves to the system default PHP, which is rarely the version the site runs. Prefix the command with the full path to the matching PHP binary.');
                }
            }
            continue;
        }

        foreach ($job['php'] as $php) {
            $bin = $php['bin'];
            $pinned = $php['pinned'];

            if ($pinned === null) {
                $unpinned[$bin] = true;
            }

            // A missing binary means the job has been failing silently on every
            // run — report that rather than the version pinned in its path.
            if (str_contains($bin, '/')) {
                if (!is_file($bin)) {
                    $r->error("Cron PHP binary '$bin' ($label) does not exist — this job fails on every run.", 'Hosting providers retire PHP paths on upgrade; update the crontab.');
                    continue;
                }
                if (!is_executable($bin)) {
                    $r->error("Cron PHP binary '$bin' ($label) is not executable.");
                    continue;
                }
            }

            // Resolve the actual version, once per binary.
            if (!array_key_exists($bin, $seen)) {
                $seen[$bin] = hc_php_binary_version($bin, $pinned);
            }
            $resolved = $seen[$bin];

            if ($resolved === null) {
                if (!str_contains($bin, '/')) {
                    $r->warn("Cron uses `$bin` from PATH ($label) and its version could not be determined.", 'Use an absolute path to the PHP binary the site runs on.');
                } else {
                    $r->warn("Could not determine the PHP version of '$bin' ($label).", hc_can_exec() ? null : 'exec() is disabled, so the binary cannot be queried; pin the version in the path instead.');
                }
                continue;
            }

            // The path pins one version but the binary reports another: a symlink
            // or the provider moved the version behind that path.
            if ($pinned !== null && $resolved['from'] === 'exec' && hc_minor($resolved['version']) !== hc_minor($pinned)) {
                $r->warn("'$bin' pins PHP $pinned in its path but reports PHP {$resolved['version']}.", 'Symlinked or repointed by the host — do not trust the path alone.');
            }

            if (hc_minor($resolved['version']) !== hc_minor($reference)) {
                $r->error(
                    sprintf('Cron uses PHP %s (%s, %s) but the web application runs PHP %s.', $resolved['version'], $bin, $label, hc_minor($reference)),
                    "Reference: $refSource. Mixed versions cause broken caches, asset and migration errors — this is HumHub's own \"Web Application and Cron uses the same PHP version\" check."
                );
            } else {
                $r->ok(sprintf('Cron PHP %s (%s, %s) matches the web PHP version (%s).', $resolved['version'], $bin, $label, $refSource));
            }
        }
    }

    if ($requirePinned && $unpinned !== []) {
        $r->warn('Cron job(s) call PHP without an explicit version: ' . implode(', ', array_keys($unpinned)) . '.', "Cron's PATH is minimal and the default `php` often points at an older version than the site uses. Use the full path, e.g. /opt/php8.4/bin/php.");
    }
    if (count($seen) > 1) {
        $r->warn('Different PHP binaries are used across this installation\'s cron jobs: ' . implode(', ', array_keys($seen)) . '.');
    }

    // --- Optional: cron/queue output logs should be recent --------------------
    foreach (['CRON_LOG_FILE' => 'cron/run', 'QUEUE_LOG_FILE' => 'queue/run'] as $key => $label) {
        $logFile = $env->str($key);
        if ($logFile === '') {
            continue;
        }
        $logFile = hc_expand_home($logFile);
        if (!is_file($logFile)) {
            $r->warn("$label log file '$logFile' does not exist.", 'Redirect the cron output to it so staleness can be detected.');
            continue;
        }
        $age = time() - (int) @filemtime($logFile);
        $maxAge = $env->int('CRON_LOG_MAX_AGE_MINUTES', 15) * 60;
        if ($age > $maxAge) {
            $r->error(sprintf('%s log has not been written for %d minute(s) — the job is probably not running.', $label, (int) round($age / 60)));
        } else {
            $r->ok(sprintf('%s ran %d minute(s) ago.', $label, (int) round($age / 60)));
        }
    }
};

// --- Web vs CLI consistency (version, user, timezone) ------------------------
$checks['sapi_consistency'] = function (Report $r) use ($env, $stateFile): void {
    $now = time();
    $record = [
        'version' => PHP_VERSION,
        'user' => hc_process_user(),
        'timezone' => date_default_timezone_get(),
        'ts' => $now,
    ];
    $state = is_readable($stateFile) ? json_decode((string) @file_get_contents($stateFile), true) : null;
    if (!is_array($state)) {
        $state = [];
    }
    $state[PHP_SAPI] = $record;
    if (@file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT)) === false) {
        $r->skip("Could not write the state file '$stateFile' — web/CLI comparison unavailable.");
        return;
    }
    @chmod($stateFile, 0640);

    $maxAge = $env->int('STATE_MAX_AGE_DAYS', 30) * 86400;
    $others = array_filter($state, static fn($v, $k) => $k !== PHP_SAPI && is_array($v), ARRAY_FILTER_USE_BOTH);
    if ($others === []) {
        $r->skip('Only the ' . PHP_SAPI . ' SAPI has reported so far. Run this script from both cron and the web to compare them.');
        return;
    }
    foreach ($others as $sapi => $other) {
        if ($now - (int) ($other['ts'] ?? 0) > $maxAge) {
            $r->warn("Stale data for the $sapi SAPI (last seen " . date('Y-m-d', (int) ($other['ts'] ?? 0)) . ') — not comparing.');
            continue;
        }
        if (hc_minor((string) $other['version']) !== hc_minor(PHP_VERSION)) {
            $r->error(sprintf('PHP version mismatch: %s runs %s, %s runs %s.', PHP_SAPI, PHP_VERSION, $sapi, $other['version']), 'Web and cron/CLI must use the same PHP version.');
        } else {
            $r->ok(sprintf('%s and %s both run PHP %s.', PHP_SAPI, $sapi, hc_minor(PHP_VERSION)));
        }
        if ((string) $other['user'] !== hc_process_user()) {
            $r->warn(sprintf('Different OS users: %s runs as %s, %s runs as %s.', PHP_SAPI, hc_process_user(), $sapi, $other['user']), 'Files created by one may not be writable by the other.');
        }
        if ((string) ($other['timezone'] ?? '') !== date_default_timezone_get()) {
            $r->warn(sprintf('Different default timezones: %s uses %s, %s uses %s.', PHP_SAPI, date_default_timezone_get(), $sapi, $other['timezone']));
        }
    }
};

// --- HumHub runtime logs ------------------------------------------------------
$checks['logs'] = function (Report $r) use ($env, $humhubPath, $hasApp, $hasHumhub): void {
    // LOG_DIR may be absolute, or relative to the application root. HumHub's
    // default is protected/runtime/logs.
    $logDir = $env->str('LOG_DIR', $hasHumhub ? 'protected/runtime/logs' : '');
    if ($logDir === '') {
        $r->skip('No LOG_DIR configured — log checks skipped.');
        return;
    }
    $logDir = hc_expand_home($logDir);
    if (!str_starts_with($logDir, '/')) {
        if (!$hasApp) {
            $r->skip('LOG_DIR is relative but the application path is unknown — log checks skipped.');
            return;
        }
        $logDir = $humhubPath . '/' . ltrim($logDir, '/');
    }
    $logDir = rtrim($logDir, '/');
    if (!is_dir($logDir)) {
        $r->skip("Log directory '$logDir' does not exist.");
        return;
    }
    $maxMb = $env->int('LOG_MAX_TOTAL_MB', 512);
    $total = 0;
    foreach (glob($logDir . '/*') ?: [] as $f) {
        if (is_file($f)) {
            $total += (int) @filesize($f);
        }
    }
    if ($total > $maxMb * 1024 * 1024) {
        $r->warn('Log directory holds ' . hc_bytes_human((float) $total) . " (threshold {$maxMb} MB).", "Rotate or truncate $logDir — runaway logs fill the disk.");
    } else {
        $r->ok('Log directory size ' . hc_bytes_human((float) $total) . '.');
    }

    $appLog = $logDir . '/' . $env->str('LOG_FILE', 'app.log');
    $window = $env->int('LOG_ERROR_WINDOW_MINUTES', 60);
    $threshold = $env->int('LOG_ERROR_THRESHOLD', 25);
    if ($window <= 0 || !is_readable($appLog)) {
        return;
    }
    $size = (int) @filesize($appLog);
    $tailBytes = min($size, $env->int('LOG_TAIL_KB', 512) * 1024);
    $fh = @fopen($appLog, 'rb');
    if ($fh === false) {
        return;
    }
    if ($tailBytes > 0) {
        @fseek($fh, -$tailBytes, SEEK_END);
    }
    $tail = (string) stream_get_contents($fh);
    fclose($fh);

    $since = time() - $window * 60;
    $count = 0;
    foreach (explode("\n", $tail) as $line) {
        if (!preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', $line, $m)) {
            continue;
        }
        if (strtotime($m[1]) < $since) {
            continue;
        }
        if (preg_match('/\[(error|critical|alert|emergency)\]/i', $line) || str_contains($line, 'Exception')) {
            $count++;
        }
    }
    if ($count >= $threshold) {
        $r->warn("$count error entries in " . basename($appLog) . " within the last $window minute(s) (threshold $threshold).", "Check $appLog.");
    } else {
        $r->ok("$count error entries in " . basename($appLog) . " within the last $window minute(s).");
    }
};

// =============================================================================
// Run
// =============================================================================

if ($opt['list']) {
    fwrite(STDOUT, "Available checks:\n  " . implode("\n  ", array_keys($checks)) . "\n");
    exit(0);
}

// Two checks were renamed when generic (non-HumHub) support was added; accept
// the old ids so existing .env files and cron lines keep working.
$idAliases = [
    'humhub_install' => 'app_install',
    'humhub_permissions' => 'app_permissions',
];
$resolveIds = static function (array $ids) use ($idAliases): array {
    $out = [];
    foreach ($ids as $id) {
        $id = strtolower(trim((string) $id));
        $out[] = $idAliases[$id] ?? $id;
    }
    return $out;
};

$skip = $resolveIds(array_merge($env->list('HEALTH_SKIP_CHECKS'), $opt['skip']));
$only = $resolveIds($opt['only'] !== [] ? $opt['only'] : $env->list('HEALTH_ONLY_CHECKS'));

// One switch to drop everything HumHub-specific at once.
if (!$isHumhubMode && $env->bool('SKIP_APP_CHECKS', false)) {
    $skip = array_merge($skip, ['app_install', 'app_permissions', 'security', 'web_exposure', 'cron', 'logs']);
}

foreach ($checks as $id => $check) {
    $report->group($id);
    if ($only !== [] && !in_array($id, $only, true)) {
        continue;
    }
    if (in_array($id, $skip, true)) {
        $report->skip('Skipped by configuration.');
        continue;
    }
    try {
        $check($report);
    } catch (Throwable $e) {
        $report->error("Check '$id' crashed: " . $e->getMessage(), 'This is a bug in the health check, not necessarily a server problem.');
    }
}

$duration = microtime(true) - $started;

// =============================================================================
// Output
// =============================================================================

$errors = $report->count(Report::ERROR);
$warnings = $report->count(Report::WARNING);
$okCount = $report->count(Report::OK);
$skipped = $report->count(Report::SKIPPED);

$headline = $errors > 0
    ? sprintf('Server health check FAILED: %d error(s), %d warning(s)', $errors, $warnings)
    : ($warnings > 0
        ? sprintf('Server health check passed with %d warning(s)', $warnings)
        : 'Server health check passed');

$meta = [
    'label' => $env->str('HEALTH_LABEL', gethostname() ?: 'unknown'),
    'hostname' => gethostname() ?: 'unknown',
    'app_type' => $appType,
    'app_name' => $appLabel,
    'app_version' => $humhubVersion,
    'humhub_version' => $humhubVersion, // kept for backwards compatibility
    'app_path' => $humhubPath !== '' ? $humhubPath : null,
    'humhub_path' => $humhubPath !== '' ? $humhubPath : null,
    'php_version' => PHP_VERSION,
    'php_sapi' => PHP_SAPI,
    'php_user' => hc_process_user(),
    'checked_at' => date('c'),
    'duration_ms' => (int) round($duration * 1000),
    'script_version' => HC_VERSION,
];

if (!$isCli) {
    $failOnWarning = $env->bool('HTTP_FAIL_ON_WARNING', false);
    http_response_code($errors > 0 || ($failOnWarning && $warnings > 0) ? 503 : 200);
}

if ($opt['json']) {
    $payload = [
        'status' => $errors > 0 ? 'error' : ($warnings > 0 ? 'warning' : 'ok'),
        'headline' => $headline,
        'summary' => ['ok' => $okCount, 'warnings' => $warnings, 'errors' => $errors, 'skipped' => $skipped],
        'meta' => $meta,
        'checks' => array_values(array_filter(
            $report->all(),
            static fn($i) => $opt['verbose'] || in_array($i['state'], [Report::ERROR, Report::WARNING], true)
        )),
    ];
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit($errors > 0 ? 2 : ($warnings > 0 ? 1 : 0));
}

if ($opt['quiet']) {
    exit($errors > 0 ? 2 : ($warnings > 0 ? 1 : 0));
}

$colors = ['OK' => "\033[32m", 'WARNING' => "\033[33m", 'ERROR' => "\033[31m", 'SKIPPED' => "\033[90m", 'reset' => "\033[0m"];
$paint = static function (string $text, string $state) use ($opt, $colors): string {
    return $opt['color'] ? $colors[$state] . $text . $colors['reset'] : $text;
};

echo $paint($headline, $errors > 0 ? Report::ERROR : ($warnings > 0 ? Report::WARNING : Report::OK)) . "\n";
$appDescriptor = $isHumhubMode
    ? 'HumHub ' . ($humhubVersion ?? '?')
    : $appLabel;
echo sprintf(
    "%s | %s | PHP %s (%s, user %s) | %d ok, %d warning(s), %d error(s), %d skipped | %.2fs\n",
    $meta['label'],
    $appDescriptor,
    PHP_VERSION,
    PHP_SAPI,
    $meta['php_user'],
    $okCount,
    $warnings,
    $errors,
    $skipped,
    $duration
);

$states = [Report::ERROR, Report::WARNING];
if ($opt['verbose']) {
    $states = [Report::ERROR, Report::WARNING, Report::OK, Report::SKIPPED];
}
$shown = $report->withState(...$states);
if ($shown !== []) {
    echo "\n";
}
foreach ($shown as $item) {
    printf("%s [%s] %s\n", $paint(str_pad($item['state'], 7), $item['state']), $item['check'], $item['message']);
    if ($item['hint'] !== null && $item['state'] !== Report::OK) {
        printf("        -> %s\n", $item['hint']);
    }
}

exit($errors > 0 ? 2 : ($warnings > 0 ? 1 : 0));
