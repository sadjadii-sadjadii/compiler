<?php

declare(strict_types=1);

namespace Sadjadii\Component\Copmiler\Administrator\Compiler;

use RuntimeException;
use Sadjadii\Component\Copmiler\Administrator\Compiler\Analyzer\ExtensionTypeDetector;
use Sadjadii\Component\Copmiler\Administrator\Compiler\Analyzer\HeaderParser;
use Sadjadii\Component\Copmiler\Administrator\Compiler\Analyzer\PhpTokenAnalyzer;
use Sadjadii\Component\Copmiler\Administrator\Compiler\Analyzer\ZipExtractor;
use Sadjadii\Component\Copmiler\Administrator\Compiler\Emitter\ComponentEmitter;
use Sadjadii\Component\Copmiler\Administrator\Compiler\Emitter\ModuleEmitter;
use Sadjadii\Component\Copmiler\Administrator\Compiler\Emitter\PluginEmitter;
use Sadjadii\Component\Copmiler\Administrator\Compiler\Model\ClassificationResult;
use Sadjadii\Component\Copmiler\Administrator\Compiler\Model\CompileOptions;
use Sadjadii\Component\Copmiler\Administrator\Compiler\Model\CompileResult;
use Sadjadii\Component\Copmiler\Administrator\Compiler\Packager\ZipPackager;
use Sadjadii\Component\Copmiler\Administrator\Compiler\Runtime\WpBridgeTemplate;
use Sadjadii\Component\Copmiler\Administrator\Compiler\Transformer\PhpCodeTransformer;
use Sadjadii\Component\Copmiler\Administrator\Compiler\Transformer\SqlSchemaConverter;

/**
 * Main compilation pipeline orchestrator:
 *  1. Extract WP plugin ZIP (or read unpacked directory)
 *  2. Parse WP plugin header metadata
 *  3. Static token analysis (hooks, shortcodes, widgets, admin menus, CPTs, DB schemas, APIs)
 *  4. Intelligent classification into exact Joomla 6 type (component | plugin | module)
 *  5. Emit strictly Joomla 6-compliant extension (Hybrid Runtime Bridge or Pure Native)
 *  6. Package into installable Joomla 6 ZIP archive
 */
final class CompilerEngine
{
    private readonly ZipExtractor $extractor;
    private readonly HeaderParser $headerParser;
    private readonly PhpTokenAnalyzer $analyzer;
    private readonly ExtensionTypeDetector $detector;
    private readonly ComponentEmitter $componentEmitter;
    private readonly PluginEmitter $pluginEmitter;
    private readonly ModuleEmitter $moduleEmitter;
    private readonly ZipPackager $packager;

    public function __construct()
    {
        $this->extractor    = new ZipExtractor();
        $this->headerParser = new HeaderParser();
        $this->analyzer     = new PhpTokenAnalyzer();
        $this->detector     = new ExtensionTypeDetector();
        $this->packager     = new ZipPackager();

        $phpTransformer = new PhpCodeTransformer();
        $bridgeTemplate = new WpBridgeTemplate();
        $sqlConverter   = new SqlSchemaConverter();

        $this->componentEmitter = new ComponentEmitter($phpTransformer, $bridgeTemplate, $sqlConverter);
        $this->pluginEmitter    = new PluginEmitter($phpTransformer, $bridgeTemplate);
        $this->moduleEmitter    = new ModuleEmitter($phpTransformer, $bridgeTemplate);
    }

    /**
     * Compile a WordPress plugin ZIP archive or unpacked folder into a Joomla 6 installable ZIP.
     */
    public function compile(
        string $sourcePath,
        string $outputDirectory,
        ?CompileOptions $options = null,
        ?string $tempBaseDir = null,
    ): CompileResult {
        $startTime = microtime(true);
        $options ??= new CompileOptions();

        $workBase = ($tempBaseDir ?: sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'copmiler_' . bin2hex(random_bytes(6));
        $extractDir = $workBase . DIRECTORY_SEPARATOR . 'src';
        $emitDir    = $workBase . DIRECTORY_SEPARATOR . 'out';

        try {
            if (is_dir($sourcePath)) {
                $pluginRoot = realpath($sourcePath) ?: $sourcePath;
            } elseif (is_file($sourcePath)) {
                $pluginRoot = $this->extractor->extract($sourcePath, $extractDir);
            } else {
                throw new RuntimeException('WordPress plugin source not found: ' . $sourcePath);
            }

            // Step 1: Parse WordPress header
            $metadata = $this->headerParser->parseFromDirectory($pluginRoot);

            // Step 2: Static token analysis
            $analysis = $this->analyzer->analyze($pluginRoot, $metadata);

            // Step 3: Classify exact Joomla 6 extension type (component | plugin | module)
            $classification = $this->detector->detect($analysis, $options);
            $elementName    = $classification->getElementName($metadata->slug);

            // Step 4: Emit exact Joomla 6 extension
            $emitter = match ($classification->type) {
                ClassificationResult::TYPE_COMPONENT => $this->componentEmitter,
                ClassificationResult::TYPE_MODULE    => $this->moduleEmitter,
                default                              => $this->pluginEmitter,
            };

            $emitted = $emitter->emit($pluginRoot, $emitDir, $analysis, $classification, $options);

            // Step 5: Package into installable Joomla 6 ZIP
            $safeVersion = preg_replace('/[^a-zA-Z0-9._\-]/', '', $metadata->version) ?: '1.0.0';
            $zipName     = sprintf('%s-v%s-joomla6-%s.zip', $elementName, $safeVersion, $options->mode);
            $zipPath     = rtrim($outputDirectory, '/\\') . DIRECTORY_SEPARATOR . $zipName;

            $pkg = $this->packager->packageDirectory($emitDir, $zipPath);

            return new CompileResult(
                analysis: $analysis,
                classification: $classification,
                options: $options,
                elementName: $elementName,
                zipPath: $pkg['path'],
                zipFilename: $pkg['filename'],
                zipSize: $pkg['size'],
                generatedFiles: $emitted['files'],
                transformLogs: $emitted['logs'],
                durationSeconds: microtime(true) - $startTime,
            );
        } finally {
            ZipExtractor::deleteDirectory($workBase);
        }
    }
}
