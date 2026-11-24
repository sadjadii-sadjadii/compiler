<?php

declare(strict_types=1);

defined('_JEXEC') or die;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Version;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            InstallerScriptInterface::class,
            new class () implements InstallerScriptInterface {
                private const MIN_PHP    = '8.3.0';
                private const MIN_JOOMLA = '6.0.0';

                public function install(InstallerAdapter $adapter): bool
                {
                    $this->ensureStorageDir();
                    return true;
                }

                public function update(InstallerAdapter $adapter): bool
                {
                    $this->ensureStorageDir();
                    return true;
                }

                public function uninstall(InstallerAdapter $adapter): bool
                {
                    return true;
                }

                public function preflight(string $type, InstallerAdapter $adapter): bool
                {
                    if ($type === 'uninstall') {
                        return true;
                    }

                    /** @var CMSApplicationInterface $app */
                    $app = Factory::getApplication();

                    if (version_compare(PHP_VERSION, self::MIN_PHP, '<')) {
                        $app->enqueueMessage(
                            sprintf('com_copmiler requires PHP %s or higher (detected %s).', self::MIN_PHP, PHP_VERSION),
                            'error'
                        );
                        return false;
                    }

                    if (!class_exists(\ZipArchive::class)) {
                        $app->enqueueMessage('com_copmiler requires the PHP ZipArchive extension.', 'error');
                        return false;
                    }

                    if (class_exists(Version::class) && version_compare(JVERSION, self::MIN_JOOMLA, '<')) {
                        $app->enqueueMessage(
                            sprintf('com_copmiler is built for Joomla %s+ (detected %s).', self::MIN_JOOMLA, JVERSION),
                            'warning'
                        );
                    }

                    return true;
                }

                public function postflight(string $type, InstallerAdapter $adapter): bool
                {
                    if ($type !== 'uninstall') {
                        $this->ensureStorageDir();
                    }
                    return true;
                }

                private function ensureStorageDir(): void
                {
                    $dir = JPATH_ADMINISTRATOR . '/components/com_copmiler/storage/builds';
                    if (!is_dir($dir)) {
                        @mkdir($dir, 0755, true);
                    }
                    $htaccess = dirname($dir) . '/.htaccess';
                    if (!is_file($htaccess)) {
                        @file_put_contents($htaccess, "Require all denied\n");
                    }
                }
            }
        );
    }
};
