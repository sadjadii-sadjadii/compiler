<?php

declare(strict_types=1);

namespace Sadjadii\Component\Copmiler\Administrator\Compiler\Packager;

use RuntimeException;
use ZipArchive;

/**
 * Packages a compiled Joomla 6 extension directory into an installable ZIP archive.
 */
final class ZipPackager
{
    /**
     * @return array{path: string, filename: string, size: int}
     */
    public function packageDirectory(string $sourceDir, string $outputZipPath): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('PHP ZipArchive extension is required to build installable Joomla 6 packages.');
        }

        $outDir = dirname($outputZipPath);
        if (!is_dir($outDir) && !mkdir($outDir, 0755, true) && !is_dir($outDir)) {
            throw new RuntimeException('Unable to create output directory: ' . $outDir);
        }

        if (is_file($outputZipPath)) {
            @unlink($outputZipPath);
        }

        $zip = new ZipArchive();
        if ($zip->open($outputZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create ZIP archive at: ' . $outputZipPath);
        }

        $realSource = realpath($sourceDir);
        if ($realSource === false) {
            $zip->close();
            throw new RuntimeException('Compiled output directory not found: ' . $sourceDir);
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($realSource, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $filePath = $file->getRealPath();
            if ($filePath === false) {
                continue;
            }
            $relativePath = ltrim(str_replace('\\', '/', substr($filePath, strlen($realSource))), '/');
            $zip->addFile($filePath, $relativePath);
        }

        $zip->close();

        clearstatcache(true, $outputZipPath);
        $size = is_file($outputZipPath) ? (int) filesize($outputZipPath) : 0;

        return [
            'path'     => $outputZipPath,
            'filename' => basename($outputZipPath),
            'size'     => $size,
        ];
    }
}
