<?php

declare(strict_types=1);

/**
 * Packages com_copmiler itself into an installable Joomla 6 component ZIP.
 * Output: dist/com_copmiler-1.0.0-joomla6.zip
 */

$root   = __DIR__;
$dist   = $root . DIRECTORY_SEPARATOR . 'dist';
$zipOut = $dist . DIRECTORY_SEPARATOR . 'com_copmiler-1.0.0-joomla6.zip';

if (!is_dir($dist)) {
    mkdir($dist, 0755, true);
}

if (is_file($zipOut)) {
    unlink($zipOut);
}

$zip = new ZipArchive();
if ($zip->open($zipOut, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Failed to create {$zipOut}\n");
    exit(1);
}

$includeFiles = ['copmiler.xml', 'script.php'];
$includeDirs  = ['admin', 'media'];

foreach ($includeFiles as $file) {
    $full = $root . DIRECTORY_SEPARATOR . $file;
    if (is_file($full)) {
        $zip->addFile($full, $file);
    }
}

foreach ($includeDirs as $dir) {
    $base = $root . DIRECTORY_SEPARATOR . $dir;
    if (!is_dir($base)) {
        continue;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($it as $item) {
        if (!$item->isFile()) {
            continue;
        }
        $rel = ltrim(str_replace('\\', '/', substr($item->getRealPath(), strlen($root))), '/');
        if (str_contains($rel, '/storage/builds/')) {
            continue;
        }
        $zip->addFile($item->getRealPath(), $rel);
    }
}

$zip->close();
echo "Packaged Joomla 6 installer: {$zipOut} (" . number_format(filesize($zipOut) / 1024, 1) . " KB)\n";
