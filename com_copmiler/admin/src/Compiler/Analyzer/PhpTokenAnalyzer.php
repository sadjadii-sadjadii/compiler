<?php

declare(strict_types=1);

namespace Sadjadii\Component\Copmiler\Administrator\Compiler\Analyzer;

use Sadjadii\Component\Copmiler\Administrator\Compiler\Model\AnalysisResult;
use Sadjadii\Component\Copmiler\Administrator\Compiler\Model\PluginMetadata;

/**
 * Token-based static analyzer for WordPress plugins using PHP's native lexer (token_get_all).
 * Accurately detects hooks, shortcodes, widgets, admin menus, CPTs, taxonomies, REST routes,
 * AJAX handlers, DB schemas, options, enqueued assets, WP API surface, and PHP 8.3+ issues.
 */
final class PhpTokenAnalyzer
{
    /** @var list<string> */
    private const TRACKED_WP_APIS = [
        'add_action', 'add_filter', 'do_action', 'apply_filters',
        'add_shortcode', 'do_shortcode',
        'add_menu_page', 'add_submenu_page', 'add_options_page', 'add_management_page', 'add_theme_page', 'add_plugins_page',
        'register_setting', 'add_settings_section', 'add_settings_field', 'settings_fields', 'do_settings_sections',
        'register_post_type', 'register_taxonomy', 'add_meta_box', 'get_post_meta', 'update_post_meta',
        'register_widget', 'register_sidebar', 'register_block_type',
        'register_rest_route',
        'wp_enqueue_script', 'wp_enqueue_style', 'wp_register_script', 'wp_register_style', 'wp_localize_script',
        'get_option', 'update_option', 'add_option', 'delete_option',
        'get_transient', 'set_transient', 'delete_transient',
        'wp_mail', 'wp_remote_get', 'wp_remote_post', 'wp_remote_request',
        'wp_create_nonce', 'wp_verify_nonce', 'check_admin_referer', 'check_ajax_referer', 'wp_nonce_field',
        'current_user_can', 'wp_get_current_user', 'get_current_user_id', 'is_user_logged_in',
        'wp_send_json', 'wp_send_json_success', 'wp_send_json_error', 'wp_die', 'wp_redirect', 'wp_safe_redirect',
        'register_activation_hook', 'register_deactivation_hook', 'register_uninstall_hook',
        'wp_schedule_event', 'wp_clear_scheduled_hook', 'wp_next_scheduled',
        'dbDelta', 'wp_insert_post', 'wp_update_post', 'wp_delete_post', 'get_posts',
        'load_plugin_textdomain', 'plugin_dir_path', 'plugin_dir_url', 'plugins_url',
    ];

    /** @var list<string> APIs that need extra attention in Joomla 6 */
    private const COMPLEX_WP_APIS = [
        'register_block_type',
        'add_rewrite_rule',
        'flush_rewrite_rules',
        'wp_insert_attachment',
        'media_handle_upload',
        'switch_to_blog',
        'is_multisite',
        'WP_List_Table',
        'WC_Payment_Gateway',
        'woocommerce',
    ];

    public function analyze(string $pluginRoot, PluginMetadata $metadata): AnalysisResult
    {
        $phpFiles   = [];
        $assetFiles = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($pluginRoot, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }

            $rel = ltrim(str_replace('\\', '/', substr($fileInfo->getPathname(), strlen($pluginRoot))), '/');

            // Skip vendor/node_modules/.git directories from static analysis noise
            if (preg_match('#(^|/)(node_modules|\.git|tests)(/|$)#i', $rel) === 1) {
                continue;
            }

            $ext = strtolower($fileInfo->getExtension());
            if ($ext === 'php') {
                $phpFiles[] = $rel;
            } elseif (in_array($ext, ['css', 'js', 'mjs', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'woff', 'woff2', 'ttf', 'eot', 'json'], true)) {
                $assetFiles[] = $rel;
            }
        }

        sort($phpFiles);
        sort($assetFiles);

        $actions        = [];
        $filters        = [];
        $shortcodes     = [];
        $widgets        = [];
        $adminMenus     = [];
        $postTypes      = [];
        $taxonomies     = [];
        $restRoutes     = [];
        $ajaxActions    = [];
        $cronEvents     = [];
        $dbTables       = [];
        $optionsUsed    = [];
        $enqueuedAssets = [];
        $wpApisUsed     = [];
        $warnings       = [];
        $usesWpdb       = false;
        $usesSettings   = false;
        $usesGutenberg  = false;

        $trackedLookup = array_flip(self::TRACKED_WP_APIS);

        foreach ($phpFiles as $relPath) {
            $fullPath = $pluginRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath);
            $source   = @file_get_contents($fullPath);

            if ($source === false) {
                continue;
            }

            // Detect $wpdb usage
            if (str_contains($source, '$wpdb')) {
                $usesWpdb = true;
            }

            // Detect CREATE TABLE statements in SQL strings
            if (preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[^;]+;/is', $source, $sqlMatches)) {
                foreach ($sqlMatches[0] as $sqlStmt) {
                    $dbTables[] = trim($sqlStmt);
                }
            }

            // Detect PHP 8.3/8.4 compatibility hazards
            $this->scanPhp8Compatibility($source, $relPath, $warnings);

            // Detect complex/ecosystem-specific dependencies
            foreach (self::COMPLEX_WP_APIS as $complexApi) {
                if (stripos($source, $complexApi) !== false) {
                    if ($complexApi === 'register_block_type') {
                        $usesGutenberg = true;
                    }
                    $warnings[] = [
                        'severity' => 'warning',
                        'file'     => $relPath,
                        'code'     => 'COMPLEX_WP_API',
                        'message'  => sprintf('Uses WordPress-specific API "%s" which may require manual adaptation in Joomla 6.', $complexApi),
                    ];
                }
            }

            // Tokenize PHP file cleanly
            $tokens = @token_get_all($source);
            if ($tokens === []) {
                continue;
            }

            $tokenCount = count($tokens);

            for ($i = 0; $i < $tokenCount; $i++) {
                $tok = $tokens[$i];

                // 1) Detect classes extending WP_Widget
                if (is_array($tok) && $tok[0] === T_CLASS) {
                    $classInfo = $this->parseClassDeclaration($tokens, $i);
                    if ($classInfo !== null && strcasecmp(ltrim($classInfo['extends'], '\\'), 'WP_Widget') === 0) {
                        $widgets[] = [
                            'class'  => $classInfo['name'],
                            'file'   => $relPath,
                            'fields' => $this->extractWidgetFormFields($source),
                        ];
                    }
                    continue;
                }

                // 2) Detect function calls
                if (!is_array($tok) || $tok[0] !== T_STRING) {
                    continue;
                }

                $funcName = $tok[1];
                $prevSig  = $this->prevSignificantToken($tokens, $i);

                // Skip method calls ($obj->foo()), static calls (Foo::bar()), function declarations (function foo())
                if ($prevSig !== null && is_array($prevSig) && in_array($prevSig[0], [T_FUNCTION, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW, T_CLASS], true)) {
                    continue;
                }

                $openParenIdx = $this->nextSignificantIndex($tokens, $i);
                if ($openParenIdx === null || $tokens[$openParenIdx] !== '(') {
                    continue;
                }

                if (isset($trackedLookup[$funcName])) {
                    $wpApisUsed[$funcName] = true;
                }

                $args = $this->extractCallArguments($tokens, $openParenIdx);
                $line = $tok[2];

                switch ($funcName) {
                    case 'add_action':
                        $hook = $this->unquoteString($args[0] ?? '');
                        if ($hook !== '') {
                            $actions[] = [
                                'hook'     => $hook,
                                'callback' => trim($args[1] ?? ''),
                                'priority' => isset($args[2]) ? (int) trim($args[2]) : 10,
                                'file'     => $relPath,
                                'line'     => $line,
                            ];
                            if (str_starts_with($hook, 'wp_ajax_')) {
                                $ajaxActions[] = $hook;
                            }
                        }
                        break;

                    case 'add_filter':
                        $hook = $this->unquoteString($args[0] ?? '');
                        if ($hook !== '') {
                            $filters[] = [
                                'hook'     => $hook,
                                'callback' => trim($args[1] ?? ''),
                                'priority' => isset($args[2]) ? (int) trim($args[2]) : 10,
                                'file'     => $relPath,
                                'line'     => $line,
                            ];
                        }
                        break;

                    case 'add_shortcode':
                        $tag = $this->unquoteString($args[0] ?? '');
                        if ($tag !== '') {
                            $shortcodes[] = [
                                'tag'      => $tag,
                                'callback' => trim($args[1] ?? ''),
                                'file'     => $relPath,
                                'line'     => $line,
                            ];
                        }
                        break;

                    case 'add_menu_page':
                    case 'add_submenu_page':
                    case 'add_options_page':
                    case 'add_management_page':
                    case 'add_theme_page':
                    case 'add_plugins_page':
                        $adminMenus[] = [
                            'function'   => $funcName,
                            'pageTitle'  => $this->unquoteString($args[$funcName === 'add_submenu_page' ? 1 : 0] ?? ''),
                            'menuTitle'  => $this->unquoteString($args[$funcName === 'add_submenu_page' ? 2 : 1] ?? ''),
                            'capability' => $this->unquoteString($args[$funcName === 'add_submenu_page' ? 3 : 2] ?? ''),
                            'menuSlug'   => $this->unquoteString($args[$funcName === 'add_submenu_page' ? 4 : 3] ?? ''),
                            'callback'   => trim($args[$funcName === 'add_submenu_page' ? 5 : 4] ?? ''),
                            'file'       => $relPath,
                            'line'       => $line,
                        ];
                        break;

                    case 'register_setting':
                    case 'add_settings_section':
                    case 'add_settings_field':
                        $usesSettings = true;
                        if ($funcName === 'register_setting') {
                            $optName = $this->unquoteString($args[1] ?? '');
                            if ($optName !== '') {
                                $optionsUsed[$optName] = true;
                            }
                        }
                        break;

                    case 'register_post_type':
                        $cpt = $this->unquoteString($args[0] ?? '');
                        if ($cpt !== '') {
                            $postTypes[] = ['slug' => $cpt, 'file' => $relPath, 'line' => $line];
                        }
                        break;

                    case 'register_taxonomy':
                        $tax = $this->unquoteString($args[0] ?? '');
                        if ($tax !== '') {
                            $taxonomies[] = ['slug' => $tax, 'file' => $relPath, 'line' => $line];
                        }
                        break;

                    case 'register_widget':
                        $widgetClass = $this->unquoteString($args[0] ?? '');
                        if ($widgetClass !== '') {
                            $exists = false;
                            foreach ($widgets as $w) {
                                if (strcasecmp($w['class'], $widgetClass) === 0) {
                                    $exists = true;
                                    break;
                                }
                            }
                            if (!$exists) {
                                $widgets[] = [
                                    'class'  => $widgetClass,
                                    'file'   => $relPath,
                                    'fields' => [],
                                ];
                            }
                        }
                        break;

                    case 'register_rest_route':
                        $ns    = $this->unquoteString($args[0] ?? '');
                        $route = $this->unquoteString($args[1] ?? '');
                        if ($ns !== '' || $route !== '') {
                            $restRoutes[] = [
                                'namespace' => $ns,
                                'route'     => $route,
                                'file'      => $relPath,
                                'line'      => $line,
                            ];
                        }
                        break;

                    case 'wp_schedule_event':
                        $hook = $this->unquoteString($args[2] ?? '');
                        $cronEvents[] = [
                            'recurrence' => $this->unquoteString($args[1] ?? ''),
                            'hook'       => $hook,
                            'file'       => $relPath,
                            'line'       => $line,
                        ];
                        break;

                    case 'get_option':
                    case 'update_option':
                    case 'add_option':
                    case 'delete_option':
                        $optKey = $this->unquoteString($args[0] ?? '');
                        if ($optKey !== '') {
                            $optionsUsed[$optKey] = true;
                        }
                        break;

                    case 'wp_enqueue_script':
                    case 'wp_enqueue_style':
                    case 'wp_register_script':
                    case 'wp_register_style':
                        $handle = $this->unquoteString($args[0] ?? '');
                        if ($handle !== '') {
                            $enqueuedAssets[] = [
                                'type'   => str_contains($funcName, 'script') ? 'script' : 'style',
                                'handle' => $handle,
                                'src'    => trim($args[1] ?? ''),
                                'file'   => $relPath,
                                'line'   => $line,
                            ];
                        }
                        break;
                }
            }
        }

        // Deduplicate warnings by code+file+message
        $uniqueWarnings = [];
        $seenWarn = [];
        foreach ($warnings as $w) {
            $k = $w['file'] . '|' . $w['code'] . '|' . $w['message'];
            if (!isset($seenWarn[$k])) {
                $seenWarn[$k] = true;
                $uniqueWarnings[] = $w;
            }
        }

        $score = $this->computeCompatibilityScore(
            count($phpFiles),
            $usesGutenberg,
            $uniqueWarnings,
            array_keys($wpApisUsed)
        );

        return new AnalysisResult(
            metadata: $metadata,
            phpFiles: $phpFiles,
            assetFiles: $assetFiles,
            actions: $actions,
            filters: $filters,
            shortcodes: $shortcodes,
            widgets: $widgets,
            adminMenus: $adminMenus,
            postTypes: $postTypes,
            taxonomies: $taxonomies,
            restRoutes: $restRoutes,
            ajaxActions: array_values(array_unique($ajaxActions)),
            cronEvents: $cronEvents,
            dbTables: array_values(array_unique($dbTables)),
            optionsUsed: array_values(array_keys($optionsUsed)),
            enqueuedAssets: $enqueuedAssets,
            wpApisUsed: array_values(array_keys($wpApisUsed)),
            usesWpdb: $usesWpdb,
            usesSettingsApi: $usesSettings,
            usesGutenberg: $usesGutenberg,
            warnings: $uniqueWarnings,
            compatibilityScore: $score,
        );
    }

    /**
     * @param array<int, mixed> $tokens
     * @return array{name: string, extends: string}|null
     */
    private function parseClassDeclaration(array $tokens, int $classIndex): ?array
    {
        $nameIdx = $this->nextSignificantIndex($tokens, $classIndex);
        if ($nameIdx === null || !is_array($tokens[$nameIdx]) || $tokens[$nameIdx][0] !== T_STRING) {
            return null;
        }

        $className = $tokens[$nameIdx][1];
        $extends   = '';
        $count     = count($tokens);

        for ($j = $nameIdx + 1; $j < $count; $j++) {
            $t = $tokens[$j];
            if ($t === '{') {
                break;
            }
            if (is_array($t) && $t[0] === T_EXTENDS) {
                $k = $this->nextSignificantIndex($tokens, $j);
                while ($k !== null && is_array($tokens[$k]) && in_array($tokens[$k][0], [T_STRING, T_NS_SEPARATOR, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    $extends .= $tokens[$k][1];
                    $k++;
                }
                break;
            }
        }

        return ['name' => $className, 'extends' => $extends];
    }

    /**
     * Extract form field keys used inside a WP_Widget::form() method via $this->get_field_name('...').
     *
     * @return list<string>
     */
    private function extractWidgetFormFields(string $source): array
    {
        $fields = [];
        if (preg_match_all('/get_field_name\s*\(\s*[\'"]([a-zA-Z0-9_\-]+)[\'"]\s*\)/', $source, $matches)) {
            $fields = array_values(array_unique($matches[1]));
        }

        return $fields;
    }

    /**
     * @param array<int, mixed> $tokens
     * @return list<string>
     */
    private function extractCallArguments(array $tokens, int $openParenIdx): array
    {
        $args    = [];
        $current = '';
        $depth   = 0;
        $count   = count($tokens);

        for ($i = $openParenIdx + 1; $i < $count; $i++) {
            $tok = $tokens[$i];
            $text = is_array($tok) ? $tok[1] : $tok;

            if ($text === '(' || $text === '[' || $text === '{') {
                $depth++;
                $current .= $text;
                continue;
            }

            if ($text === ')' || $text === ']' || $text === '}') {
                if ($depth === 0 && $text === ')') {
                    if (trim($current) !== '') {
                        $args[] = trim($current);
                    }
                    break;
                }
                $depth--;
                $current .= $text;
                continue;
            }

            if ($text === ',' && $depth === 0) {
                $args[] = trim($current);
                $current = '';
                continue;
            }

            $current .= $text;
        }

        return $args;
    }

    private function unquoteString(string $expr): string
    {
        $expr = trim($expr);

        // Handle __('Text', 'domain') or esc_html__('Text', 'domain')
        if (preg_match('/^(?:__|_e|esc_html__|esc_attr__|esc_html_e)\s*\(\s*([\'"])(.*?)\1/s', $expr, $m) === 1) {
            return stripslashes($m[2]);
        }

        if (strlen($expr) >= 2 && (($expr[0] === "'" && $expr[-1] === "'") || ($expr[0] === '"' && $expr[-1] === '"'))) {
            return stripslashes(substr($expr, 1, -1));
        }

        return '';
    }

    /**
     * @param array<int, mixed> $tokens
     */
    private function nextSignificantIndex(array $tokens, int $from): ?int
    {
        $count = count($tokens);
        for ($i = $from + 1; $i < $count; $i++) {
            if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $i;
        }

        return null;
    }

    /**
     * @param array<int, mixed> $tokens
     * @return mixed
     */
    private function prevSignificantToken(array $tokens, int $from): mixed
    {
        for ($i = $from - 1; $i >= 0; $i--) {
            if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $tokens[$i];
        }

        return null;
    }

    /**
     * @param list<array<string, string>> $warnings
     */
    private function scanPhp8Compatibility(string $source, string $relPath, array &$warnings): void
    {
        if (preg_match('/\bmysql_(?:connect|query|fetch_assoc|real_escape_string)\s*\(/i', $source) === 1) {
            $warnings[] = [
                'severity' => 'error',
                'file'     => $relPath,
                'code'     => 'PHP8_REMOVED_MYSQL',
                'message'  => 'Uses removed ext/mysql functions which do not exist in PHP 8.3+ (Joomla 6).',
            ];
        }

        if (preg_match('/\bcreate_function\s*\(/i', $source) === 1) {
            $warnings[] = [
                'severity' => 'error',
                'file'     => $relPath,
                'code'     => 'PHP8_REMOVED_CREATE_FUNCTION',
                'message'  => 'Uses removed create_function(). Must be replaced with an anonymous closure for PHP 8.3+.',
            ];
        }

        if (preg_match('/\beach\s*\(\s*\$/i', $source) === 1) {
            $warnings[] = [
                'severity' => 'warning',
                'file'     => $relPath,
                'code'     => 'PHP8_REMOVED_EACH',
                'message'  => 'Possible usage of removed each() function. Verify compatibility with PHP 8.3+.',
            ];
        }
    }

    /**
     * @param list<array<string, string>> $warnings
     * @param list<string>                $apis
     */
    private function computeCompatibilityScore(int $phpCount, bool $usesGutenberg, array $warnings, array $apis): int
    {
        $score = 96;

        if ($usesGutenberg) {
            $score -= 18;
        }

        foreach ($warnings as $w) {
            $score -= ($w['severity'] === 'error') ? 15 : 5;
        }

        if ($phpCount > 80) {
            $score -= 10;
        } elseif ($phpCount > 30) {
            $score -= 5;
        }

        if (count($apis) > 0) {
            $score = min(98, $score + 2);
        }

        return max(15, min(100, $score));
    }
}
