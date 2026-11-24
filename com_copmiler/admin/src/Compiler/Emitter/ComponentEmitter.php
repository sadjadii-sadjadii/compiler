<?php

declare(strict_types=1);

namespace Sadjadii\Component\Copmiler\Administrator\Compiler\Emitter;

use Sadjadii\Component\Copmiler\Administrator\Compiler\Model\AnalysisResult;
use Sadjadii\Component\Copmiler\Administrator\Compiler\Model\ClassificationResult;
use Sadjadii\Component\Copmiler\Administrator\Compiler\Model\CompileOptions;
use Sadjadii\Component\Copmiler\Administrator\Compiler\Runtime\WpBridgeTemplate;
use Sadjadii\Component\Copmiler\Administrator\Compiler\Transformer\PhpCodeTransformer;
use Sadjadii\Component\Copmiler\Administrator\Compiler\Transformer\SqlSchemaConverter;

/**
 * Emits a fully installable Joomla 6 Component (com_<slug>).
 */
final class ComponentEmitter extends AbstractEmitter
{
    public function __construct(
        PhpCodeTransformer $phpTransformer,
        private readonly WpBridgeTemplate $bridgeTemplate,
        private readonly SqlSchemaConverter $sqlConverter,
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
        $elementName = 'com_' . $slug;
        $studly      = $this->studlyCase($slug);
        $vendor      = $this->studlyCase($options->vendorNamespace);
        $baseNs      = $vendor . '\\Component\\' . $studly;
        $adminNs     = $baseNs . '\\Administrator';
        $siteNs      = $baseNs . '\\Site';
        $langPrefix  = strtoupper($elementName);
        $date        = date('Y-m-d');

        // 1. Copy & transform original WP plugin files into admin/wp_source
        $this->copySourceFiles($sourceDir, $outputDir, 'admin/wp_source', $analysis, $options, $elementName, $generated, $logs);

        // 2. Bundle Joomla 6 Runtime Bridge (Hybrid) or Native Helper (Native)
        if ($options->isHybrid()) {
            $this->writeFile($outputDir, 'admin/src/Runtime/WpBridge.php', $this->bridgeTemplate->renderBridgeBootstrap($adminNs, $slug, $elementName, 'component'), $generated);
            $this->writeFile($outputDir, 'admin/src/Runtime/WpdbAdapter.php', $this->bridgeTemplate->renderWpdbAdapter(), $generated);
            $this->writeFile($outputDir, 'admin/src/Runtime/WpCompatRegistry.php', $this->bridgeTemplate->renderCompatRegistry(), $generated);
            $this->writeFile($outputDir, 'admin/src/Runtime/wp_functions.php', $this->bridgeTemplate->renderWpFunctions(), $generated);
            $logs[] = 'Bundled Joomla 6 WordPress Runtime Bridge into admin/src/Runtime/.';
        } else {
            $this->writeFile($outputDir, 'admin/src/Runtime/NativeHelper.php', $this->bridgeTemplate->renderNativeHelper(), $generated);
            $logs[] = 'Generated pure Native Joomla 6 component skeleton with zero WordPress runtime shim.';
        }

        // 3. SQL install & uninstall
        $sqlData = $this->sqlConverter->convert($analysis->dbTables, $slug, $options->isHybrid());
        $this->writeFile($outputDir, 'admin/sql/install.mysql.utf8.sql', $sqlData['install'], $generated);
        $this->writeFile($outputDir, 'admin/sql/uninstall.mysql.utf8.sql', $sqlData['uninstall'], $generated);
        $this->writeFile($outputDir, 'admin/sql/updates/mysql/' . $meta->version . '.sql', "-- Update script for {$elementName} {$meta->version}\n", $generated);
        $logs[] = sprintf('Generated Joomla 6 SQL schema covering %d table(s).', count($sqlData['tables']));

        // 4. Joomla 6 DI Service Provider
        $providerCode = <<<PHP
<?php

declare(strict_types=1);

defined('_JEXEC') or die;

use Joomla\\CMS\\Dispatcher\\ComponentDispatcherFactoryInterface;
use Joomla\\CMS\\Extension\\ComponentInterface;
use Joomla\\CMS\\Extension\\Service\\Provider\\ComponentDispatcherFactory;
use Joomla\\CMS\\Extension\\Service\\Provider\\MVCFactory;
use Joomla\\CMS\\MVC\\Factory\\MVCFactoryInterface;
use Joomla\\DI\\Container;
use Joomla\\DI\\ServiceProviderInterface;
use {$adminNs}\\Extension\\{$studly}Component;

return new class () implements ServiceProviderInterface {
    public function register(Container \$container): void
    {
        \$container->registerServiceProvider(new MVCFactory('\\\\{$vendor}\\\\Component\\\\{$studly}'));
        \$container->registerServiceProvider(new ComponentDispatcherFactory('\\\\{$vendor}\\\\Component\\\\{$studly}'));

        \$container->set(
            ComponentInterface::class,
            static function (Container \$container): ComponentInterface {
                \$component = new {$studly}Component(
                    \$container->get(ComponentDispatcherFactoryInterface::class)
                );
                \$component->setMVCFactory(\$container->get(MVCFactoryInterface::class));

                return \$component;
            }
        );
    }
};
PHP;
        $this->writeFile($outputDir, 'admin/services/provider.php', $providerCode, $generated);

        // 5. Extension class
        $extCode = <<<PHP
<?php

declare(strict_types=1);

namespace {$adminNs}\\Extension;

defined('_JEXEC') or die;

use Joomla\\CMS\\Extension\\MVCComponent;

class {$studly}Component extends MVCComponent
{
}
PHP;
        $this->writeFile($outputDir, "admin/src/Extension/{$studly}Component.php", $extCode, $generated);

        // 6. Admin DisplayController (supports WP Settings save + WP AJAX dispatch)
        $mainFileEsc = addslashes($meta->mainFile);
        $bootSnippet = $options->isHybrid()
            ? <<<PHP
        \\{$adminNs}\\Runtime\\WpBridge::boot(
            JPATH_COMPONENT_ADMINISTRATOR . '/wp_source',
            \\Joomla\\CMS\\Uri\\Uri::root() . 'administrator/components/{$elementName}/wp_source/',
            '{$mainFileEsc}'
        );
PHP
            : "        require_once JPATH_COMPONENT_ADMINISTRATOR . '/src/Runtime/NativeHelper.php';";

        $adminController = <<<PHP
<?php

declare(strict_types=1);

namespace {$adminNs}\\Controller;

defined('_JEXEC') or die;

use Joomla\\CMS\\Language\\Text;
use Joomla\\CMS\\MVC\\Controller\\BaseController;
use Joomla\\CMS\\Router\\Route;
use Joomla\\CMS\\Session\\Session;

class DisplayController extends BaseController
{
    protected \$default_view = 'dashboard';

    public function display(\$cachable = false, \$urlparams = [])
    {
{$bootSnippet}
        return parent::display(\$cachable, \$urlparams);
    }

    public function saveSettings(): void
    {
        Session::checkToken() or die(Text::_('JINVALID_TOKEN'));
{$bootSnippet}

        \$input = \$this->app->getInput();
        \$group = \$input->post->getString('wp_option_group', '');

        if (\$group !== '' && class_exists('JoomlaWpCompatRegistry') && !empty(\\JoomlaWpCompatRegistry::\$settings[\$group])) {
            foreach (array_keys(\\JoomlaWpCompatRegistry::\$settings[\$group]) as \$optionName) {
                \$val = \$input->post->get(\$optionName, '', 'RAW');
                \\JoomlaWpCompatRegistry::updateOption((string) \$optionName, \$val);
            }
            \$this->setMessage(Text::_('{$langPrefix}_SETTINGS_SAVED'), 'message');
        }

        \$page = \$input->getString('wp_page', '');
        \$url  = 'index.php?option={$elementName}&view=dashboard' . (\$page !== '' ? '&wp_page=' . urlencode(\$page) : '');
        \$this->setRedirect(Route::_(\$url, false));
    }

    public function ajax(): void
    {
{$bootSnippet}
        \$action = \$this->app->getInput()->getString('action', '');
        if (\$action !== '' && class_exists('JoomlaWpCompatRegistry')) {
            \\JoomlaWpCompatRegistry::doAction('wp_ajax_' . \$action);
            \\JoomlaWpCompatRegistry::doAction('wp_ajax_nopriv_' . \$action);
        }
        \$this->app->close();
    }
}
PHP;
        $this->writeFile($outputDir, 'admin/src/Controller/DisplayController.php', $adminController, $generated);

        // 7. Admin Dashboard View & Template
        $adminView = <<<PHP
<?php

declare(strict_types=1);

namespace {$adminNs}\\View\\Dashboard;

defined('_JEXEC') or die;

use Joomla\\CMS\\Factory;
use Joomla\\CMS\\Language\\Text;
use Joomla\\CMS\\MVC\\View\\HtmlView as BaseHtmlView;
use Joomla\\CMS\\Toolbar\\ToolbarHelper;

class HtmlView extends BaseHtmlView
{
    public array \$wpPages = [];
    public string \$activePage = '';
    public string \$renderedHtml = '';

    public function display(\$tpl = null): void
    {
        ToolbarHelper::title(Text::_('{$langPrefix}'), 'puzzle');
        if (Factory::getApplication()->getIdentity()->authorise('core.admin', '{$elementName}')) {
            ToolbarHelper::preferences('{$elementName}');
        }

        if (class_exists('JoomlaWpCompatRegistry')) {
            \\JoomlaWpCompatRegistry::doAction('admin_enqueue_scripts');
            \\JoomlaWpCompatRegistry::flushAssetsToJoomla();
            \$this->wpPages = \\JoomlaWpCompatRegistry::\$adminPages;

            \$requested = Factory::getApplication()->getInput()->getString('wp_page', '');
            if (\$requested !== '' && isset(\$this->wpPages[\$requested])) {
                \$this->activePage = \$requested;
            } elseif (\$this->wpPages !== []) {
                \$this->activePage = (string) array_key_first(\$this->wpPages);
            }

            if (\$this->activePage !== '' && !empty(\$this->wpPages[\$this->activePage]['callback'])) {
                \$cb = \$this->wpPages[\$this->activePage]['callback'];
                if (is_callable(\$cb)) {
                    ob_start();
                    \$cb();
                    \$html = (string) ob_get_clean();
                    // Rewrite WP options.php form action to Joomla saveSettings task
                    \$actionUrl = 'index.php?option={$elementName}&task=display.saveSettings&wp_page=' . urlencode(\$this->activePage);
                    \$html = preg_replace('/action=["\']options\.php["\']/i', 'action="' . htmlspecialchars(\$actionUrl, ENT_QUOTES, 'UTF-8') . '"', \$html) ?? \$html;
                    \$this->renderedHtml = \$html;
                }
            }
        }

        parent::display(\$tpl);
    }
}
PHP;
        $this->writeFile($outputDir, 'admin/src/View/Dashboard/HtmlView.php', $adminView, $generated);

        $nativeSummaryHtml = '';
        if ($options->isNative()) {
            $menuList = '';
            foreach ($analysis->adminMenus as $m) {
                $menuList .= '<li><strong>' . htmlspecialchars($m['menuTitle'] ?: $m['menuSlug'], ENT_QUOTES, 'UTF-8') . '</strong> (<code>' . htmlspecialchars($m['menuSlug'], ENT_QUOTES, 'UTF-8') . '</code>)</li>';
            }
            $nativeSummaryHtml = '<div class="alert alert-info"><h4>Native Joomla 6 Mode</h4><p>Static source files are transpiled inside <code>admin/wp_source/</code>. Review <code>MIGRATION_REPORT.md</code> to connect custom controllers and models.</p>' . ($menuList !== '' ? '<ul>' . $menuList . '</ul>' : '') . '</div>';
        }

        $adminTmpl = <<<PHP
<?php

declare(strict_types=1);

defined('_JEXEC') or die;

use Joomla\\CMS\\Language\\Text;
use Joomla\\CMS\\Router\\Route;
?>
<div class="container-fluid">
    <?php if (count(\$this->wpPages) > 1) : ?>
        <ul class="nav nav-tabs mb-3">
            <?php foreach (\$this->wpPages as \$slugKey => \$pageInfo) : ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo \$slugKey === \$this->activePage ? 'active' : ''; ?>"
                       href="<?php echo Route::_('index.php?option={$elementName}&view=dashboard&wp_page=' . urlencode((string) \$slugKey)); ?>">
                        <?php echo htmlspecialchars((string) (\$pageInfo['menu_title'] ?: \$pageInfo['page_title'] ?: \$slugKey), ENT_QUOTES, 'UTF-8'); ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <div class="card">
        <div class="card-body">
            <?php if (\$this->renderedHtml !== '') : ?>
                <?php echo \$this->renderedHtml; ?>
            <?php else : ?>
                <h3><?php echo Text::_('{$langPrefix}'); ?></h3>
                <p><?php echo Text::_('{$langPrefix}_XML_DESCRIPTION'); ?></p>
                {$nativeSummaryHtml}
            <?php endif; ?>
        </div>
    </div>
</div>
PHP;
        $this->writeFile($outputDir, 'admin/tmpl/dashboard/default.php', $adminTmpl, $generated);

        // 8. Site Controller, View & Template (supports frontend shortcode rendering)
        $firstShortcode = $analysis->shortcodes[0]['tag'] ?? '';
        $siteController = <<<PHP
<?php

declare(strict_types=1);

namespace {$siteNs}\\Controller;

defined('_JEXEC') or die;

use Joomla\\CMS\\MVC\\Controller\\BaseController;

class DisplayController extends BaseController
{
    protected \$default_view = 'display';

    public function display(\$cachable = false, \$urlparams = [])
    {
{$bootSnippet}
        return parent::display(\$cachable, \$urlparams);
    }
}
PHP;
        $this->writeFile($outputDir, 'site/src/Controller/DisplayController.php', $siteController, $generated);

        $siteView = <<<PHP
<?php

declare(strict_types=1);

namespace {$siteNs}\\View\\Display;

defined('_JEXEC') or die;

use Joomla\\CMS\\MVC\\View\\HtmlView as BaseHtmlView;

class HtmlView extends BaseHtmlView
{
    public string \$output = '';

    public function display(\$tpl = null): void
    {
        if (class_exists('JoomlaWpCompatRegistry')) {
            \\JoomlaWpCompatRegistry::doAction('wp_enqueue_scripts');
            \\JoomlaWpCompatRegistry::flushAssetsToJoomla();
            \$shortcodeTag = '{$firstShortcode}';
            if (\$shortcodeTag !== '' && isset(\\JoomlaWpCompatRegistry::\$shortcodes[\$shortcodeTag])) {
                \$this->output = \\JoomlaWpCompatRegistry::doShortcode('[' . \$shortcodeTag . ']');
            }
        }
        parent::display(\$tpl);
    }
}
PHP;
        $this->writeFile($outputDir, 'site/src/View/Display/HtmlView.php', $siteView, $generated);

        $siteTmpl = <<<'PHP'
<?php

declare(strict_types=1);

defined('_JEXEC') or die;
?>
<div class="com-wp-compiled-site">
    <?php echo $this->output; ?>
</div>
PHP;
        $this->writeFile($outputDir, 'site/tmpl/display/default.php', $siteTmpl, $generated);

        // 9. access.xml & config.xml
        $accessXml = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<access component="{$elementName}">
    <section name="component">
        <action name="core.admin" title="JACTION_ADMIN" />
        <action name="core.manage" title="JACTION_MANAGE" />
        <action name="core.create" title="JACTION_CREATE" />
        <action name="core.delete" title="JACTION_DELETE" />
        <action name="core.edit" title="JACTION_EDIT" />
    </section>
</access>
XML;
        $this->writeFile($outputDir, 'admin/access.xml', $accessXml, $generated);

        $configXml = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<config>
    <fieldset name="permissions" label="JCONFIG_PERMISSIONS_LABEL" description="JCONFIG_PERMISSIONS_DESC">
        <field name="rules" type="rules" label="JCONFIG_PERMISSIONS_LABEL" filter="rules" validate="rules" component="{$elementName}" section="component" />
    </fieldset>
</config>
XML;
        $this->writeFile($outputDir, 'admin/config.xml', $configXml, $generated);

        // 10. Language files (en-GB + fa-IR)
        $safeName = $this->iniEscape($meta->name);
        $safeDesc = $this->iniEscape($meta->description ?: ('Compiled from WordPress plugin ' . $meta->name . ' for Joomla 6'));
        $enIni = "{$langPrefix}=\"{$safeName}\"\n{$langPrefix}_XML_DESCRIPTION=\"{$safeDesc}\"\n{$langPrefix}_SETTINGS_SAVED=\"Settings saved successfully.\"\n";
        $faIni = "{$langPrefix}=\"{$safeName}\"\n{$langPrefix}_XML_DESCRIPTION=\"{$safeDesc}\"\n{$langPrefix}_SETTINGS_SAVED=\"تنظیمات با موفقیت ذخیره شد.\"\n";

        $this->writeFile($outputDir, "admin/language/en-GB/{$elementName}.ini", $enIni, $generated);
        $this->writeFile($outputDir, "admin/language/en-GB/{$elementName}.sys.ini", $enIni, $generated);
        $this->writeFile($outputDir, "admin/language/fa-IR/{$elementName}.ini", $faIni, $generated);
        $this->writeFile($outputDir, "admin/language/fa-IR/{$elementName}.sys.ini", $faIni, $generated);

        // 11. Migration report
        $this->writeFile($outputDir, 'MIGRATION_REPORT.md', $this->buildMigrationReport($analysis, $classification, $options, $elementName, $logs), $generated);

        // 12. Joomla 6 Component Manifest XML
        $manifestXml = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<extension type="component" method="upgrade">
    <name>{$langPrefix}</name>
    <author>{$this->xmlEscape($meta->author)}</author>
    <creationDate>{$date}</creationDate>
    <copyright>(C) {$date} {$this->xmlEscape($meta->author)}</copyright>
    <license>{$this->xmlEscape($meta->license)}</license>
    <version>{$this->xmlEscape($meta->version)}</version>
    <description>{$langPrefix}_XML_DESCRIPTION</description>
    <namespace path="src">{$vendor}\\Component\\{$studly}</namespace>

    <install>
        <sql>
            <file driver="mysql" charset="utf8">sql/install.mysql.utf8.sql</file>
        </sql>
    </install>
    <uninstall>
        <sql>
            <file driver="mysql" charset="utf8">sql/uninstall.mysql.utf8.sql</file>
        </sql>
    </uninstall>
    <update>
        <schemas>
            <schemapath type="mysql">sql/updates/mysql</schemapath>
        </schemas>
    </update>

    <files folder="site">
        <folder>src</folder>
        <folder>tmpl</folder>
    </files>

    <administration>
        <menu>{$langPrefix}</menu>
        <files folder="admin">
            <filename>access.xml</filename>
            <filename>config.xml</filename>
            <folder>services</folder>
            <folder>sql</folder>
            <folder>src</folder>
            <folder>tmpl</folder>
            <folder>wp_source</folder>
        </files>
        <languages folder="admin/language">
            <language tag="en-GB">en-GB/{$elementName}.ini</language>
            <language tag="en-GB">en-GB/{$elementName}.sys.ini</language>
            <language tag="fa-IR">fa-IR/{$elementName}.ini</language>
            <language tag="fa-IR">fa-IR/{$elementName}.sys.ini</language>
        </languages>
    </administration>
</extension>
XML;
        $this->writeFile($outputDir, "{$slug}.xml", $manifestXml, $generated);

        return ['files' => $generated, 'logs' => $logs];
    }
}
