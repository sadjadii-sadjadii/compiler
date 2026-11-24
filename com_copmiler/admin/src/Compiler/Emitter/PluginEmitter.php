<?php

declare(strict_types=1);

namespace Sadjadii\Component\Copmiler\Administrator\Compiler\Emitter;

use Sadjadii\Component\Copmiler\Administrator\Compiler\Model\AnalysisResult;
use Sadjadii\Component\Copmiler\Administrator\Compiler\Model\ClassificationResult;
use Sadjadii\Component\Copmiler\Administrator\Compiler\Model\CompileOptions;
use Sadjadii\Component\Copmiler\Administrator\Compiler\Runtime\WpBridgeTemplate;
use Sadjadii\Component\Copmiler\Administrator\Compiler\Transformer\PhpCodeTransformer;

/**
 * Emits a strictly compliant Joomla 6 Plugin (plg_<group>_<slug>) using
 * SubscriberInterface, DatabaseAwareTrait, and the Joomla 6 DI constructor
 * (without the deprecated DispatcherInterface constructor parameter).
 */
final class PluginEmitter extends AbstractEmitter
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
        $group       = $classification->pluginGroup;
        $elementName = 'plg_' . $group . '_' . $slug;
        $studlySlug  = $this->studlyCase($slug);
        $studlyGroup = $this->studlyCase($group);
        $vendor      = $this->studlyCase($options->vendorNamespace);
        $namespace   = $vendor . '\\Plugin\\' . $studlyGroup . '\\' . $studlySlug;
        $langPrefix  = strtoupper($elementName);
        $date        = date('Y-m-d');

        // 1. Copy & transform original WP plugin files
        $this->copySourceFiles($sourceDir, $outputDir, 'wp_source', $analysis, $options, $elementName, $generated, $logs);

        // 2. Bundle Joomla 6 Runtime Bridge or Native Helper
        if ($options->isHybrid()) {
            $this->writeFile($outputDir, 'src/Runtime/WpBridge.php', $this->bridgeTemplate->renderBridgeBootstrap($namespace, $slug, $elementName, 'plugin'), $generated);
            $this->writeFile($outputDir, 'src/Runtime/WpdbAdapter.php', $this->bridgeTemplate->renderWpdbAdapter(), $generated);
            $this->writeFile($outputDir, 'src/Runtime/WpCompatRegistry.php', $this->bridgeTemplate->renderCompatRegistry(), $generated);
            $this->writeFile($outputDir, 'src/Runtime/wp_functions.php', $this->bridgeTemplate->renderWpFunctions(), $generated);
            $logs[] = 'Bundled Joomla 6 WordPress Runtime Bridge into src/Runtime/.';
        } else {
            $this->writeFile($outputDir, 'src/Runtime/NativeHelper.php', $this->bridgeTemplate->renderNativeHelper(), $generated);
            $logs[] = 'Generated pure Native Joomla 6 plugin with SubscriberInterface.';
        }

        // 3. Determine subscribed Joomla 6 events based on plugin group & detected WP hooks
        $events = $this->resolveSubscribedEvents($analysis, $group);
        $eventsCode = '';
        foreach ($events as $eventName => $methodName) {
            $eventsCode .= "            '{$eventName}' => '{$methodName}',\n";
        }

        // 4. Joomla 6 DI Service Provider (modern Joomla 6: no deprecated dispatcher arg in constructor!)
        $providerCode = <<<PHP
<?php

declare(strict_types=1);

defined('_JEXEC') or die;

use Joomla\\CMS\\Extension\\PluginInterface;
use Joomla\\CMS\\Factory;
use Joomla\\CMS\\Plugin\\PluginHelper;
use Joomla\\Database\\DatabaseInterface;
use Joomla\\DI\\Container;
use Joomla\\DI\\ServiceProviderInterface;
use {$namespace}\\Extension\\{$studlySlug}Plugin;

return new class () implements ServiceProviderInterface {
    public function register(Container \$container): void
    {
        \$container->set(
            PluginInterface::class,
            static function (Container \$container): PluginInterface {
                \$plugin = new {$studlySlug}Plugin(
                    (array) PluginHelper::getPlugin('{$group}', '{$slug}')
                );
                \$plugin->setApplication(Factory::getApplication());
                \$plugin->setDatabase(\$container->get(DatabaseInterface::class));

                return \$plugin;
            }
        );
    }
};
PHP;
        $this->writeFile($outputDir, 'services/provider.php', $providerCode, $generated);

        // 5. Main Joomla 6 Plugin Extension Class
        $mainFileEsc = addslashes($meta->mainFile);
        $bootMethod = $options->isHybrid()
            ? <<<PHP
    private function bootWpBridge(): void
    {
        \$pluginDir = dirname(__DIR__, 2) . '/wp_source';
        \$pluginUrl = \\Joomla\\CMS\\Uri\\Uri::root() . 'plugins/{$group}/{$slug}/wp_source/';
        \\{$namespace}\\Runtime\\WpBridge::boot(\$pluginDir, \$pluginUrl, '{$mainFileEsc}', \$this->getDatabase());
    }
PHP
            : <<<PHP
    private function bootWpBridge(): void
    {
        require_once dirname(__DIR__) . '/Runtime/NativeHelper.php';
    }
PHP;

        $pluginClassCode = <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Extension;

defined('_JEXEC') or die;

use Joomla\\CMS\\Plugin\\CMSPlugin;
use Joomla\\Database\\DatabaseAwareTrait;
use Joomla\\Event\\Event;
use Joomla\\Event\\SubscriberInterface;

final class {$studlySlug}Plugin extends CMSPlugin implements SubscriberInterface
{
    use DatabaseAwareTrait;

    protected \$autoloadLanguage = true;

    public static function getSubscribedEvents(): array
    {
        return [
{$eventsCode}        ];
    }

{$bootMethod}

    public function onAfterInitialise(Event \$event): void
    {
        \$this->bootWpBridge();
    }

    public function onContentPrepare(Event \$event): void
    {
        \$this->bootWpBridge();
        if (!class_exists('JoomlaWpCompatRegistry')) {
            return;
        }

        \$item = method_exists(\$event, 'getItem') ? \$event->getItem() : \$event->getArgument(1);
        if (!is_object(\$item)) {
            return;
        }

        if (isset(\$item->text) && is_string(\$item->text)) {
            \$text = \\JoomlaWpCompatRegistry::doShortcode(\$item->text);
            \$text = (string) \\JoomlaWpCompatRegistry::applyFilters('the_content', \$text);
            \$item->text = \$text;
        }

        if (isset(\$item->title) && is_string(\$item->title)) {
            \$item->title = (string) \\JoomlaWpCompatRegistry::applyFilters('the_title', \$item->title);
        }
    }

    public function onBeforeCompileHead(Event \$event): void
    {
        \$this->bootWpBridge();
        if (!class_exists('JoomlaWpCompatRegistry')) {
            return;
        }

        if (\$this->getApplication()->isClient('administrator')) {
            \\JoomlaWpCompatRegistry::doAction('admin_enqueue_scripts');
        } else {
            \\JoomlaWpCompatRegistry::doAction('wp_enqueue_scripts');
        }
        \\JoomlaWpCompatRegistry::flushAssetsToJoomla();

        ob_start();
        \\JoomlaWpCompatRegistry::doAction(\$this->getApplication()->isClient('administrator') ? 'admin_head' : 'wp_head');
        \$headExtra = trim((string) ob_get_clean());
        if (\$headExtra !== '') {
            \$doc = \$this->getApplication()->getDocument();
            if (method_exists(\$doc, 'addCustomTag')) {
                \$doc->addCustomTag(\$headExtra);
            }
        }
    }

    public function onAfterRender(Event \$event): void
    {
        if (!class_exists('JoomlaWpCompatRegistry')) {
            return;
        }
        ob_start();
        \\JoomlaWpCompatRegistry::doAction(\$this->getApplication()->isClient('administrator') ? 'admin_footer' : 'wp_footer');
        \$footerExtra = trim((string) ob_get_clean());
        if (\$footerExtra !== '') {
            \$app  = \$this->getApplication();
            \$body = \$app->getBody();
            if (is_string(\$body) && str_contains(\$body, '</body>')) {
                \$app->setBody(str_replace('</body>', \$footerExtra . '</body>', \$body));
            }
        }
    }

    public function onUserAfterLogin(Event \$event): void
    {
        \$this->bootWpBridge();
        if (class_exists('JoomlaWpCompatRegistry')) {
            \$user = \$this->getApplication()->getIdentity();
            \\JoomlaWpCompatRegistry::doAction('wp_login', (string) \$user->username, \$user);
        }
    }

    public function onUserAfterLogout(Event \$event): void
    {
        \$this->bootWpBridge();
        if (class_exists('JoomlaWpCompatRegistry')) {
            \\JoomlaWpCompatRegistry::doAction('wp_logout');
        }
    }

    public function onUserAfterSave(Event \$event): void
    {
        \$this->bootWpBridge();
        if (class_exists('JoomlaWpCompatRegistry')) {
            \$user = method_exists(\$event, 'getUser') ? \$event->getUser() : \$event->getArgument(0);
            \$isNew = (bool) (method_exists(\$event, 'getIsNew') ? \$event->getIsNew() : \$event->getArgument(1));
            \$uid = is_array(\$user) ? (int) (\$user['id'] ?? 0) : 0;
            \\JoomlaWpCompatRegistry::doAction(\$isNew ? 'user_register' : 'profile_update', \$uid);
        }
    }

    public function onBeforeApiRoute(Event \$event): void
    {
        \$this->bootWpBridge();
        if (class_exists('JoomlaWpCompatRegistry')) {
            \\JoomlaWpCompatRegistry::doAction('rest_api_init');
        }
    }

    public function onAjax{$studlySlug}(Event \$event): void
    {
        \$this->bootWpBridge();
        \$action = \$this->getApplication()->getInput()->getString('action', '');
        if (\$action !== '' && class_exists('JoomlaWpCompatRegistry')) {
            \\JoomlaWpCompatRegistry::doAction('wp_ajax_' . \$action);
            \\JoomlaWpCompatRegistry::doAction('wp_ajax_nopriv_' . \$action);
        }
    }
}
PHP;
        $this->writeFile($outputDir, "src/Extension/{$studlySlug}Plugin.php", $pluginClassCode, $generated);

        // 6. Language files (en-GB + fa-IR)
        $safeName = $this->iniEscape($meta->name);
        $safeDesc = $this->iniEscape($meta->description ?: ('Compiled from WordPress plugin ' . $meta->name . ' for Joomla 6'));
        $iniBody  = "{$langPrefix}=\"{$safeName}\"\n{$langPrefix}_XML_DESCRIPTION=\"{$safeDesc}\"\n";

        $this->writeFile($outputDir, "language/en-GB/{$elementName}.ini", $iniBody, $generated);
        $this->writeFile($outputDir, "language/en-GB/{$elementName}.sys.ini", $iniBody, $generated);
        $this->writeFile($outputDir, "language/fa-IR/{$elementName}.ini", $iniBody, $generated);
        $this->writeFile($outputDir, "language/fa-IR/{$elementName}.sys.ini", $iniBody, $generated);

        // 7. Migration Report
        $this->writeFile($outputDir, 'MIGRATION_REPORT.md', $this->buildMigrationReport($analysis, $classification, $options, $elementName, $logs), $generated);

        // 8. Joomla 6 Plugin Manifest XML
        $manifestXml = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<extension type="plugin" group="{$this->xmlEscape($group)}" method="upgrade">
    <name>{$langPrefix}</name>
    <author>{$this->xmlEscape($meta->author)}</author>
    <creationDate>{$date}</creationDate>
    <copyright>(C) {$date} {$this->xmlEscape($meta->author)}</copyright>
    <license>{$this->xmlEscape($meta->license)}</license>
    <version>{$this->xmlEscape($meta->version)}</version>
    <description>{$langPrefix}_XML_DESCRIPTION</description>
    <namespace path="src">{$namespace}</namespace>

    <files>
        <folder plugin="{$slug}">services</folder>
        <folder>src</folder>
        <folder>wp_source</folder>
    </files>

    <languages>
        <language tag="en-GB">language/en-GB/{$elementName}.ini</language>
        <language tag="en-GB">language/en-GB/{$elementName}.sys.ini</language>
        <language tag="fa-IR">language/fa-IR/{$elementName}.ini</language>
        <language tag="fa-IR">language/fa-IR/{$elementName}.sys.ini</language>
    </languages>
</extension>
XML;
        $this->writeFile($outputDir, "{$slug}.xml", $manifestXml, $generated);

        return ['files' => $generated, 'logs' => $logs];
    }

    /**
     * @return array<string, string>
     */
    private function resolveSubscribedEvents(AnalysisResult $analysis, string $group): array
    {
        $studlySlug = $this->studlyCase($this->cleanSlug($analysis->metadata->slug));
        $events = [];

        if ($group === 'content') {
            $events['onContentPrepare'] = 'onContentPrepare';
        } elseif ($group === 'user') {
            $events['onUserAfterLogin']  = 'onUserAfterLogin';
            $events['onUserAfterLogout'] = 'onUserAfterLogout';
            $events['onUserAfterSave']   = 'onUserAfterSave';
        } elseif ($group === 'webservices') {
            $events['onBeforeApiRoute'] = 'onBeforeApiRoute';
        } else {
            $events['onAfterInitialise']   = 'onAfterInitialise';
            $events['onBeforeCompileHead'] = 'onBeforeCompileHead';
            $events['onAfterRender']       = 'onAfterRender';
            if (count($analysis->shortcodes) > 0 || count($analysis->filters) > 0) {
                $events['onContentPrepare'] = 'onContentPrepare';
            }
        }

        if (count($analysis->ajaxActions) > 0) {
            $events['onAjax' . $studlySlug] = 'onAjax' . $studlySlug;
        }

        return $events;
    }
}
