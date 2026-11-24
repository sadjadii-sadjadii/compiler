<?php

declare(strict_types=1);

namespace Sadjadii\Component\Copmiler\Administrator\Controller;

defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Sadjadii\Component\Copmiler\Administrator\Compiler\CompilerEngine;
use Sadjadii\Component\Copmiler\Administrator\Compiler\Model\CompileOptions;
use Sadjadii\Component\Copmiler\Administrator\Model\BuildModel;

/**
 * Handles WordPress plugin upload, compilation to Joomla 6, ZIP download, and build deletion.
 */
final class CompileController extends BaseController
{
    public function compile(): void
    {
        Session::checkToken() or die(Text::_('JINVALID_TOKEN'));

        $user = $this->app->getIdentity();
        if (!$user->authorise('copmiler.compile', 'com_copmiler') && !$user->authorise('core.manage', 'com_copmiler')) {
            $this->setMessage(Text::_('JERROR_ALERTNOAUTHOR'), 'error');
            $this->setRedirect(Route::_('index.php?option=com_copmiler&view=dashboard', false));
            return;
        }

        $params      = ComponentHelper::getParams('com_copmiler');
        $maxUploadMb = max(1, (int) $params->get('max_upload_mb', 25));
        $input       = $this->app->getInput();
        $file        = $input->files->get('wp_plugin_zip', [], 'array');

        if (empty($file) || empty($file['tmp_name']) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->setMessage(Text::_('COM_COPMILER_ERR_UPLOAD_FAILED'), 'error');
            $this->setRedirect(Route::_('index.php?option=com_copmiler&view=dashboard', false));
            return;
        }

        $origName = (string) ($file['name'] ?? '');
        if (!str_ends_with(strtolower($origName), '.zip')) {
            $this->setMessage(Text::_('COM_COPMILER_ERR_INVALID_ZIP'), 'error');
            $this->setRedirect(Route::_('index.php?option=com_copmiler&view=dashboard', false));
            return;
        }

        if ((int) ($file['size'] ?? 0) > $maxUploadMb * 1024 * 1024) {
            $this->setMessage(Text::sprintf('COM_COPMILER_ERR_FILE_TOO_LARGE', $maxUploadMb), 'error');
            $this->setRedirect(Route::_('index.php?option=com_copmiler&view=dashboard', false));
            return;
        }

        $mode          = $input->post->getCmd('compile_mode', (string) $params->get('default_mode', CompileOptions::MODE_HYBRID));
        $targetOverride = $input->post->getCmd('target_override', CompileOptions::TARGET_AUTO);
        $pluginGroup   = $input->post->getCmd('plugin_group_override', 'auto');
        $vendorNs      = $input->post->getCmd('vendor_namespace', (string) $params->get('vendor_namespace', 'WpCompiled'));

        if (!in_array($mode, [CompileOptions::MODE_HYBRID, CompileOptions::MODE_NATIVE], true)) {
            $mode = CompileOptions::MODE_HYBRID;
        }

        $storageDir = JPATH_ADMINISTRATOR . '/components/com_copmiler/storage/builds';
        $tmpBase    = JPATH_ROOT . '/tmp';
        if (!is_dir($storageDir)) {
            @mkdir($storageDir, 0755, true);
        }

        $options = new CompileOptions(
            mode: $mode,
            targetOverride: $targetOverride ?: CompileOptions::TARGET_AUTO,
            pluginGroupOverride: $pluginGroup ?: 'auto',
            vendorNamespace: $vendorNs ?: 'WpCompiled',
        );

        try {
            $engine = new CompilerEngine();
            $result = $engine->compile((string) $file['tmp_name'], $storageDir, $options, $tmpBase);

            /** @var BuildModel $model */
            $model   = $this->getModel('Build', 'Administrator');
            $buildId = $model->saveBuild($result, (int) $user->id);

            $this->setMessage(
                Text::sprintf(
                    'COM_COPMILER_MSG_COMPILE_SUCCESS',
                    $result->analysis->metadata->name,
                    $result->elementName,
                    strtoupper($result->classification->type)
                ),
                'message'
            );

            $this->setRedirect(Route::_('index.php?option=com_copmiler&view=build&id=' . $buildId, false));
        } catch (\Throwable $e) {
            $this->setMessage(Text::sprintf('COM_COPMILER_ERR_COMPILE_EXCEPTION', $e->getMessage()), 'error');
            $this->setRedirect(Route::_('index.php?option=com_copmiler&view=dashboard', false));
        }
    }

    public function download(): void
    {
        Session::checkToken('get') or die(Text::_('JINVALID_TOKEN'));

        $id = $this->app->getInput()->getInt('id', 0);

        /** @var BuildModel $model */
        $model = $this->getModel('Build', 'Administrator');
        $build = $model->getBuild($id);

        if ($build === null || empty($build->zip_path) || !is_file((string) $build->zip_path)) {
            $this->setMessage(Text::_('COM_COPMILER_ERR_FILE_NOT_FOUND'), 'error');
            $this->setRedirect(Route::_('index.php?option=com_copmiler&view=dashboard', false));
            return;
        }

        // Prevent path traversal outside storage/builds
        $realStorage = realpath(JPATH_ADMINISTRATOR . '/components/com_copmiler/storage/builds');
        $realFile    = realpath((string) $build->zip_path);
        if ($realStorage === false || $realFile === false || !str_starts_with($realFile, $realStorage)) {
            $this->setMessage(Text::_('JERROR_ALERTNOAUTHOR'), 'error');
            $this->setRedirect(Route::_('index.php?option=com_copmiler&view=dashboard', false));
            return;
        }

        $filename = preg_replace('/[^a-zA-Z0-9._\-]/', '_', (string) $build->zip_filename) ?: 'joomla6-extension.zip';

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . (string) filesize($realFile));
        header('Cache-Control: no-store, no-cache, must-revalidate');

        readfile($realFile);
        $this->app->close();
    }

    public function delete(): void
    {
        Session::checkToken('get') or die(Text::_('JINVALID_TOKEN'));

        $user = $this->app->getIdentity();
        if (!$user->authorise('core.delete', 'com_copmiler') && !$user->authorise('core.admin', 'com_copmiler')) {
            $this->setMessage(Text::_('JERROR_ALERTNOAUTHOR'), 'error');
            $this->setRedirect(Route::_('index.php?option=com_copmiler&view=dashboard', false));
            return;
        }

        $id = $this->app->getInput()->getInt('id', 0);

        /** @var BuildModel $model */
        $model = $this->getModel('Build', 'Administrator');
        if ($model->deleteBuild($id)) {
            $this->setMessage(Text::_('COM_COPMILER_MSG_BUILD_DELETED'), 'message');
        } else {
            $this->setMessage(Text::_('COM_COPMILER_ERR_BUILD_DELETE_FAILED'), 'error');
        }

        $this->setRedirect(Route::_('index.php?option=com_copmiler&view=dashboard', false));
    }
}
