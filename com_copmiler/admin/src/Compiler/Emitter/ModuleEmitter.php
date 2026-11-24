<?php

declare(strict_types=1);

namespace Sadjadii\Component\Copmiler\Administrator\Compiler\Emitter;

use Sadjadii\Component\Copmiler\Administrator\Compiler\Model\AnalysisResult;
use Sadjadii\Component\Copmiler\Administrator\Compiler\Model\ClassificationResult;
use Sadjadii\Component\Copmiler\Administrator\Compiler\Model\CompileOptions;
use Sadjadii\Component\Copmiler\Administrator\Compiler\Runtime\WpBridgeTemplate;
use Sadjadii\Component\Copmiler\Administrator\Compiler\Transformer\PhpCodeTransformer;

/**
 * Emits a strictly compliant Joomla 6 Site Module (mod_<slug>) using
 * AbstractModuleDispatcher, HelperFactory, DI provider, and XML params
 * automatically generated from the WordPress WP_Widget form fields.
 */
final class ModuleEmitter extends AbstractEmitter
{
    public function __construct(
        PhpCodeTransformer $phpTransformer,
        private readonly WpBridgeTemplate $bridgeTemplate,
    ) {
        parent::__construct($phpTransformer);
    }

    public function emit(
        string $sourceDir,
        string $outputDir,
        AnalysisResult $analysis,
        ClassificationResult $classification,
        CompileOptions $options,
    ): array {
        $generated = [];
        $logs      = [];

        $meta        = $analysis->metadata;
        $slug        = $this->cleanSlug($meta->slug);
        $elementName = 'mod_' . $slug;
        $studly      = $this->studlyCase($slug);
        $vendor      = $this->studlyCase($options->vendorNamespace);
        $baseNs      = $vendor . '\\Module\\' . $studly;
        $siteNs      = $baseNs . '\\Site';
        $langPrefix  = strtoupper($elementName);
        $date        = date('Y-m-d');

        // 1. Copy & transform original WP widget plugin source
        $this->copySourceFiles($sourceDir, $outputDir, 'wp_source', $analysis, $options, $elementName, $generated, $logs);

        // 2. Bundle Joomla 6 Runtime Bridge or Native Helper
        if ($options->isHybrid()) {
            $this->writeFile($outputDir, 'src/Runtime/WpBridge.php', $this->bridgeTemplate->renderBridgeBootstrap($siteNs, $slug, $elementName, 'module'), $generated);
            $this->writeFile($outputDir, 'src/Runtime/WpdbAdapter.php', $this->bridgeTemplate->renderWpdbAdapter(), $generated);
            $this->writeFile($outputDir, 'src/Runtime/WpCompatRegistry.php', $this->bridgeTemplate->renderCompatRegistry(), $generated);
            $this->writeFile($outputDir, 'src/Runtime/wp_functions.php', $this->bridgeTemplate->renderWpFunctions(), $generated);
            $logs[] = 'Bundled Joomla 6 WordPress Runtime Bridge for WP_Widget execution.';
        } else {
            $this->writeFile($outputDir, 'src/Runtime/NativeHelper.php', $this->bridgeTemplate->renderNativeHelper(), $generated);
            $logs[] = 'Generated pure Native Joomla 6 module structure.';
        }

        // 3. Collect extracted widget fields to generate Joomla Module XML params
        $widgetClass = $analysis->widgets[0]['class'] ?? '';
        $fieldNames  = [];
        foreach ($analysis->widgets as $w) {
            foreach ($w['fields'] ?? [] as $f) {
                $fieldNames[$f] = true;
            }
        }
        $fieldsXml = '';
        foreach (array_keys($fieldNames) as $fieldName) {
            $cleanField = preg_replace('/[^a-zA-Z0-9_]/', '_', $fieldName) ?: 'field';
            $label      = ucwords(str_replace(['_', '-'], ' ', $cleanField));
            $fieldsXml .= "                <field name=\"{$this->xmlEscape($cleanField)}\" type=\"text\" label=\"{$this->xmlEscape($label)}\" default=\"\" />\n";
        }
        if ($fieldsXml === '') {
            $fieldsXml = "                <field name=\"title\" type=\"text\" label=\"JGLOBAL_TITLE\" default=\"\" />\n";
        }

        // 4. Joomla 6 Module DI Service Provider
        $providerCode = <<<PHP
<?php

declare(strict_types=1);

defined('_JEXEC') or die;

use Joomla\\CMS\\Extension\\Service\\Provider\\HelperFactory;
use Joomla\\CMS\\Extension\\Service\\Provider\\Module;
use Joomla\\CMS\\Extension\\Service\\Provider\\ModuleDispatcherFactory;
use Joomla\\DI\\Container;
use Joomla\\DI\\ServiceProviderInterface;

return new class () implements ServiceProviderInterface {
    public function register(Container \$container): void
    {
        \$container->registerServiceProvider(new ModuleDispatcherFactory('\\\\{$vendor}\\\\Module\\\\{$studly}'));
        \$container->registerServiceProvider(new HelperFactory('\\\\{$vendor}\\\\Module\\\\{$studly}\\\\Site\\\\Helper'));
        \$container->registerServiceProvider(new Module());
    }
};
PHP;
        $this->writeFile($outputDir, 'services/provider.php', $providerCode, $generated);

        // 5. Joomla 6 Module Dispatcher
        $dispatcherCode = <<<PHP
<?php

declare(strict_types=1);

namespace {$siteNs}\\Dispatcher;

defined('_JEXEC') or die;

use Joomla\\CMS\\Dispatcher\\AbstractModuleDispatcher;
use Joomla\\CMS\\Helper\\HelperFactoryAwareInterface;
use Joomla\\CMS\\Helper\\HelperFactoryAwareTrait;

final class Dispatcher extends AbstractModuleDispatcher implements HelperFactoryAwareInterface
{
    use HelperFactoryAwareTrait;

    protected function getLayoutData(): array
    {
        \$data = parent::getLayoutData();
        \$helper = \$this->getHelperFactory()->getHelper('{$studly}Helper');
        \$data['widgetHtml'] = \$helper->renderWidget(\$data['params'], \$data['module'], \$this->getApplication());

        return \$data;
    }
}
PHP;
        $this->writeFile($outputDir, 'src/Dispatcher/Dispatcher.php', $dispatcherCode, $generated);

        // 6. Module Helper
        $mainFileEsc   = addslashes($meta->mainFile);
        $widgetClassEsc = addslashes($widgetClass);

        if ($options->isHybrid()) {
            $helperCode = <<<PHP
<?php

declare(strict_types=1);

namespace {$siteNs}\\Helper;

defined('_JEXEC') or die;

use Joomla\\CMS\\Application\\CMSApplicationInterface;
use Joomla\\CMS\\Uri\\Uri;
use Joomla\\Registry\\Registry;
use {$siteNs}\\Runtime\\WpBridge;

final class {$studly}Helper
{
    public function renderWidget(Registry \$params, object \$module, CMSApplicationInterface \$app): string
    {
        \$pluginDir = dirname(__DIR__, 2) . '/wp_source';
        \$pluginUrl = Uri::root() . 'modules/{$elementName}/wp_source/';
        WpBridge::boot(\$pluginDir, \$pluginUrl, '{$mainFileEsc}');

        \\JoomlaWpCompatRegistry::doAction('wp_enqueue_scripts');
        \\JoomlaWpCompatRegistry::flushAssetsToJoomla();

        \$widget = null;
        if ('{$widgetClassEsc}' !== '' && isset(\\JoomlaWpCompatRegistry::\$widgets['{$widgetClassEsc}'])) {
            \$widget = \\JoomlaWpCompatRegistry::\$widgets['{$widgetClassEsc}'];
        } elseif (\\JoomlaWpCompatRegistry::\$widgets !== []) {
            \$widget = reset(\\JoomlaWpCompatRegistry::\$widgets);
        } elseif ('{$widgetClassEsc}' !== '' && class_exists('{$widgetClassEsc}')) {
            \$widget = new \\{$widgetClassEsc}();
        }

        if (!is_object(\$widget) || !method_exists(\$widget, 'widget')) {
            return '';
        }

        \$instance = \$params->toArray();
        if (empty(\$instance['title']) && !empty(\$module->title)) {
            \$instance['title'] = (string) \$module->title;
        }

        \$args = [
            'before_widget' => '<div class="mod-{$slug} wp-widget-compiled">',
            'after_widget'  => '</div>',
            'before_title'  => '<h3 class="mod-{$slug}-title">',
            'after_title'   => '</h3>',
            'widget_id'     => '{$elementName}-' . (int) (\$module->id ?? 0),
            'widget_name'   => (string) (\$module->title ?? '{$this->xmlEscape($meta->name)}'),
        ];

        ob_start();
        \$widget->widget(\$args, \$instance);
        return (string) ob_get_clean();
    }
}
PHP;
        } else {
            $helperCode = <<<PHP
<?php

declare(strict_types=1);

namespace {$siteNs}\\Helper;

defined('_JEXEC') or die;

use Joomla\\CMS\\Application\\CMSApplicationInterface;
use Joomla\\Registry\\Registry;

final class {$studly}Helper
{
    public function renderWidget(Registry \$params, object \$module, CMSApplicationInterface \$app): string
    {
        require_once dirname(__DIR__) . '/Runtime/NativeHelper.php';
        \$title = \\SadjadiiNativeHelper::escHtml(\$params->get('title', \$module->title ?? ''));
        return '<div class="mod-{$slug}"><h4>' . \$title . '</h4></div>';
    }
}
PHP;
        }
        $this->writeFile($outputDir, "src/Helper/{$studly}Helper.php", $helperCode, $generated);

        // 7. Module Template
        $tmplCode = <<<'PHP'
<?php

declare(strict_types=1);

defined('_JEXEC') or die;

/** @var string $widgetHtml */
echo $widgetHtml;
PHP;
        $this->writeFile($outputDir, 'tmpl/default.php', $tmplCode, $generated);

        // 8. Language files
        $safeName = $this->iniEscape($meta->name);
        $safeDesc = $this->iniEscape($meta->description ?: ('Compiled from WordPress widget plugin ' . $meta->name . ' for Joomla 6'));
        $iniBody  = "{$langPrefix}=\"{$safeName}\"\n{$langPrefix}_XML_DESCRIPTION=\"{$safeDesc}\"\n";

        $this->writeFile($outputDir, "language/en-GB/{$elementName}.ini", $iniBody, $generated);
        $this->writeFile($outputDir, "language/en-GB/{$elementName}.sys.ini", $iniBody, $generated);
        $this->writeFile($outputDir, "language/fa-IR/{$elementName}.ini", $iniBody, $generated);
        $this->writeFile($outputDir, "language/fa-IR/{$elementName}.sys.ini", $iniBody, $generated);

        // 9. Migration Report
        $this->writeFile($outputDir, 'MIGRATION_REPORT.md', $this->buildMigrationReport($analysis, $classification, $options, $elementName, $logs), $generated);

        // 10. Joomla 6 Module Manifest XML
        $manifestXml = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<extension type="module" client="site" method="upgrade">
    <name>{$langPrefix}</name>
    <author>{$this->xmlEscape($meta->author)}</author>
    <creationDate>{$date}</creationDate>
    <copyright>(C) {$date} {$this->xmlEscape($meta->author)}</copyright>
    <license>{$this->xmlEscape($meta->license)}</license>
    <version>{$this->xmlEscape($meta->version)}</version>
    <description>{$langPrefix}_XML_DESCRIPTION</description>
    <namespace path="src">{$baseNs}</namespace>

    <files>
        <folder module="{$elementName}">services</folder>
        <folder>src</folder>
        <folder>tmpl</folder>
        <folder>wp_source</folder>
    </files>

    <languages>
        <language tag="en-GB">language/en-GB/{$elementName}.ini</language>
        <language tag="en-GB">language/en-GB/{$elementName}.sys.ini</language>
        <language tag="fa-IR">language/fa-IR/{$elementName}.ini</language>
        <language tag="fa-IR">language/fa-IR/{$elementName}.sys.ini</language>
    </languages>

    <config>
        <fields name="params">
            <fieldset name="basic">
{$fieldsXml}            </fieldset>
        </fields>
    </config>
</extension>
XML;
        $this->writeFile($outputDir, "{$elementName}.xml", $manifestXml, $generated);

        return ['files' => $generated, 'logs' => $logs];
    }
}
