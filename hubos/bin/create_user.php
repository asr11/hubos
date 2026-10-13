#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Create a user (default: owner). The password is read from STDIN only —
 * never pass it as an argument (it would leak into shell history / ps).
 *
 *   php bin/create_user.php --email=asher@example.com --name="אשר" \
 *       [--tenant=hub] [--tenant-name="האב מערכות מתקדמות בע\"מ"] [--role=owner]
 *
 * Interactive: prompts twice with echo disabled.
 * Non-interactive: printf '%s\n' "$PW" | php bin/create_user.php ...
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$config = require dirname(__DIR__) . '/app/bootstrap.php';

use HubOS\Core\DB;
use HubOS\Core\Permissions;
use HubOS\Core\Provisioning;
use HubOS\Core\Tenant;

foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^-{1,2}(pass|password|pw|pwd)(=|$)/i', $arg) === 1) {
        fwrite(STDERR, "Refusing password-like argument. Provide the password on STDIN.\n");
        exit(2);
    }
}

$opts = getopt('', ['email:', 'name:', 'tenant:', 'tenant-name:', 'role:', 'help']);
if (isset($opts['help']) || !isset($opts['email'], $opts['name'])) {
    fwrite(STDERR, "Usage: php bin/create_user.php --email=EMAIL --name=NAME [--tenant=slug] [--tenant-name=NAME] [--role="
        . implode('|', Permissions::ROLES) . "]\nPassword is read from STDIN.\n");
    exit(isset($opts['help']) ? 0 : 2);
}

$email = (string) $opts['email'];
$name = (string) $opts['name'];
$slug = (string) ($opts['tenant'] ?? 'hub');
$tenantName = (string) ($opts['tenant-name'] ?? 'האב מערכות מתקדמות בע"מ');
$role = (string) ($opts['role'] ?? 'owner');

$readSecret = static function (string $prompt): string {
    $tty = function_exists('stream_isatty') && stream_isatty(STDIN);
    $canStty = $tty && function_exists('shell_exec');
    if ($tty) {
        fwrite(STDERR, $prompt);
    }
    if ($canStty) {
        shell_exec('stty -echo');
    }
    $line = fgets(STDIN);
    if ($canStty) {
        shell_exec('stty echo');
        fwrite(STDERR, "\n");
    }
    return $line === false ? '' : rtrim($line, "\r\n");
};

$password = $readSecret('Password: ');
if (function_exists('stream_isatty') && stream_isatty(STDIN)) {
    if (!hash_equals($password, $readSecret('Repeat password: '))) {
        fwrite(STDERR, "Passwords do not match.\n");
        exit(2);
    }
}

try {
    $pdo = DB::connect($config['db_path']);
    $pdo->beginTransaction();
    $tenantId = Provisioning::ensureTenant($pdo, $slug, $tenantName);
    $userId = Provisioning::createUser($pdo, $tenantId, $email, $name, $role, $password);
    $pdo->commit();
    $count = (new Tenant($pdo, $tenantId))->count('users');
    fwrite(STDOUT, "Created user #{$userId} ({$role}) in tenant '{$slug}' #{$tenantId}. Users in tenant: {$count}\n");
    exit(0);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    $password = str_repeat("\0", strlen($password));
}
