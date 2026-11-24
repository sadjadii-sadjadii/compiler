<?php

declare(strict_types=1);

namespace Sadjadii\Component\Copmiler\Administrator\Compiler\Model;

/**
 * Full static analysis report of a WordPress plugin source directory.
 */
final readonly class AnalysisResult
{
    /**
     * @param PluginMetadata              $metadata
     * @param list<string>                $phpFiles          Relative paths of PHP files
     * @param list<string>                $assetFiles        Relative paths of CSS/JS/image/font files
     * @param list<array<string, mixed>>  $actions           add_action() calls
     * @param list<array<string, mixed>>  $filters           add_filter() calls
     * @param list<array<string, mixed>>  $shortcodes        add_shortcode() calls
     * @param list<array<string, mixed>>  $widgets           WP_Widget subclasses & register_widget() calls
     * @param list<array<string, mixed>>  $adminMenus        add_menu_page / add_submenu_page / add_options_page
     * @param list<array<string, mixed>>  $postTypes         register_post_type() calls
     * @param list<array<string, mixed>>  $taxonomies        register_taxonomy() calls
     * @param list<array<string, mixed>>  $restRoutes        register_rest_route() calls
     * @param list<string>                $ajaxActions       wp_ajax_* / wp_ajax_nopriv_* hooks
     * @param list<array<string, mixed>>  $cronEvents        wp_schedule_event() calls
     * @param list<string>                $dbTables          SQL CREATE TABLE statements found
     * @param list<string>                $optionsUsed       Option keys referenced via get_option/update_option/register_setting
     * @param list<array<string, mixed>>  $enqueuedAssets    wp_enqueue_script / wp_enqueue_style calls
     * @param list<string>                $wpApisUsed        Distinct WordPress API functions referenced
     * @param bool                        $usesWpdb          Whether $wpdb is used
     * @param bool                        $usesSettingsApi   Whether register_setting / add_settings_section is used
     * @param bool                        $usesGutenberg     Whether register_block_type is used
     * @param list<array<string, string>> $warnings          Static analysis warnings & PHP 8.3+ notes
     * @param int                         $compatibilityScore 0..100 estimated compatibility with Joomla 6
     */
    public function __construct(
        public PluginMetadata $metadata,
        public array $phpFiles,
        public array $assetFiles,
        public array $actions,
        public array $filters,
        public array $shortcodes,
        public array $widgets,
        public array $adminMenus,
        public array $postTypes,
        public array $taxonomies,
        public array $restRoutes,
        public array $ajaxActions,
        public array $cronEvents,
        public array $dbTables,
        public array $optionsUsed,
        public array $enqueuedAssets,
        public array $wpApisUsed,
        public bool $usesWpdb,
        public bool $usesSettingsApi,
        public bool $usesGutenberg,
        public array $warnings,
        public int $compatibilityScore,
    ) {
    }

    public function toArray(): array
    {
        return [
            'metadata'           => $this->metadata->toArray(),
            'phpFiles'           => $this->phpFiles,
            'assetFiles'         => $this->assetFiles,
            'actions'            => $this->actions,
            'filters'            => $this->filters,
            'shortcodes'         => $this->shortcodes,
            'widgets'            => $this->widgets,
            'adminMenus'         => $this->adminMenus,
            'postTypes'          => $this->postTypes,
            'taxonomies'         => $this->taxonomies,
            'restRoutes'         => $this->restRoutes,
            'ajaxActions'        => $this->ajaxActions,
            'cronEvents'         => $this->cronEvents,
            'dbTables'           => $this->dbTables,
            'optionsUsed'        => $this->optionsUsed,
            'enqueuedAssets'     => $this->enqueuedAssets,
            'wpApisUsed'         => $this->wpApisUsed,
            'usesWpdb'           => $this->usesWpdb,
            'usesSettingsApi'    => $this->usesSettingsApi,
            'usesGutenberg'      => $this->usesGutenberg,
            'warnings'           => $this->warnings,
            'compatibilityScore' => $this->compatibilityScore,
        ];
    }
}
