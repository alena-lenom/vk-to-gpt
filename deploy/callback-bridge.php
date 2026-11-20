<?php
declare(strict_types=1);
// Guarded, reversible setup for the existing private VK to GPT event directory.
ini_set('display_errors', '0');
if (PHP_SAPI !== 'cli') exit(2);
$action = $argv[1] ?? '';
$stage = $argv[2] ?? '';
$webroot = $argv[3] ?? '';
$base = '/var/www/u3641285/data/vk-to-gpt';
$expectedWebroot = '/var/www/u3641285/data/www/vk.tattoomystery.ru';
try {
    $stageReal = realpath($stage);
    if ($stageReal === false || $stageReal !== $stage
        || !preg_match('~^/tmp/vk-to-gpt-callback\\.[A-Za-z0-9]{8}$~D', $stage)
        || $webroot !== $expectedWebroot || realpath($webroot) !== $webroot) {
        throw new RuntimeException('Invalid staging path');
    }
    $config = require $base.'/shared/config.php';
    if (!is_array($config) || ($config['VK_COMMUNITY_ID'] ?? null) !== '220996873') {
        throw new RuntimeException('Invalid community binding');
    }
    $dir = $config['VK_SAPI_EVENTS_DIR'] ?? null;
    if (!is_string($dir) || $dir === '' || str_contains($dir, "\\0")
        || !str_starts_with($dir, $base.'/') || is_link($dir)) {
        throw new RuntimeException('Invalid event directory');
    }
    $leaf = basename($dir);
    $parent = dirname($dir);
    if (!preg_match('/^[A-Za-z0-9_-]+$/D', $leaf)
        || realpath($parent) !== $parent || !is_dir($parent)
        || str_starts_with($parent.'/', $base.'/app/')
        || $parent === $base.'/app') {
        throw new RuntimeException('Unsafe event directory');
    }
    $marker = $stage.'/created-event-directory.flag';
    $expectedWrapper = file_get_contents($stage.'/mcp.php');
    $installedWrapper = is_file($webroot.'/mcp.php') && !is_link($webroot.'/mcp.php')
        ? file_get_contents($webroot.'/mcp.php') : false;
    if (!is_string($expectedWrapper) || !is_string($installedWrapper)) {
        throw new RuntimeException('Missing MCP wrapper');
    }
    if ($action === 'verify-wrapper') {
        $legacy = str_replace(",'VK_SAPI_EVENTS_DIR'", '', $expectedWrapper);
        if ($installedWrapper !== $expectedWrapper && $installedWrapper !== $legacy) {
            throw new RuntimeException('MCP wrapper differs beyond approved binding');
        }
        echo 'mcp_wrapper_guard=ok'."\n";
        exit(0);
    }
    if ($action === 'prepare') {
        if (file_exists($dir)) {
            if (!is_dir($dir)) throw new RuntimeException('Event path is not a directory');
        } else {
            umask(0077);
            if (!mkdir($dir, 0700, false)) throw new RuntimeException('Unable to create private events directory');
            if (file_put_contents($marker, 'created', LOCK_EX) === false) {
                @rmdir($dir);
                throw new RuntimeException('Unable to record rollback marker');
            }
        }
    } elseif ($action === 'rollback') {
        if (is_file($marker)) {
            if (is_dir($dir) && !is_link($dir)) {
                // Never remove files: an incoming real VK event must survive a rollback.
                @rmdir($dir);
            }
            @unlink($marker);
        }
        echo 'callback_directory_rollback=attempted'."\n";
        exit(0);
    } elseif ($action !== 'check') {
        throw new RuntimeException('Unknown operation');
    }
    $real = realpath($dir);
    if ($real !== $dir || !is_readable($dir) || !is_writable($dir)
        || ((fileperms($dir) & 0077) !== 0)) {
        throw new RuntimeException('Unsafe or inaccessible event directory');
    }
    if ($action === 'check' && $installedWrapper !== $expectedWrapper) {
        throw new RuntimeException('MCP wrapper was not installed');
    }
    echo 'callback_private_directory='.($action === 'prepare' ? 'prepared' : 'verified')."\n";
} catch (Throwable) {
    fwrite(STDERR, 'callback_bridge_'.$action.'=failed'."\n");
    exit(1);
}
