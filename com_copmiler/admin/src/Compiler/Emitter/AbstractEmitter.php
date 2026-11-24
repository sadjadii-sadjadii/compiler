<?php

declare(strict_types=1);

namespace Sadjadii\Component\Copmiler\Administrator\Compiler\Emitter;

use Sadjadii\Component\Copmiler\Administrator\Compiler\Model\AnalysisResult;
use Sadjadii\Component\Copmiler\Administrator\Compiler\Model\ClassificationResult;
use Sadjadii\Component\Copmiler\Administrator\Compiler\Model\CompileOptions;
use Sadjadii\Component\Copmiler\Administrator\Compiler\Transformer\PhpCodeTransformer;

/**
 * Shared utilities for emitting Joomla 6 Component, Plugin, and Module extensions.
 */
abstract class AbstractEmitter
{
    public function __construct(
        protected readonly PhpCodeTransformer $phpTransformer,
    ) {
    }

    /**
     * Emit the compiled Joomla 6 extension into $outputDir.
     *
     * @return array{files: list<string>, logs: list<string>}
     */
    abstract public function emit(
        string $sourceDir,
        string $outputDir,
        AnalysisResult $analysis,
        ClassificationResult $classification,
        CompileOptions $options,
    ): array;

    protected function writeFile(string $baseDir, string $relPath, string $content, array &$generatedFiles): void
    {
        $relPath  = ltrim(str_replace('\\', '/', $relPath), '/');
        $fullPath = $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath);
        $dir      = dirname($fullPath);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($fullPath, $content);
        $generatedFiles[] = $relPath;
    }

    protected function copySourceFiles(
        string $sourceDir,
        string $outputDir,
        string $targetSubDir,
        AnalysisResult $analysis,
        CompileOptions $options,
        string $elementName,
        array &$generatedFiles,
        array &$logs,
    ): void {
        // Transform and copy all PHP source files
        foreach ($analysis->phpFiles as $relPhp) {
            $srcPath = $sourceDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPhp);
            $raw     = @file_get_contents($srcPath);
            if ($raw === false) {
                continue;
            }

            $transformed = $options->isHybrid()
                ? $this->phpTransformer->transformForHybrid($raw, $relPhp)
                : $this->phpTransformer->transformForNative($raw, $relPhp, $elementName);

            foreach ($transformed['notes'] as $note) {
                $logs[] = $note;
            }

            $this->writeFile($outputDir, $targetSubDir . '/' . $relPhp, $transformed['code'], $generatedFiles);
        }

        // Copy static assets (CSS, JS, images, fonts, JSON)
        foreach ($analysis->assetFiles as $relAsset) {
            $srcPath = $sourceDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relAsset);
            $raw     = @file_get_contents($srcPath);
            if ($raw === false) {
                continue;
            }
            $this->writeFile($outputDir, $targetSubDir . '/' . $relAsset, $raw, $generatedFiles);
        }
    }

    protected function studlyCase(string $slug): string
    {
        $parts = preg_split('/[^a-zA-Z0-9]+/', $slug) ?: [$slug];
        $studly = implode('', array_map(static fn (string $p): string => ucfirst(strtolower($p)), array_filter($parts)));
        if ($studly === '' || ctype_digit($studly[0])) {
            $studly = 'Wp' . $studly;
        }
        return $studly;
    }

    protected function cleanSlug(string $slug): string
    {
        $clean = preg_replace('/[^a-z0-9_]/', '', strtolower(str_replace('-', '_', $slug))) ?? '';
        if ($clean === '' || ctype_digit($clean[0])) {
            $clean = 'wp_' . $clean;
        }
        return $clean;
    }

    protected function xmlEscape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    protected function iniEscape(string $value): string
    {
        return str_replace(['"', "\r", "\n"], ['\"', '', ' '], $value);
    }

    protected function buildMigrationReport(
        AnalysisResult $analysis,
        ClassificationResult $classification,
        CompileOptions $options,
        string $elementName,
        array $logs,
    ): string {
        $m = $analysis->metadata;
        $lines = [
            '# Joomla 6 Migration Report — ' . $m->name,
            '',
            '- **Generated Joomla 6 Extension:** `' . $elementName . '`',
            '- **Classified Type:** `' . $classification->type . '`' . ($classification->type === 'plugin' ? ' (group: `' . $classification->pluginGroup . '`)' : ''),
            '- **Classification Confidence:** ' . $classification->confidence . '%',
            '- **Compilation Mode:** `' . $options->mode . '`',
            '- **Estimated Compatibility Score:** ' . $analysis->compatibilityScore . '/100',
            '- **Source Version:** ' . $m->version,
            '- **Target Platform:** Joomla 6.x (PHP 8.3+)',
            '',
            '## Classification Evidence',
        ];

        foreach ($classification->reasons as $reason) {
            $lines[] = '- ' . $reason;
        }

        $lines[] = '';
        $lines[] = '## Detected WordPress Constructs';
        $lines[] = '- PHP Files: ' . count($analysis->phpFiles);
        $lines[] = '- Static Assets: ' . count($analysis->assetFiles);
        $lines[] = '- Actions: ' . count($analysis->actions);
        $lines[] = '- Filters: ' . count($analysis->filters);
        $lines[] = '- Shortcodes: ' . count($analysis->shortcodes);
        $lines[] = '- Widgets: ' . count($analysis->widgets);
        $lines[] = '- Admin Menus: ' . count($analysis->adminMenus);
        $lines[] = '- Custom Post Types: ' . count($analysis->postTypes);
        $lines[] = '- REST Routes: ' . count($analysis->restRoutes);
        $lines[] = '- Custom DB Tables: ' . count($analysis->dbTables);

        if ($analysis->warnings !== []) {
            $lines[] = '';
            $lines[] = '## Warnings & Manual Review Notes';
            foreach ($analysis->warnings as $w) {
                $lines[] = sprintf('- **[%s]** `%s`: %s', strtoupper($w['severity']), $w['file'], $w['message']);
            }
        }

        if ($logs !== []) {
            $lines[] = '';
            $lines[] = '## Transformation Log';
            foreach ($logs as $log) {
                $lines[] = '- ' . $log;
            }
        }

        return implode("\n", $lines) . "\n";
    }
}
