<?php

declare(strict_types=1);

namespace Sadjadii\Component\Copmiler\Administrator\Model;

defined('_JEXEC') or die;

use Joomla\CMS\MVC\Model\BaseDatabaseModel;

final class DashboardModel extends BaseDatabaseModel
{
    /**
     * @return list<object>
     */
    public function getRecentBuilds(int $limit = 30): array
    {
        $db = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select([
                'id', 'wp_name', 'wp_slug', 'wp_version', 'compile_mode',
                'detected_type', 'plugin_group', 'confidence', 'compatibility_score',
                'element_name', 'zip_filename', 'zip_size', 'created',
            ])
            ->from($db->quoteName('#__copmiler_builds'))
            ->order($db->quoteName('id') . ' DESC')
            ->setLimit($limit);

        $db->setQuery($query);

        return $db->loadObjectList() ?: [];
    }

    /**
     * @return array{total: int, components: int, plugins: int, modules: int}
     */
    public function getStats(): array
    {
        $db = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select([$db->quoteName('detected_type'), 'COUNT(*) AS cnt'])
            ->from($db->quoteName('#__copmiler_builds'))
            ->group($db->quoteName('detected_type'));

        $db->setQuery($query);
        $rows = $db->loadAssocList('detected_type', 'cnt') ?: [];

        $components = (int) ($rows['component'] ?? 0);
        $plugins    = (int) ($rows['plugin'] ?? 0);
        $modules    = (int) ($rows['module'] ?? 0);

        return [
            'total'      => $components + $plugins + $modules,
            'components' => $components,
            'plugins'    => $plugins,
            'modules'    => $modules,
        ];
    }
}
