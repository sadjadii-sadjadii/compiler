<?php

declare(strict_types=1);

namespace Sadjadii\Component\Copmiler\Administrator\View\Build;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Toolbar\Toolbar;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Sadjadii\Component\Copmiler\Administrator\Model\BuildModel;

final class HtmlView extends BaseHtmlView
{
    public ?object $build = null;

    /** @var array<string, mixed> */
    public array $report = [];

    public string $downloadUrl = '';

    public function display($tpl = null): void
    {
        $id = Factory::getApplication()->getInput()->getInt('id', 0);

        /** @var BuildModel $model */
        $model = $this->getModel();
        $this->build = $model->getBuild($id);

        if ($this->build !== null) {
            $decoded = json_decode((string) $this->build->report_json, true);
            $this->report = is_array($decoded) ? $decoded : [];
            $token = Session::getFormToken();
            $this->downloadUrl = Route::_('index.php?option=com_copmiler&task=compile.download&id=' . (int) $this->build->id . '&' . $token . '=1', false);
        }

        $this->addToolbar();

        $wa = $this->getDocument()->getWebAssetManager();
        $wa->getRegistry()->addExtensionRegistryFile('com_copmiler');
        $wa->useStyle('com_copmiler.admin');

        parent::display($tpl);
    }

    private function addToolbar(): void
    {
        $title = $this->build !== null
            ? Text::sprintf('COM_COPMILER_BUILD_REPORT_TITLE', $this->build->wp_name)
            : Text::_('COM_COPMILER_BUILD_NOT_FOUND');

        ToolbarHelper::title($title, 'file-check');

        $toolbar = Toolbar::getInstance('toolbar');
        $toolbar->linkButton('back', 'COM_COPMILER_BTN_BACK_DASHBOARD')
            ->url(Route::_('index.php?option=com_copmiler&view=dashboard', false))
            ->icon('icon-arrow-left');

        if ($this->downloadUrl !== '') {
            $toolbar->linkButton('download', 'COM_COPMILER_BTN_DOWNLOAD_ZIP')
                ->url($this->downloadUrl)
                ->buttonClass('btn btn-success')
                ->icon('icon-download');
        }
    }
}
