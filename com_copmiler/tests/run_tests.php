<?php

declare(strict_types=1);

/**
 * Automated end-to-end verification suite for com_copmiler:
 *  1. Lints all PHP files in com_copmiler itself using current PHP 8.4 binary.
 *  2. Compiles 3 realistic WordPress plugin fixtures (Widget, Content Shortcode, Admin/DB Manager)
 *     in BOTH Hybrid and Native modes.
 *  3. Asserts exact automatic Joomla 6 extension classification (module / plugin[content] / component).
 *  4. Extracts every generated Joomla 6 ZIP, validates every XML manifest, and runs `php -l`
 *     on EVERY generated PHP file.
 *  5. Executes the compiled Hybrid WP runtime in-process to verify shortcodes, filters, and widgets run!
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

use Sadjadii\Component\Copmiler\Administrator\Compiler\Analyzer\ZipExtractor;
use Sadjadii\Component\Copmiler\Administrator\Compiler\CompilerEngine;
use Sadjadii\Component\Copmiler\Administrator\Compiler\Model\CompileOptions;

$phpBin  = PHP_BINARY;
$rootDir = dirname(__DIR__);
$outDir  = __DIR__ . DIRECTORY_SEPARATOR . '.tmp_test_' . bin2hex(random_bytes(4));
mkdir($outDir, 0755, true);

$passed = 0;
$failed = 0;

function assertTrue(bool $cond, string $msg, int &$passed, int &$failed): void
{
    if ($cond) {
        $passed++;
        echo "[PASS] {$msg}\n";
    } else {
        $failed++;
        echo "[FAIL] {$msg}\n";
    }
}

function lintPhpFile(string $phpBin, string $filePath): bool
{
    $cmd = escapeshellarg($phpBin) . ' -n -l ' . escapeshellarg($filePath) . ' 2>&1';
    exec($cmd, $output, $exitCode);
    if ($exitCode !== 0) {
        echo "  Lint error in {$filePath}: " . implode(' | ', $output) . "\n";
        return false;
    }
    return true;
}

try {
    // 1. Lint every PHP file in com_copmiler itself
    $selfIt = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($rootDir, FilesystemIterator::SKIP_DOTS)
    );
    $selfLintOk = true;
    $selfCount  = 0;
    foreach ($selfIt as $f) {
        if ($f->isFile() && strtolower($f->getExtension()) === 'php') {
            $selfCount++;
            if (!lintPhpFile($phpBin, $f->getRealPath())) {
                $selfLintOk = false;
            }
        }
    }
    assertTrue($selfLintOk && $selfCount > 15, "All {$selfCount} PHP files in com_copmiler pass PHP " . PHP_VERSION . " syntax lint", $passed, $failed);

    // 2. Validate com_copmiler manifests & XML files
    $selfXmlFiles = [
        $rootDir . '/copmiler.xml',
        $rootDir . '/admin/access.xml',
        $rootDir . '/admin/config.xml',
    ];
    foreach ($selfXmlFiles as $xmlFile) {
        $dom = new DOMDocument();
        $ok  = $dom->load($xmlFile);
        assertTrue((bool) $ok, 'Valid XML: ' . basename($xmlFile), $passed, $failed);
    }

    // 3. Compile all 3 fixtures in both Hybrid and Native modes
    $cases = [
        [
            'fixture'       => 'wp-weather-widget',
            'expectedType'  => 'module',
            'expectedGroup' => null,
            'expectedElem'  => 'mod_wp_weather_widget',
            'manifest'      => 'mod_wp_weather_widget.xml',
        ],
        [
            'fixture'       => 'wp-reading-time',
            'expectedType'  => 'plugin',
            'expectedGroup' => 'content',
            'expectedElem'  => 'plg_content_wp_reading_time',
            'manifest'      => 'wp_reading_time.xml',
        ],
        [
            'fixture'       => 'wp-lead-manager',
            'expectedType'  => 'component',
            'expectedGroup' => null,
            'expectedElem'  => 'com_wp_lead_manager',
            'manifest'      => 'wp_lead_manager.xml',
        ],
    ];

    $engine = new CompilerEngine();
    $extractor = new ZipExtractor();

    foreach ($cases as $case) {
        $fixtureDir = __DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . $case['fixture'];

        foreach ([CompileOptions::MODE_HYBRID, CompileOptions::MODE_NATIVE] as $mode) {
            $opts   = new CompileOptions(mode: $mode);
            $result = $engine->compile($fixtureDir, $outDir, $opts, $outDir);

            assertTrue(
                $result->classification->type === $case['expectedType'],
                "[{$case['fixture']} / {$mode}] Classified as {$result->classification->type} (expected {$case['expectedType']})",
                $passed,
                $failed
            );

            if ($case['expectedGroup'] !== null) {
                assertTrue(
                    $result->classification->pluginGroup === $case['expectedGroup'],
                    "[{$case['fixture']} / {$mode}] Plugin group is {$result->classification->pluginGroup} (expected {$case['expectedGroup']})",
                    $passed,
                    $failed
                );
            }

            assertTrue(
                $result->elementName === $case['expectedElem'],
                "[{$case['fixture']} / {$mode}] Element name is {$result->elementName}",
                $passed,
                $failed
            );

            assertTrue(
                is_file($result->zipPath) && $result->zipSize > 500,
                "[{$case['fixture']} / {$mode}] Generated ZIP exists ({$result->zipSize} bytes)",
                $passed,
                $failed
            );

            // Extract generated ZIP and validate EVERY PHP & XML file inside!
            $verifyDir = $outDir . DIRECTORY_SEPARATOR . 'verify_' . $case['fixture'] . '_' . $mode;
            $extracted = $extractor->extract($result->zipPath, $verifyDir);

            $manifestPath = $extracted . DIRECTORY_SEPARATOR . $case['manifest'];
            $dom = new DOMDocument();
            assertTrue(
                is_file($manifestPath) && (bool) $dom->load($manifestPath),
                "[{$case['fixture']} / {$mode}] Valid Joomla 6 manifest: {$case['manifest']}",
                $passed,
                $failed
            );

            $genIt = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($extracted, FilesystemIterator::SKIP_DOTS)
            );
            $genPhpOk    = true;
            $genPhpCount = 0;
            foreach ($genIt as $gf) {
                if ($gf->isFile() && strtolower($gf->getExtension()) === 'php') {
                    $genPhpCount++;
                    if (!lintPhpFile($phpBin, $gf->getRealPath())) {
                        $genPhpOk = false;
                    }
                }
            }
            assertTrue(
                $genPhpOk && $genPhpCount > 0,
                "[{$case['fixture']} / {$mode}] All {$genPhpCount} generated PHP files pass PHP " . PHP_VERSION . " syntax lint",
                $passed,
                $failed
            );

            // Runtime execution smoke test for Hybrid outputs!
            if ($mode === CompileOptions::MODE_HYBRID) {
                if (!defined('_JEXEC')) {
                    define('_JEXEC', 1);
                }

                if ($case['fixture'] === 'wp-reading-time') {
                    require_once $extracted . '/src/Runtime/WpBridge.php';
                    \WpCompiled\Plugin\Content\WpReadingTime\Runtime\WpBridge::boot(
                        $extracted . '/wp_source',
                        'https://example.com/plugins/content/wp_reading_time/wp_source/',
                        'wp-reading-time.php'
                    );
                    $scOut = \JoomlaWpCompatRegistry::doShortcode('Hello [reading_badge label="Joomla 6 Ready"]!');
                    $flOut = (string) \JoomlaWpCompatRegistry::applyFilters('the_content', $scOut);
                    assertTrue(
                        str_contains($flOut, 'Joomla 6 Ready') && str_contains($flOut, 'reading-time'),
                        '[Runtime Execution] Compiled WP shortcode + the_content filter executed cleanly in Joomla 6 bridge',
                        $passed,
                        $failed
                    );
                }

                if ($case['fixture'] === 'wp-weather-widget') {
                    require_once $extracted . '/src/Runtime/WpBridge.php';
                    \WpCompiled\Module\WpWeatherWidget\Site\Runtime\WpBridge::boot(
                        $extracted . '/wp_source',
                        'https://example.com/modules/mod_wp_weather_widget/wp_source/',
                        'wp-weather-widget.php'
                    );
                    $widget = \JoomlaWpCompatRegistry::$widgets['Wp_Weather_Card_Widget'] ?? null;
                    ob_start();
                    if ($widget !== null) {
                        $widget->widget(
                            ['before_widget' => '<div>', 'after_widget' => '</div>', 'before_title' => '<h3>', 'after_title' => '</h3>'],
                            ['title' => 'Shiraz Weather', 'city' => 'Shiraz', 'unit' => 'C']
                        );
                    }
                    $widgetHtml = (string) ob_get_clean();
                    assertTrue(
                        str_contains($widgetHtml, 'Shiraz Weather') && str_contains($widgetHtml, 'Shiraz: 22'),
                        '[Runtime Execution] Compiled WP_Widget executed cleanly and rendered dynamic Joomla params',
                        $passed,
                        $failed
                    );
                }

                if ($case['fixture'] === 'wp-lead-manager') {
                    require_once $extracted . '/admin/src/Runtime/WpBridge.php';
                    \WpCompiled\Component\WpLeadManager\Administrator\Runtime\WpBridge::boot(
                        $extracted . '/admin/wp_source',
                        'https://example.com/administrator/components/com_wp_lead_manager/wp_source/',
                        'wp-lead-manager.php'
                    );
                    \JoomlaWpCompatRegistry::doAction('admin_menu');
                    $hasPages = isset(\JoomlaWpCompatRegistry::$adminPages['wplm-dashboard'], \JoomlaWpCompatRegistry::$adminPages['wplm-settings']);
                    $sqlFile  = file_get_contents($extracted . '/admin/sql/install.mysql.utf8.sql') ?: '';
                    assertTrue(
                        $hasPages && str_contains($sqlFile, '#__lead_entries'),
                        '[Runtime Execution] Compiled WP admin pages registered + SQL table converted to #__lead_entries',
                        $passed,
                        $failed
                    );
                }
            }
        }
    }

    echo "\n========================================\n";
    echo "TOTAL: {$passed} passed, {$failed} failed\n";
    echo "========================================\n";
} finally {
    ZipExtractor::deleteDirectory($outDir);
}

exit($failed > 0 ? 1 : 0);
