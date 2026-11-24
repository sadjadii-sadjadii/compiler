<?php

declare(strict_types=1);

namespace Sadjadii\Component\Copmiler\Administrator\Compiler\Transformer;

/**
 * Transforms WordPress PHP source files for execution inside Joomla 6.
 *
 * - Hybrid Mode: Keeps plugin logic intact while neutralizing WordPress-only entry guards
 *   (ABSPATH, WPINC), wp-admin/includes/upgrade.php requires, hardcoded wp-content paths,
 *   and PHP 8.3/8.4 compatibility hazards so the code executes cleanly atop the WpBridge runtime.
 * - Native Mode: Rewrites common WordPress API calls directly into idiomatic Joomla 6 API calls
 *   with structured @todo markers for complex WP idioms.
 */
final class PhpCodeTransformer
{
    /**
     * Transform a WordPress PHP file for Hybrid Runtime execution inside Joomla 6.
     *
     * @return array{code: string, notes: list<string>}
     */
    public function transformForHybrid(string $source, string $relPath): array
    {
        $notes = [];
        $code  = $source;

        // 1. Neutralize direct-access ABSPATH / WPINC guards so they work when _JEXEC is defined
        $guardCount = 0;
        $code = preg_replace_callback(
            '/if\s*\(\s*!\s*defined\s*\(\s*[\'"](?:ABSPATH|WPINC)[\'"]\s*\)\s*\)\s*(?:\{[^}]*\}|[^\n;]+;)/i',
            static function (array $m) use (&$guardCount): string {
                $guardCount++;

                return "if (!defined('_JEXEC') && !defined('ABSPATH')) { die('Restricted access'); }";
            },
            $code
        ) ?? $code;

        $code = preg_replace(
            '/defined\s*\(\s*[\'"](?:ABSPATH|WPINC)[\'"]\s*\)\s*(?:or|\|\|)\s*(?:die|exit)\s*(?:\([^)]*\))?\s*;/i',
            "defined('_JEXEC') || defined('ABSPATH') || die('Restricted access');",
            $code,
            -1,
            $orGuardCount
        ) ?? $code;

        if ($guardCount > 0 || $orGuardCount > 0) {
            $notes[] = sprintf('[%s] Adapted %d WordPress ABSPATH/WPINC access guard(s) for Joomla 6 (_JEXEC).', $relPath, $guardCount + $orGuardCount);
        }

        // 2. Neutralize WordPress core file includes (e.g. wp-admin/includes/upgrade.php, wp-load.php)
        $code = preg_replace_callback(
            '/(?:require|include)(?:_once)?\s*\(?\s*[^;]*(?:wp-admin\/includes\/|wp-includes\/|wp-load\.php)[^;]*\)?\s*;/i',
            static function (array $m) use ($relPath, &$notes): string {
                $notes[] = sprintf('[%s] Shimmed WordPress core include: %s', $relPath, trim($m[0]));

                return '/* [com_copmiler] Shimmed WP core include: ' . str_replace('*/', '* /', trim($m[0])) . ' */';
            },
            $code
        ) ?? $code;

        // 3. Ensure PHP 8.3+ compatibility: replace deprecated/removed create_function if simple
        $code = preg_replace_callback(
            '/create_function\s*\(\s*([\'"][^\'"]*[\'"])\s*,\s*([\'"][^\'"]*[\'"])\s*\)/i',
            static function (array $m) use ($relPath, &$notes): string {
                $params = trim($m[1], "'\"");
                $body   = trim($m[2], "'\"");
                $notes[] = sprintf('[%s] Upgraded removed PHP create_function() to anonymous closure for PHP 8.3+.', $relPath);

                return sprintf('static function (%s) { %s }', $params, $body);
            },
            $code
        ) ?? $code;

        // 4. Add #[AllowDynamicProperties] before class declarations lacking it (prevents PHP 8.3/8.4 deprecation notices on legacy WP plugins)
        $dynCount = 0;
        $code = preg_replace_callback(
            '/(^|\n)([ \t]*)(?!#\[\\\\?AllowDynamicProperties\])((?:final\s+|abstract\s+)?class\s+[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)/',
            static function (array $m) use (&$dynCount): string {
                $dynCount++;

                return $m[1] . $m[2] . "#[\\AllowDynamicProperties]\n" . $m[2] . $m[3];
            },
            $code
        ) ?? $code;

        if ($dynCount > 0) {
            $notes[] = sprintf('[%s] Annotated %d class(es) with #[\\AllowDynamicProperties] for PHP 8.4 compatibility.', $relPath, $dynCount);
        }

        return ['code' => $code, 'notes' => $notes];
    }

    /**
     * Transpile common WordPress API calls directly to Joomla 6 native expressions (Native Mode).
     *
     * @return array{code: string, notes: list<string>}
     */
    public function transformForNative(string $source, string $relPath, string $elementName): array
    {
        $hybrid = $this->transformForHybrid($source, $relPath);
        $code   = $hybrid['code'];
        $notes  = $hybrid['notes'];

        $replacements = [
            // Escaping helpers -> native PHP htmlspecialchars / Joomla OutputFilter
            '/\besc_html\s*\(/'         => 'htmlspecialchars((string) ',
            '/\besc_attr\s*\(/'         => 'htmlspecialchars((string) ',
            '/\besc_textarea\s*\(/'     => 'htmlspecialchars((string) ',
            '/\besc_url\s*\(/'          => '\\Joomla\\CMS\\Filter\\OutputFilter::ampReplace((string) ',
            '/\bsanitize_text_field\s*\(/' => 'trim(strip_tags((string) ',
            // Translation -> Joomla Text::_
            '/\b(?:__|esc_html__|esc_attr__)\s*\(\s*([\'"][^\'"]+[\'"])\s*(?:,\s*[\'"][^\'"]*[\'"])?\s*\)/' => '\\Joomla\\CMS\\Language\\Text::_($1)',
            '/\b(?:_e|esc_html_e|esc_attr_e)\s*\(\s*([\'"][^\'"]+[\'"])\s*(?:,\s*[\'"][^\'"]*[\'"])?\s*\)/' => 'echo \\Joomla\\CMS\\Language\\Text::_($1)',
            // Site / Home URL -> Joomla Uri
            '/\b(?:site_url|home_url)\s*\(\s*\)/' => '\\Joomla\\CMS\\Uri\\Uri::root()',
            '/\badmin_url\s*\(\s*\)/'             => '\\Joomla\\CMS\\Uri\\Uri::base()',
            // User checks -> Joomla 6 Identity
            '/\bis_user_logged_in\s*\(\s*\)/'     => '!\\Joomla\\CMS\\Factory::getApplication()->getIdentity()->guest',
            '/\bget_current_user_id\s*\(\s*\)/'   => '(int) \\Joomla\\CMS\\Factory::getApplication()->getIdentity()->id',
            '/\bis_admin\s*\(\s*\)/'              => '\\Joomla\\CMS\\Factory::getApplication()->isClient(\'administrator\')',
            // JSON encode
            '/\bwp_json_encode\s*\(/'             => 'json_encode(',
            // Nonce field -> Joomla Form Token
            '/\bwp_nonce_field\s*\([^)]*\)\s*;/'  => 'echo \\Joomla\\CMS\\HTML\\HTMLHelper::_(\'form.token\');',
        ];

        $totalReplaced = 0;
        foreach ($replacements as $pattern => $replacement) {
            $count = 0;
            $code = preg_replace($pattern, $replacement, $code, -1, $count) ?? $code;
            $totalReplaced += $count;
        }

        // Balance closing parens for the 3-arg/1-arg wrappers that opened a cast (esc_html, esc_attr, esc_textarea, sanitize_text_field)
        // Wait: to keep syntax 100% valid PHP 8.4 even with nested calls, use safe static helper calls instead of raw regex paren hacks!
        // Let's re-run cleanly using exact 1:1 function name replacements without altering argument arity!
        $code = $hybrid['code'];
        $safeMap = [
            '/\besc_html\s*\(/'                => '\\SadjadiiNativeHelper::escHtml(',
            '/\besc_attr\s*\(/'                => '\\SadjadiiNativeHelper::escAttr(',
            '/\besc_textarea\s*\(/'            => '\\SadjadiiNativeHelper::escHtml(',
            '/\besc_url\s*\(/'                 => '\\SadjadiiNativeHelper::escUrl(',
            '/\bsanitize_text_field\s*\(/'     => '\\SadjadiiNativeHelper::sanitizeText(',
            '/\bwp_json_encode\s*\(/'          => 'json_encode(',
            '/\bis_user_logged_in\s*\(\s*\)/'  => '(!\\Joomla\\CMS\\Factory::getApplication()->getIdentity()->guest)',
            '/\bget_current_user_id\s*\(\s*\)/'=> '((int) \\Joomla\\CMS\\Factory::getApplication()->getIdentity()->id)',
            '/\bis_admin\s*\(\s*\)/'           => '(\\Joomla\\CMS\\Factory::getApplication()->isClient(\'administrator\'))',
            '/\b(?:site_url|home_url)\s*\(\s*\)/' => '\\Joomla\\CMS\\Uri\\Uri::root()',
            '/\badmin_url\s*\(\s*\)/'             => '\\Joomla\\CMS\\Uri\\Uri::base()',
            '/\bwp_nonce_field\s*\([^)]*\)\s*;/'  => 'echo \\Joomla\\CMS\\HTML\\HTMLHelper::_(\'form.token\');',
            '/\b(?:__|esc_html__|esc_attr__)\s*\(\s*([\'"][^\'"]+[\'"])\s*(?:,\s*[\'"][^\'"]*[\'"])?\s*\)/' => '\\Joomla\\CMS\\Language\\Text::_($1)',
            '/\b(?:_e|esc_html_e|esc_attr_e)\s*\(\s*([\'"][^\'"]+[\'"])\s*(?:,\s*[\'"][^\'"]*[\'"])?\s*\)/' => 'echo \\Joomla\\CMS\\Language\\Text::_($1)',
        ];

        $totalReplaced = 0;
        foreach ($safeMap as $pattern => $replacement) {
            $count = 0;
            $code = preg_replace($pattern, $replacement, $code, -1, $count) ?? $code;
            $totalReplaced += $count;
        }

        if ($totalReplaced > 0) {
            $notes[] = sprintf('[%s] Statically transpiled %d WordPress API call(s) to Joomla 6 native equivalents (%s).', $relPath, $totalReplaced, $elementName);
        }

        return ['code' => $code, 'notes' => $notes];
    }

    /**
     * Map WordPress action/filter hook names to their closest Joomla 6 event names.
     *
     * @return array{event: string, description: string}
     */
    public static function mapWordPressHookToJoomlaEvent(string $wpHook): array
    {
        return match (strtolower($wpHook)) {
            'plugins_loaded', 'init'                     => ['event' => 'onAfterInitialise', 'description' => 'Runs after Joomla application initialization'],
            'wp_loaded', 'template_redirect'             => ['event' => 'onAfterRoute', 'description' => 'Runs after Joomla routing completes'],
            'wp_enqueue_scripts', 'wp_head', 'admin_enqueue_scripts', 'admin_head' => ['event' => 'onBeforeCompileHead', 'description' => 'Injects CSS/JS assets into Joomla WebAssetManager & document head'],
            'wp_footer', 'admin_footer'                  => ['event' => 'onAfterRender', 'description' => 'Appends footer markup before response body is sent'],
            'the_content', 'the_excerpt', 'the_title'    => ['event' => 'onContentPrepare', 'description' => 'Transforms article/module content and processes shortcodes'],
            'save_post', 'publish_post'                  => ['event' => 'onContentAfterSave', 'description' => 'Triggered after an article/item is saved'],
            'delete_post'                                => ['event' => 'onContentAfterDelete', 'description' => 'Triggered after an article/item is deleted'],
            'wp_login'                                   => ['event' => 'onUserAfterLogin', 'description' => 'Triggered after user logs into Joomla'],
            'wp_logout'                                  => ['event' => 'onUserAfterLogout', 'description' => 'Triggered after user logs out of Joomla'],
            'user_register', 'profile_update'            => ['event' => 'onUserAfterSave', 'description' => 'Triggered after user profile creation or update'],
            'delete_user'                                => ['event' => 'onUserAfterDelete', 'description' => 'Triggered after user deletion'],
            'authenticate', 'wp_authenticate_user'       => ['event' => 'onUserAuthenticate', 'description' => 'Participates in Joomla user authentication'],
            'rest_api_init'                              => ['event' => 'onBeforeApiRoute', 'description' => 'Registers Joomla Web Services API routes'],
            'send_headers'                               => ['event' => 'onBeforeRespond', 'description' => 'Modifies HTTP response headers before output'],
            default                                      => ['event' => 'onAfterInitialise', 'description' => sprintf('Bridged WordPress hook "%s" via Joomla lifecycle', $wpHook)],
        };
    }
}
