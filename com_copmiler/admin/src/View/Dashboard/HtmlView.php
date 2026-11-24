<?php

declare(strict_types=1);

namespace Sadjadii\Component\Copmiler\Administrator\View\Dashboard;

defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Sadjadii\Component\Copmiler\Administrator\Model\DashboardModel;

final class HtmlView extends BaseHtmlView
{
    /** @var list<object> */
    public array $builds = [];

    /** @var array{total: int, components: int, plugins: int, modules: int} */
    public array $stats = ['total' => 0, 'components' => 0, 'plugins' => 0, 'modules' => 0];

    public string $defaultMode = 'hybrid';
    public string $vendorNamespace = 'WpCompiled';
    public int $maxUploadMb = 25;

    public function display($tpl = null): void
    {
        /** @var DashboardModel $model */
        $model = $this->getModel();
        $this->builds = $model->getRecentBuilds();
        $this->stats  = $model->getStats();

        $params = ComponentHelper::getParams('com_copmiler');
        $this->defaultMode     = (string) $params->get('default_mode', 'hybrid');
        $this->vendorNamespace = (string) $params->get('vendor_namespace', 'WpCompiled');
        $this->maxUploadMb     = max(1, (int) $params->get('max_upload_mb', 25));

        $this->addToolbar();

        $wa = $this->getDocument()->getWebAssetManager();
        $wa->getRegistry()->addExtensionRegistryFile('com_copmiler');
        $wa->useStyle('com_copmiler.admin');
        $wa->useScript('com_copmiler.admin');

        parent::display($tpl);
    }

    private function addToolbar(): void
    {
        ToolbarHelper::title(Text::_('COM_COPMILER_DASHBOARD_TITLE'), 'cogs');

        $user = Factory::getApplication()->getIdentity();
        if ($user->authorise('core.admin', 'com_copmiler') || $user->authorise('core.options', 'com_copmiler')) {
            ToolbarHelper::preferences('com_copmiler');
        }
    }
}
