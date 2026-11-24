<?php

declare(strict_types=1);

namespace Sadjadii\Component\Copmiler\Administrator\Compiler\Analyzer;

use Sadjadii\Component\Copmiler\Administrator\Compiler\Model\AnalysisResult;
use Sadjadii\Component\Copmiler\Administrator\Compiler\Model\ClassificationResult;
use Sadjadii\Component\Copmiler\Administrator\Compiler\Model\CompileOptions;

/**
 * Classifies any WordPress plugin into its exact Joomla 6 equivalent extension type:
 *  - component (com_*)   : Admin screens, CPTs, taxonomies, custom DB tables, full apps
 *  - module    (mod_*)   : WP_Widget / register_widget display blocks
 *  - plugin    (plg_*_*) : Event hooks, shortcodes, content filters, auth/user/REST/cron listeners
 *
 * Also determines the exact Joomla 6 plugin group (content, user, authentication, webservices, task, system).
 */
final class ExtensionTypeDetector
{
    public function detect(AnalysisResult $analysis, CompileOptions $options): ClassificationResult
    {
        $scores = [
            ClassificationResult::TYPE_COMPONENT => 0,
            ClassificationResult::TYPE_PLUGIN    => 0,
            ClassificationResult::TYPE_MODULE    => 0,
        ];
        $reasons = [];

        // --- COMPONENT SIGNALS ---
        $topMenus = array_filter(
            $analysis->adminMenus,
            static fn (array $m): bool => in_array($m['function'], ['add_menu_page', 'add_submenu_page'], true)
        );
        $settingsMenus = array_filter(
            $analysis->adminMenus,
            static fn (array $m): bool => !in_array($m['function'], ['add_menu_page', 'add_submenu_page'], true)
        );

        if (count($topMenus) > 0) {
            $pts = 45 + min(30, (count($topMenus) - 1) * 15);
            $scores[ClassificationResult::TYPE_COMPONENT] += $pts;
            $reasons[] = sprintf('Registers %d dedicated WordPress admin menu/submenu page(s) (+%d component).', count($topMenus), $pts);
        }

        if (count($settingsMenus) > 0) {
            // Options pages alone might be a settings-backed component or a configurable plugin
            $pts = count($analysis->dbTables) > 0 || count($analysis->postTypes) > 0 ? 30 : 20;
            $scores[ClassificationResult::TYPE_COMPONENT] += $pts;
            $reasons[] = sprintf('Registers %d WordPress options/management page(s) (+%d component).', count($settingsMenus), $pts);
        }

        if (count($analysis->postTypes) > 0) {
            $pts = 50 + min(25, (count($analysis->postTypes) - 1) * 15);
            $scores[ClassificationResult::TYPE_COMPONENT] += $pts;
            $reasons[] = sprintf('Registers %d Custom Post Type(s): %s (+%d component).', count($analysis->postTypes), implode(', ', array_column($analysis->postTypes, 'slug')), $pts);
        }

        if (count($analysis->taxonomies) > 0) {
            $pts = 25;
            $scores[ClassificationResult::TYPE_COMPONENT] += $pts;
            $reasons[] = sprintf('Registers %d custom taxonomy/taxonomies (+%d component).', count($analysis->taxonomies), $pts);
        }

        if (count($analysis->dbTables) > 0) {
            $pts = 45;
            $scores[ClassificationResult::TYPE_COMPONENT] += $pts;
            $reasons[] = sprintf('Creates %d custom database table(s) (+%d component).', count($analysis->dbTables), $pts);
        } elseif ($analysis->usesWpdb) {
            $scores[ClassificationResult::TYPE_COMPONENT] += 15;
            $reasons[] = 'Uses $wpdb for direct database queries (+15 component).';
        }

        if ($analysis->usesSettingsApi && count($analysis->adminMenus) > 0) {
            $scores[ClassificationResult::TYPE_COMPONENT] += 15;
            $reasons[] = 'Uses WordPress Settings API with admin UI (+15 component).';
        }

        // --- MODULE (WIDGET) SIGNALS ---
        if (count($analysis->widgets) > 0) {
            $pts = 65 + min(20, (count($analysis->widgets) - 1) * 10);
            $scores[ClassificationResult::TYPE_MODULE] += $pts;
            $reasons[] = sprintf('Defines %d WP_Widget class(es): %s (+%d module).', count($analysis->widgets), implode(', ', array_column($analysis->widgets, 'class')), $pts);
        }

        // Pure widget plugins typically only hook widgets_init + enqueue styles/scripts
        $nonWidgetHooks = array_filter(
            $analysis->actions,
            static fn (array $a): bool => !in_array($a['hook'], ['widgets_init', 'wp_enqueue_scripts', 'init', 'plugins_loaded'], true)
        );
        if (count($analysis->widgets) > 0 && count($nonWidgetHooks) === 0 && count($analysis->adminMenus) === 0 && count($analysis->dbTables) === 0) {
            $scores[ClassificationResult::TYPE_MODULE] += 25;
            $reasons[] = 'Primary entry point is widgets_init with no standalone admin screens (+25 module).';
        }

        // --- PLUGIN (EVENT / SHORTCODE / FILTER / REST / CRON) SIGNALS ---
        if (count($analysis->shortcodes) > 0) {
            $pts = 40 + min(20, (count($analysis->shortcodes) - 1) * 10);
            $scores[ClassificationResult::TYPE_PLUGIN] += $pts;
            $reasons[] = sprintf('Registers %d shortcode(s): [%s] (+%d plugin).', count($analysis->shortcodes), implode('], [', array_column($analysis->shortcodes, 'tag')), $pts);
        }

        if (count($analysis->filters) > 0) {
            $pts = 30 + min(25, (count($analysis->filters) - 1) * 5);
            $scores[ClassificationResult::TYPE_PLUGIN] += $pts;
            $reasons[] = sprintf('Hooks %d WordPress filter(s) (+%d plugin).', count($analysis->filters), $pts);
        }

        if (count($nonWidgetHooks) > 0) {
            $pts = 25 + min(25, (count($nonWidgetHooks) - 1) * 5);
            $scores[ClassificationResult::TYPE_PLUGIN] += $pts;
            $reasons[] = sprintf('Hooks %d lifecycle/system action(s) (+%d plugin).', count($nonWidgetHooks), $pts);
        }

        if (count($analysis->restRoutes) > 0) {
            $scores[ClassificationResult::TYPE_PLUGIN] += 30;
            $reasons[] = sprintf('Exposes %d REST API route(s) (+30 plugin).', count($analysis->restRoutes));
        }

        if (count($analysis->cronEvents) > 0) {
            $scores[ClassificationResult::TYPE_PLUGIN] += 25;
            $reasons[] = sprintf('Schedules %d background cron event(s) (+25 plugin).', count($analysis->cronEvents));
        }

        // If everything scored 0 (minimal procedural plugin), default to system plugin
        if (array_sum($scores) === 0) {
            $scores[ClassificationResult::TYPE_PLUGIN] = 20;
            $reasons[] = 'No component or widget signatures detected; defaults to Joomla 6 system plugin (+20 plugin).';
        }

        // Determine winning type
        $sorted = $scores;
        arsort($sorted);
        $topTypes = array_keys($sorted);
        $detectedType = $topTypes[0];

        $total = max(1, array_sum($scores));
        $topScore = $scores[$detectedType];
        $secondScore = $scores[$topTypes[1]];
        $margin = $topScore - $secondScore;
        $confidence = (int) min(99, max(45, round(($topScore / $total) * 70 + min(30, $margin * 0.6))));

        // Determine exact Joomla 6 plugin group
        $pluginGroup = $this->detectPluginGroup($analysis, $options->pluginGroupOverride);

        // Apply manual target override if user requested a specific extension type
        $overridden = false;
        if ($options->targetOverride !== CompileOptions::TARGET_AUTO
            && in_array($options->targetOverride, [
                ClassificationResult::TYPE_COMPONENT,
                ClassificationResult::TYPE_PLUGIN,
                ClassificationResult::TYPE_MODULE,
            ], true)
        ) {
            if ($options->targetOverride !== $detectedType) {
                $overridden = true;
                $reasons[] = sprintf('User manually overridden target type from "%s" to "%s".', $detectedType, $options->targetOverride);
                $detectedType = $options->targetOverride;
            }
        }

        return new ClassificationResult(
            type: $detectedType,
            pluginGroup: $pluginGroup,
            scores: $scores,
            confidence: $confidence,
            reasons: $reasons,
            overridden: $overridden,
        );
    }

    private function detectPluginGroup(AnalysisResult $analysis, string $groupOverride): string
    {
        $allowedGroups = ['system', 'content', 'user', 'authentication', 'webservices', 'task'];
        if ($groupOverride !== 'auto' && in_array($groupOverride, $allowedGroups, true)) {
            return $groupOverride;
        }

        $groupScores = [
            'content'        => 0,
            'user'           => 0,
            'authentication' => 0,
            'webservices'    => 0,
            'task'           => 0,
            'system'         => 5, // baseline fallback
        ];

        $allHooks = array_merge(
            array_column($analysis->actions, 'hook'),
            array_column($analysis->filters, 'hook')
        );

        if (count($analysis->shortcodes) > 0) {
            $groupScores['content'] += 40;
        }

        if (count($analysis->restRoutes) > 0) {
            $groupScores['webservices'] += 45;
        }

        if (count($analysis->cronEvents) > 0) {
            $groupScores['task'] += 35;
        }

        foreach ($allHooks as $hook) {
            $h = strtolower($hook);

            if (in_array($h, ['the_content', 'the_title', 'the_excerpt', 'excerpt_more', 'comment_text', 'save_post', 'publish_post', 'delete_post'], true)) {
                $groupScores['content'] += 25;
            } elseif (in_array($h, ['authenticate', 'wp_authenticate_user', 'check_password'], true)) {
                $groupScores['authentication'] += 45;
            } elseif (in_array($h, ['wp_login', 'wp_logout', 'user_register', 'profile_update', 'delete_user', 'set_user_role'], true)) {
                $groupScores['user'] += 35;
            } elseif (in_array($h, ['rest_api_init', 'rest_pre_dispatch'], true)) {
                $groupScores['webservices'] += 30;
            } elseif (in_array($h, ['cron_schedules'], true)) {
                $groupScores['task'] += 25;
            } elseif (in_array($h, ['wp_head', 'wp_footer', 'template_redirect', 'wp_enqueue_scripts', 'admin_enqueue_scripts', 'init', 'plugins_loaded', 'send_headers'], true)
                || str_starts_with($h, 'wp_ajax_')
            ) {
                $groupScores['system'] += 15;
            }
        }

        arsort($groupScores);

        return (string) array_key_first($groupScores);
    }
}
