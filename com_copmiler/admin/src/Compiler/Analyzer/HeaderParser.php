<?php

declare(strict_types=1);

namespace Sadjadii\Component\Copmiler\Administrator\Compiler\Analyzer;

use RuntimeException;
use Sadjadii\Component\Copmiler\Administrator\Compiler\Model\PluginMetadata;

/**
 * Discovers the primary WordPress plugin file and parses its standard header metadata.
 */
final class HeaderParser
{
    private const HEADER_FIELDS = [
        'name'        => 'Plugin Name',
        'pluginUri'   => 'Plugin URI',
        'version'     => 'Version',
        'description' => 'Description',
        'author'      => 'Author',
        'authorUri'   => 'Author URI',
        'textDomain'  => 'Text Domain',
        'domainPath'  => 'Domain Path',
        'license'     => 'License',
        'requiresPhp' => 'Requires PHP',
        'requiresWp'  => 'Requires at least',
    ];

    /**
     * Locate and parse the main WordPress plugin file inside $pluginRoot.
     */
    public function parseFromDirectory(string $pluginRoot): PluginMetadata
    {
        $rootFiles = glob($pluginRoot . DIRECTORY_SEPARATOR . '*.php') ?: [];
        sort($rootFiles);

        foreach ($rootFiles as $file) {
            $parsed = $this->tryParseFile($file, $pluginRoot);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        // Fallback: search one level deep if plugin wasn't unwrapped cleanly
        $nestedFiles = glob($pluginRoot . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . '*.php') ?: [];
        sort($nestedFiles);

        foreach ($nestedFiles as $file) {
            $parsed = $this->tryParseFile($file, $pluginRoot);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        throw new RuntimeException('No valid WordPress plugin header ("Plugin Name:") found in the archive.');
    }

    private function tryParseFile(string $filePath, string $pluginRoot): ?PluginMetadata
    {
        $handle = @fopen($filePath, 'rb');
        if ($handle === false) {
            return null;
        }

        // WordPress reads the first 8 KB of the plugin file for headers
        $head = (string) fread($handle, 8192);
        fclose($handle);

        $head = str_replace("\r", "\n", $head);
        $extracted = [];

        foreach (self::HEADER_FIELDS as $key => $headerLabel) {
            $pattern = '/^[ \t\/*#@]*' . preg_quote($headerLabel, '/') . ':(.*)$/mi';
            if (preg_match($pattern, $head, $match) === 1) {
                $val = trim(preg_replace('/\s*(?:\*\/|\?>).*/', '', $match[1]) ?? '');
                $extracted[$key] = $val;
            }
        }

        if (empty($extracted['name'])) {
            return null;
        }

        $relativeMain = ltrim(str_replace('\\', '/', substr($filePath, strlen($pluginRoot))), '/');
        $slugSource = !empty($extracted['textDomain'])
            ? $extracted['textDomain']
            : pathinfo($relativeMain, PATHINFO_FILENAME);

        $slug = $this->sanitizeSlug($slugSource !== '' ? $slugSource : $extracted['name']);

        return new PluginMetadata(
            name: $extracted['name'],
            slug: $slug,
            mainFile: $relativeMain,
            version: ($extracted['version'] ?? '') ?: '1.0.0',
            description: $extracted['description'] ?? '',
            author: ($extracted['author'] ?? '') ?: 'Unknown',
            authorUri: $extracted['authorUri'] ?? '',
            pluginUri: $extracted['pluginUri'] ?? '',
            license: ($extracted['license'] ?? '') ?: 'GPL-2.0-or-later',
            textDomain: ($extracted['textDomain'] ?? '') ?: $slug,
            domainPath: ($extracted['domainPath'] ?? '') ?: '/languages',
            requiresPhp: ($extracted['requiresPhp'] ?? '') ?: '8.3',
            requiresWp: $extracted['requiresWp'] ?? '',
        );
    }

    private function sanitizeSlug(string $raw): string
    {
        $slug = strtolower(trim($raw));
        $slug = preg_replace('/[^a-z0-9_]+/', '_', $slug) ?? '';
        $slug = trim($slug, '_');

        if ($slug === '' || ctype_digit($slug[0])) {
            $slug = 'wp_' . $slug;
        }

        return substr($slug, 0, 48);
    }
}
