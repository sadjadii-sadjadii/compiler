#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Standalone CLI wrapper for com_copmiler (WordPress -> Joomla 6 Compiler).
 *
 * Usage:
 *   php cli/compile.php <path-to-wp-plugin.zip|dir> [--out=./dist] [--mode=hybrid|native] [--target=auto|component|plugin|module] [--group=auto|system|content|user|authentication|webservices|task] [--vendor=WpCompiled]
 */

spl_autoload_register(static function (string $class): void {
    $prefix = 'Sadjadii\\Component\\Copmiler\\Administrator\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $rel  = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix)));
    $file = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . $rel . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

use Sadjadii\Component\Copmiler\Administrator\Compiler\CompilerEngine;
use Sadjadii\Component\Copmiler\Administrator\Compiler\Model\CompileOptions;

$args = array_slice($argv, 1);
if ($args === [] || in_array('--help', $args, true) || in_array('-h', $args, true)) {
    echo "WordPress to Joomla 6 Compiler CLI (com_copmiler)\n";
    echo "Usage:\n";
    echo "  php cli/compile.php <wp-plugin.zip|folder> [--out=./dist] [--mode=hybrid|native] [--target=auto|component|plugin|module] [--group=auto] [--vendor=WpCompiled]\n";
    exit(0);
}

$source = $args[0];
$outDir = getcwd() . DIRECTORY_SEPARATOR . 'dist';
$mode   = CompileOptions::MODE_HYBRID;
$target = CompileOptions::TARGET_AUTO;
$group  = 'auto';
$vendor = 'WpCompiled';

foreach (array_slice($args, 1) as $arg) {
    if (str_starts_with($arg, '--out=')) {
        $outDir = substr($arg, 6);
    } elseif (str_starts_with($arg, '--mode=')) {
        $mode = substr($arg, 7);
    } elseif (str_starts_with($arg, '--target=')) {
        $target = substr($arg, 9);
    } elseif (str_starts_with($arg, '--group=')) {
        $group = substr($arg, 8);
    } elseif (str_starts_with($arg, '--vendor=')) {
        $vendor = substr($arg, 9);
    }
}

try {
    $engine  = new CompilerEngine();
    $options = new CompileOptions($mode, $target, $group, $vendor);
    $result  = $engine->compile($source, $outDir, $options);

    echo "=== Compilation Complete ===\n";
    echo "Source WP Plugin : {$result->analysis->metadata->name} (v{$result->analysis->metadata->version})\n";
    echo "Detected Type    : " . strtoupper($result->classification->type)
        . ($result->classification->type === 'plugin' ? " (group: {$result->classification->pluginGroup})" : '')
        . " [confidence: {$result->classification->confidence}%]\n";
    echo "Joomla 6 Element : {$result->elementName}\n";
    echo "Mode             : {$result->options->mode}\n";
    echo "Compatibility    : {$result->analysis->compatibilityScore}/100\n";
    echo "Output ZIP       : {$result->zipPath} (" . number_format($result->zipSize / 1024, 1) . " KB)\n";
    echo "Files Generated  : " . count($result->generatedFiles) . "\n";
    echo "Build Time       : " . round($result->durationSeconds, 3) . "s\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}
