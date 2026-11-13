<?php
/**
 * config/env.php - Minimal .env loader.
 *
 * No composer dependency added on purpose (this project has no autoloaded
 * dependency layer beyond a lazily-loaded PHPMailer) - just parses KEY=VALUE
 * lines from the project-root .env file into getenv()/$_ENV, so secrets like
 * XENDIT_SECRET_KEY never have to be hardcoded in source.
 *
 * Safe to require multiple times; safe if .env doesn't exist yet (getenv()
 * simply returns false for anything not set - callers must handle that).
 */
if (!function_exists('loadEnvFile')) {
    function loadEnvFile(string $path): void {
        static $loaded = [];
        $path = realpath($path) ?: $path;
        if (isset($loaded[$path])) { return; }
        $loaded[$path] = true;

        if (!is_file($path) || !is_readable($path)) { return; }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) { return; }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') { continue; }
            if (strpos($line, '=') === false) { continue; }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            // Strip matching surrounding quotes, if present.
            if (strlen($value) >= 2) {
                $first = $value[0];
                $last = $value[strlen($value) - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            if ($key === '') { continue; }
            // Don't override real process/server environment variables that
            // may already be set (e.g. by the hosting environment).
            if (getenv($key) === false) {
                putenv($key . '=' . $value);
                $_ENV[$key] = $value;
            }
        }
    }
}

loadEnvFile(__DIR__ . '/../.env');

/*
 * Pin PHP's clock to the store's own timezone.
 *
 * XAMPP's php.ini ships with date.timezone=Europe/Berlin, while MySQL runs on
 * the machine's local time - Metro Manila - and it is MySQL's NOW()/
 * CURRENT_TIMESTAMP that writes every created_at. Six hours apart, so PHP's
 * date('Y-m-d') could still say *yesterday* while orders were already being
 * stamped with today's date: the reports' default end date then cut the newest
 * orders out of every total, and a "Today" range came back empty. Any PHP-side
 * date compared against a stored timestamp had the same skew.
 *
 * Set APP_TIMEZONE in .env if the store ever moves; anything unrecognised
 * falls back to Asia/Manila rather than silently inheriting php.ini again.
 */
$appTimezone = getenv('APP_TIMEZONE');
if (!is_string($appTimezone) || $appTimezone === '' || !in_array($appTimezone, timezone_identifiers_list(), true)) {
    $appTimezone = 'Asia/Manila';
}
date_default_timezone_set($appTimezone);
unset($appTimezone);
