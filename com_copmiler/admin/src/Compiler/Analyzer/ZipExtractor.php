<?php

declare(strict_types=1);

namespace Sadjadii\Component\Copmiler\Administrator\Compiler\Analyzer;

use RuntimeException;
use ZipArchive;

/**
 * Safely extracts a WordPress plugin ZIP archive with Zip-Slip protection
 * and automatic single-root-directory unwrapping.
 */
final class ZipExtractor
{
    private const MAX_FILES = 5000;
    private const MAX_TOTAL_BYTES = 104_857_600; // 100 MB uncompressed limit

    /**
     * Extract a ZIP archive to a clean directory and return the resolved plugin root directory.
     */
    public function extract(string $zipPath, string $targetDir): string
    {
        if (!is_file($zipPath)) {
            throw new RuntimeException('Uploaded ZIP archive does not exist: ' . $zipPath);
        }

        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('PHP ZipArchive extension is required by com_copmiler.');
        }

        $zip = new ZipArchive();
        $openResult = $zip->open($zipPath);

        if ($openResult !== true) {
            throw new RuntimeException('Unable to open ZIP archive (error code: ' . $openResult . ').');
        }

        try {
            if ($zip->numFiles === 0) {
                throw new RuntimeException('The ZIP archive is empty.');
            }

            if ($zip->numFiles > self::MAX_FILES) {
                throw new RuntimeException('The ZIP archive exceeds the maximum allowed file count (' . self::MAX_FILES . ').');
            }

            if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
                throw new RuntimeException('Unable to create extraction directory: ' . $targetDir);
            }

            $realTarget = realpath($targetDir);
            if ($realTarget === false) {
                throw new RuntimeException('Unable to resolve extraction directory: ' . $targetDir);
            }

            $totalBytes = 0;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entryName = $zip->getNameIndex($i);
                if ($entryName === false) {
                    continue;
                }

                // Skip macOS metadata folders
                if (str_starts_with($entryName, '__MACOSX/') || str_contains($entryName, '/__MACOSX/')) {
                    continue;
                }

                $normalized = str_replace('\\', '/', $entryName);
                $normalized = ltrim($normalized, '/');

                if ($normalized === '' || str_contains($normalized, '../') || $normalized === '..' || str_contains($normalized, "\0")) {
                    throw new RuntimeException('Unsafe path detected inside ZIP archive: ' . $entryName);
                }

                $stat = $zip->statIndex($i);
                if (is_array($stat)) {
                    $totalBytes += (int) ($stat['size'] ?? 0);
                    if ($totalBytes > self::MAX_TOTAL_BYTES) {
                        throw new RuntimeException('ZIP archive exceeds maximum uncompressed size (100 MB).');
                    }
                }

                $destPath = $realTarget . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized);

                if (str_ends_with($normalized, '/')) {
                    if (!is_dir($destPath) && !mkdir($destPath, 0755, true) && !is_dir($destPath)) {
                        throw new RuntimeException('Unable to create directory: ' . $destPath);
                    }
                    continue;
                }

                $parentDir = dirname($destPath);
                if (!is_dir($parentDir) && !mkdir($parentDir, 0755, true) && !is_dir($parentDir)) {
                    throw new RuntimeException('Unable to create parent directory: ' . $parentDir);
                }

                $realParent = realpath($parentDir);
                if ($realParent === false || !str_starts_with($realParent, $realTarget)) {
                    throw new RuntimeException('Path traversal blocked for ZIP entry: ' . $entryName);
                }

                $stream = $zip->getStream($entryName);
                if ($stream === false) {
                    throw new RuntimeException('Failed to read ZIP entry: ' . $entryName);
                }

                $out = fopen($destPath, 'wb');
                if ($out === false) {
                    fclose($stream);
                    throw new RuntimeException('Failed to write extracted file: ' . $destPath);
                }

                stream_copy_to_stream($stream, $out);
                fclose($stream);
                fclose($out);
            }
        } finally {
            $zip->close();
        }

        return $this->unwrapSingleRootFolder($realTarget);
    }

    /**
     * If the ZIP contained a single top-level plugin folder (standard WP format),
     * return that folder as the plugin root.
     */
    private function unwrapSingleRootFolder(string $dir): string
    {
        $entries = array_values(array_filter(
            scandir($dir) ?: [],
            static fn (string $item): bool => $item !== '.' && $item !== '..' && $item !== '__MACOSX' && $item !== '.DS_Store'
        ));

        if (count($entries) === 1) {
            $candidate = $dir . DIRECTORY_SEPARATOR . $entries[0];
            if (is_dir($candidate)) {
                return $candidate;
            }
        }

        return $dir;
    }

    /**
     * Recursively remove a temporary workspace directory.
     */
    public static function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($dir);
    }
}
