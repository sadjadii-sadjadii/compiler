<?php

declare(strict_types=1);

namespace Sadjadii\Component\Copmiler\Administrator\Model;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Sadjadii\Component\Copmiler\Administrator\Compiler\Model\CompileResult;

final class BuildModel extends BaseDatabaseModel
{
    public function saveBuild(CompileResult $result, int $userId): int
    {
        $db   = $this->getDatabase();
        $meta = $result->analysis->metadata;
        $cls  = $result->classification;

        $row = (object) [
            'wp_name'             => substr($meta->name, 0, 255),
            'wp_slug'             => substr($meta->slug, 0, 128),
            'wp_version'          => substr($meta->version, 0, 64),
            'compile_mode'        => $result->options->mode,
            'detected_type'       => $cls->type,
            'plugin_group'        => $cls->pluginGroup,
            'confidence'          => $cls->confidence,
            'compatibility_score' => $result->analysis->compatibilityScore,
            'element_name'        => $result->elementName,
            'zip_filename'        => $result->zipFilename,
            'zip_path'            => $result->zipPath,
            'zip_size'            => $result->zipSize,
            'report_json'         => json_encode($result->toArray(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'created'             => Factory::getDate()->toSql(),
            'created_by'          => $userId,
        ];

        $db->insertObject('#__copmiler_builds', $row, 'id');

        return (int) $row->id;
    }

    public function getBuild(int $id): ?object
    {
        if ($id <= 0) {
            return null;
        }

        $db = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__copmiler_builds'))
            ->where($db->quoteName('id') . ' = ' . $id);

        $db->setQuery($query);
        $item = $db->loadObject();

        return is_object($item) ? $item : null;
    }

    public function deleteBuild(int $id): bool
    {
        $build = $this->getBuild($id);
        if ($build === null) {
            return false;
        }

        if (!empty($build->zip_path) && is_file((string) $build->zip_path)) {
            $realStorage = realpath(JPATH_ADMINISTRATOR . '/components/com_copmiler/storage/builds');
            $realFile    = realpath((string) $build->zip_path);
            if ($realStorage !== false && $realFile !== false && str_starts_with($realFile, $realStorage)) {
                @unlink($realFile);
            }
        }

        $db = $this->getDatabase();
        $query = $db->getQuery(true)
            ->delete($db->quoteName('#__copmiler_builds'))
            ->where($db->quoteName('id') . ' = ' . $id);
        $db->setQuery($query);
        $db->execute();

        return true;
    }
}
