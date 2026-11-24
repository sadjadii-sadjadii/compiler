<?php

declare(strict_types=1);

namespace Sadjadii\Component\Copmiler\Administrator\Compiler\Runtime;

/**
 * Generates the self-contained Joomla 6 WordPress Runtime Compatibility Bridge
 * bundled into Hybrid-compiled extensions (components, plugins, modules).
 *
 * Uses strictly modern Joomla 6 APIs:
 *  - Joomla\Database\DatabaseInterface
 *  - Joomla\CMS\WebAsset\WebAssetManager
 *  - Joomla\Http\HttpFactory (NOT deprecated Joomla\CMS\Http)
 *  - Joomla\CMS\Mail\MailerFactoryInterface
 *  - Joomla\CMS\Session\Session
 *  - Joomla\CMS\Uri\Uri
 *  - Joomla\CMS\Filter\OutputFilter
 *
 * All global functions/classes are guarded with function_exists / class_exists so
 * multiple compiled WordPress extensions coexist safely on the same Joomla 6 site.
 */
final class WpBridgeTemplate
{
    public function renderBridgeBootstrap(string $namespace, string $slug, string $elementName, string $extensionType): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Runtime;

defined('_JEXEC') or die;

use Joomla\\CMS\\Factory;
use Joomla\\CMS\\Uri\\Uri;
use Joomla\\Database\\DatabaseInterface;

/**
 * Per-extension bootstrapper connecting this compiled WordPress plugin
 * to the shared Joomla 6 WordPress Compatibility Runtime.
 */
final class WpBridge
{
    private static bool \$booted = false;
    private static string \$pluginDir = '';
    private static string \$pluginUrl = '';
    private static string \$mainFile = '';

    public static function boot(
        string \$pluginDir,
        string \$pluginUrl,
        string \$mainFile,
        ?DatabaseInterface \$db = null
    ): void {
        self::\$pluginDir = rtrim(\$pluginDir, '/\\\\') . DIRECTORY_SEPARATOR;
        self::\$pluginUrl = rtrim(\$pluginUrl, '/') . '/';
        self::\$mainFile  = \$mainFile;

        if (!defined('ABSPATH')) {
            define('ABSPATH', defined('JPATH_ROOT') ? rtrim(JPATH_ROOT, '/\\\\') . DIRECTORY_SEPARATOR : self::\$pluginDir);
        }
        if (!defined('WPINC')) {
            define('WPINC', 'wp-includes');
        }
        if (!defined('WP_CONTENT_DIR')) {
            define('WP_CONTENT_DIR', defined('JPATH_ROOT') ? JPATH_ROOT . '/media' : self::\$pluginDir);
        }
        if (!defined('WP_PLUGIN_DIR')) {
            define('WP_PLUGIN_DIR', dirname(rtrim(self::\$pluginDir, '/\\\\')));
        }
        if (!defined('WP_CONTENT_URL')) {
            define('WP_CONTENT_URL', class_exists(Uri::class) ? rtrim(Uri::root(), '/') . '/media' : '');
        }
        if (!defined('WP_PLUGIN_URL')) {
            define('WP_PLUGIN_URL', rtrim(dirname(rtrim(self::\$pluginUrl, '/')), '/'));
        }
        if (!defined('DAY_IN_SECONDS')) {
            define('MINUTE_IN_SECONDS', 60);
            define('HOUR_IN_SECONDS', 3600);
            define('DAY_IN_SECONDS', 86400);
            define('WEEK_IN_SECONDS', 604800);
            define('MONTH_IN_SECONDS', 2592000);
            define('YEAR_IN_SECONDS', 31536000);
        }

        require_once __DIR__ . '/WpdbAdapter.php';
        require_once __DIR__ . '/WpCompatRegistry.php';
        require_once __DIR__ . '/wp_functions.php';

        if (\$db === null && class_exists(Factory::class)) {
            try {
                \$db = Factory::getContainer()->get(DatabaseInterface::class);
            } catch (\\Throwable) {
                \$db = null;
            }
        }

        \\JoomlaWpCompatRegistry::init(\$db, '#__{$slug}_wp_options', self::\$pluginDir, self::\$pluginUrl, '{$elementName}');

        if (!self::\$booted) {
            self::\$booted = true;
            \$entry = self::\$pluginDir . ltrim(str_replace('/', DIRECTORY_SEPARATOR, self::\$mainFile), DIRECTORY_SEPARATOR);
            if (is_file(\$entry)) {
                require_once \$entry;
            }
            \\JoomlaWpCompatRegistry::doAction('plugins_loaded');
            \\JoomlaWpCompatRegistry::doAction('init');
            if (\\JoomlaWpCompatRegistry::isAdmin()) {
                \\JoomlaWpCompatRegistry::doAction('admin_init');
                \\JoomlaWpCompatRegistry::doAction('admin_menu');
            }
            \\JoomlaWpCompatRegistry::doAction('widgets_init');
        }
    }

    public static function getPluginDir(): string
    {
        return self::\$pluginDir;
    }

    public static function getPluginUrl(): string
    {
        return self::\$pluginUrl;
    }
}
PHP;
    }

    public function renderWpdbAdapter(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

defined('_JEXEC') or die;

use Joomla\Database\DatabaseInterface;

if (!class_exists('JoomlaWpdbAdapter', false)) {
    #[AllowDynamicProperties]
    class JoomlaWpdbAdapter
    {
        public string $prefix = 'jos_';
        public string $base_prefix = 'jos_';
        public string $posts = 'jos_content';
        public string $users = 'jos_users';
        public string $options = 'jos_options';
        public string $comments = 'jos_comments';
        public string $postmeta = 'jos_fields_values';
        public string $usermeta = 'jos_user_profiles';
        public int $insert_id = 0;
        public int $rows_affected = 0;
        public string $last_error = '';
        public string $last_query = '';
        public string $charset = 'utf8mb4';
        public string $collate = 'utf8mb4_unicode_ci';

        private ?DatabaseInterface $db;

        public function __construct(?DatabaseInterface $db = null, string $optionsTable = '#__wp_options')
        {
            $this->db = $db;
            if ($db !== null) {
                $this->prefix      = $db->getPrefix();
                $this->base_prefix = $this->prefix;
                $this->posts       = $this->prefix . 'content';
                $this->users       = $this->prefix . 'users';
                $this->options     = str_replace('#__', $this->prefix, $optionsTable);
            }
        }

        public function get_charset_collate(): string
        {
            return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
        }

        public function esc_like(string $text): string
        {
            return addcslashes($text, '_%\\');
        }

        public function prepare(string $query, mixed ...$args): string
        {
            if (count($args) === 1 && is_array($args[0])) {
                $args = array_values($args[0]);
            }

            $index = 0;
            $db = $this->db;

            return preg_replace_callback('/%(%|d|f|s|i)/', static function (array $m) use (&$index, $args, $db): string {
                if ($m[1] === '%') {
                    return '%';
                }
                $val = $args[$index++] ?? '';
                return match ($m[1]) {
                    'd' => (string) (int) $val,
                    'f' => (string) (float) $val,
                    'i' => $db !== null ? $db->quoteName((string) $val) : '`' . str_replace('`', '``', (string) $val) . '`',
                    default => $db !== null ? $db->quote((string) $val) : "'" . addslashes((string) $val) . "'",
                };
            }, $query) ?? $query;
        }

        public function query(string $sql): int|bool
        {
            $this->last_query = $sql;
            $this->last_error = '';
            if ($this->db === null) {
                return false;
            }
            try {
                $this->db->setQuery($sql);
                $this->db->execute();
                $this->rows_affected = (int) $this->db->getAffectedRows();
                $this->insert_id     = (int) $this->db->insertid();
                return $this->rows_affected;
            } catch (\Throwable $e) {
                $this->last_error = $e->getMessage();
                return false;
            }
        }

        public function get_results(string $sql, string $output = 'OBJECT'): array
        {
            $this->last_query = $sql;
            $this->last_error = '';
            if ($this->db === null) {
                return [];
            }
            try {
                $this->db->setQuery($sql);
                if ($output === 'ARRAY_A') {
                    return $this->db->loadAssocList() ?: [];
                }
                if ($output === 'ARRAY_N') {
                    return $this->db->loadRowList() ?: [];
                }
                return $this->db->loadObjectList() ?: [];
            } catch (\Throwable $e) {
                $this->last_error = $e->getMessage();
                return [];
            }
        }

        public function get_row(string $sql, string $output = 'OBJECT', int $y = 0): mixed
        {
            $rows = $this->get_results($sql, $output);
            return $rows[$y] ?? null;
        }

        public function get_var(string $sql): mixed
        {
            $this->last_query = $sql;
            if ($this->db === null) {
                return null;
            }
            try {
                $this->db->setQuery($sql);
                return $this->db->loadResult();
            } catch (\Throwable $e) {
                $this->last_error = $e->getMessage();
                return null;
            }
        }

        public function get_col(string $sql): array
        {
            $this->last_query = $sql;
            if ($this->db === null) {
                return [];
            }
            try {
                $this->db->setQuery($sql);
                return $this->db->loadColumn() ?: [];
            } catch (\Throwable $e) {
                $this->last_error = $e->getMessage();
                return [];
            }
        }

        public function insert(string $table, array $data, array|string|null $format = null): int|false
        {
            if ($this->db === null || $data === []) {
                return false;
            }
            try {
                $obj = (object) $data;
                $this->db->insertObject($table, $obj);
                $this->insert_id     = (int) $this->db->insertid();
                $this->rows_affected = 1;
                return 1;
            } catch (\Throwable $e) {
                $this->last_error = $e->getMessage();
                return false;
            }
        }

        public function update(string $table, array $data, array $where, array|string|null $format = null, array|string|null $whereFormat = null): int|false
        {
            if ($this->db === null || $data === [] || $where === []) {
                return false;
            }
            try {
                $query = $this->db->getQuery(true)->update($this->db->quoteName($table));
                foreach ($data as $col => $val) {
                    $query->set($this->db->quoteName((string) $col) . ' = ' . $this->db->quote((string) $val));
                }
                foreach ($where as $col => $val) {
                    $query->where($this->db->quoteName((string) $col) . ' = ' . $this->db->quote((string) $val));
                }
                $this->db->setQuery($query);
                $this->db->execute();
                $this->rows_affected = (int) $this->db->getAffectedRows();
                return $this->rows_affected;
            } catch (\Throwable $e) {
                $this->last_error = $e->getMessage();
                return false;
            }
        }

        public function delete(string $table, array $where, array|string|null $whereFormat = null): int|false
        {
            if ($this->db === null || $where === []) {
                return false;
            }
            try {
                $query = $this->db->getQuery(true)->delete($this->db->quoteName($table));
                foreach ($where as $col => $val) {
                    $query->where($this->db->quoteName((string) $col) . ' = ' . $this->db->quote((string) $val));
                }
                $this->db->setQuery($query);
                $this->db->execute();
                $this->rows_affected = (int) $this->db->getAffectedRows();
                return $this->rows_affected;
            } catch (\Throwable $e) {
                $this->last_error = $e->getMessage();
                return false;
            }
        }
    }
}
PHP;
    }

    public function renderCompatRegistry(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;

if (!class_exists('JoomlaWpCompatRegistry', false)) {
    final class JoomlaWpCompatRegistry
    {
        public static ?DatabaseInterface $db = null;
        public static string $optionsTable = '#__wp_options';
        public static string $pluginDir = '';
        public static string $pluginUrl = '';
        public static string $elementName = '';

        /** @var array<string, array<int, list<array{cb: callable, args: int}>>> */
        public static array $hooks = [];

        /** @var array<string, callable> */
        public static array $shortcodes = [];

        /** @var array<string, array<string, mixed>> */
        public static array $adminPages = [];

        /** @var array<string, array<string, mixed>> */
        public static array $settings = [];

        /** @var array<string, array<string, mixed>> */
        public static array $settingsSections = [];

        /** @var array<string, list<array<string, mixed>>> */
        public static array $settingsFields = [];

        /** @var array<string, object> */
        public static array $widgets = [];

        /** @var array<string, array<string, mixed>> */
        public static array $postTypes = [];

        /** @var array<string, array<string, mixed>> */
        public static array $restRoutes = [];

        /** @var array<string, array<string, mixed>> */
        public static array $scripts = [];

        /** @var array<string, array<string, mixed>> */
        public static array $styles = [];

        /** @var array<string, mixed> */
        private static array $optionCache = [];

        private static bool $optionsTableVerified = false;

        public static function init(
            ?DatabaseInterface $db,
            string $optionsTable,
            string $pluginDir,
            string $pluginUrl,
            string $elementName
        ): void {
            if ($db !== null) {
                self::$db = $db;
            }
            self::$optionsTable = $optionsTable;
            self::$pluginDir    = $pluginDir;
            self::$pluginUrl    = $pluginUrl;
            self::$elementName  = $elementName;

            if (!isset($GLOBALS['wpdb']) || !($GLOBALS['wpdb'] instanceof \JoomlaWpdbAdapter)) {
                $GLOBALS['wpdb'] = new \JoomlaWpdbAdapter(self::$db, self::$optionsTable);
            }
        }

        public static function isAdmin(): bool
        {
            if (!class_exists(Factory::class)) {
                return false;
            }
            try {
                return Factory::getApplication()->isClient('administrator');
            } catch (\Throwable) {
                return false;
            }
        }

        public static function addHook(string $tag, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
        {
            self::$hooks[$tag][$priority][] = ['cb' => $callback, 'args' => $acceptedArgs];
        }

        public static function removeHook(string $tag, callable $callback, int $priority = 10): bool
        {
            if (!isset(self::$hooks[$tag][$priority])) {
                return false;
            }
            foreach (self::$hooks[$tag][$priority] as $idx => $item) {
                if ($item['cb'] === $callback) {
                    unset(self::$hooks[$tag][$priority][$idx]);
                    return true;
                }
            }
            return false;
        }

        public static function doAction(string $tag, mixed ...$args): void
        {
            if (empty(self::$hooks[$tag])) {
                return;
            }
            ksort(self::$hooks[$tag]);
            foreach (self::$hooks[$tag] as $callbacks) {
                foreach ($callbacks as $item) {
                    $callArgs = array_slice($args, 0, max(0, $item['args']));
                    ($item['cb'])(...$callArgs);
                }
            }
        }

        public static function applyFilters(string $tag, mixed $value, mixed ...$args): mixed
        {
            if (empty(self::$hooks[$tag])) {
                return $value;
            }
            ksort(self::$hooks[$tag]);
            foreach (self::$hooks[$tag] as $callbacks) {
                foreach ($callbacks as $item) {
                    $all = array_merge([$value], $args);
                    $callArgs = array_slice($all, 0, max(1, $item['args']));
                    $value = ($item['cb'])(...$callArgs);
                }
            }
            return $value;
        }

        public static function doShortcode(string $content): string
        {
            if ($content === '' || self::$shortcodes === [] || !str_contains($content, '[')) {
                return $content;
            }

            $tags = implode('|', array_map(static fn (string $t): string => preg_quote($t, '/'), array_keys(self::$shortcodes)));
            $pattern = '/\[(' . $tags . ')(?![\w-])([^\]\/]*(?:\/(?!\])[^\]\/]*)*?)(?:(\/)\]|\](?:([^\[]*(?:\[(?!\/\1\])[^\[]*)*)\[\/\1\])?)/s';

            return preg_replace_callback($pattern, static function (array $m): string {
                $tag     = $m[1];
                $rawAttr = $m[2] ?? '';
                $inner   = $m[4] ?? null;
                $atts    = self::parseShortcodeAtts($rawAttr);

                $cb = self::$shortcodes[$tag] ?? null;
                if ($cb === null) {
                    return $m[0];
                }

                return (string) $cb($atts, $inner, $tag);
            }, $content) ?? $content;
        }

        /**
         * @return array<string|int, string>
         */
        public static function parseShortcodeAtts(string $text): array
        {
            $atts = [];
            $text = trim($text);
            if ($text === '') {
                return $atts;
            }

            $pattern = '/([\w-]+)\s*=\s*"([^"]*)"(?:\s|$)|([\w-]+)\s*=\s*\'([^\']*)\'(?:\s|$)|([\w-]+)\s*=\s*([^\s\'"]+)(?:\s|$)|"([^"]*)"(?:\s|$)|\'([^\']*)\'(?:\s|$)|(\S+)(?:\s|$)/';
            if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $m) {
                    if (!empty($m[1])) {
                        $atts[strtolower($m[1])] = stripcslashes($m[2]);
                    } elseif (!empty($m[3])) {
                        $atts[strtolower($m[3])] = stripcslashes($m[4]);
                    } elseif (!empty($m[5])) {
                        $atts[strtolower($m[5])] = stripcslashes($m[6]);
                    } elseif (isset($m[7]) && $m[7] !== '') {
                        $atts[] = stripcslashes($m[7]);
                    } elseif (isset($m[8]) && $m[8] !== '') {
                        $atts[] = stripcslashes($m[8]);
                    } elseif (isset($m[9]) && $m[9] !== '') {
                        $atts[] = stripcslashes($m[9]);
                    }
                }
            }

            return $atts;
        }

        private static function ensureOptionsTable(): void
        {
            if (self::$optionsTableVerified || self::$db === null) {
                return;
            }
            self::$optionsTableVerified = true;
            try {
                self::$db->setQuery(
                    'CREATE TABLE IF NOT EXISTS ' . self::$db->quoteName(self::$optionsTable) . ' ('
                    . '`option_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                    . '`option_name` VARCHAR(191) NOT NULL,'
                    . '`option_value` LONGTEXT NOT NULL,'
                    . '`autoload` VARCHAR(20) NOT NULL DEFAULT \'yes\','
                    . 'PRIMARY KEY (`option_id`),'
                    . 'UNIQUE KEY `idx_option_name` (`option_name`)'
                    . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
                );
                self::$db->execute();
            } catch (\Throwable) {
                // Ignore if DB does not permit DDL at runtime
            }
        }

        public static function getOption(string $name, mixed $default = false): mixed
        {
            if (array_key_exists($name, self::$optionCache)) {
                return self::$optionCache[$name];
            }

            if (self::$db === null) {
                return $default;
            }

            self::ensureOptionsTable();
            try {
                $query = self::$db->getQuery(true)
                    ->select(self::$db->quoteName('option_value'))
                    ->from(self::$db->quoteName(self::$optionsTable))
                    ->where(self::$db->quoteName('option_name') . ' = ' . self::$db->quote($name));
                self::$db->setQuery($query);
                $raw = self::$db->loadResult();
                if ($raw === null) {
                    return $default;
                }
                $decoded = self::maybeUnserialize((string) $raw);
                self::$optionCache[$name] = $decoded;
                return $decoded;
            } catch (\Throwable) {
                return $default;
            }
        }

        public static function updateOption(string $name, mixed $value): bool
        {
            self::$optionCache[$name] = $value;
            if (self::$db === null) {
                return true;
            }

            self::ensureOptionsTable();
            $serialized = is_scalar($value) || $value === null ? (string) $value : serialize($value);
            try {
                $query = self::$db->getQuery(true)
                    ->select('COUNT(*)')
                    ->from(self::$db->quoteName(self::$optionsTable))
                    ->where(self::$db->quoteName('option_name') . ' = ' . self::$db->quote($name));
                self::$db->setQuery($query);
                $exists = (int) self::$db->loadResult() > 0;

                if ($exists) {
                    $q = self::$db->getQuery(true)
                        ->update(self::$db->quoteName(self::$optionsTable))
                        ->set(self::$db->quoteName('option_value') . ' = ' . self::$db->quote($serialized))
                        ->where(self::$db->quoteName('option_name') . ' = ' . self::$db->quote($name));
                } else {
                    $q = self::$db->getQuery(true)
                        ->insert(self::$db->quoteName(self::$optionsTable))
                        ->columns([self::$db->quoteName('option_name'), self::$db->quoteName('option_value')])
                        ->values(self::$db->quote($name) . ', ' . self::$db->quote($serialized));
                }

                self::$db->setQuery($q);
                self::$db->execute();
                return true;
            } catch (\Throwable) {
                return false;
            }
        }

        public static function deleteOption(string $name): bool
        {
            unset(self::$optionCache[$name]);
            if (self::$db === null) {
                return true;
            }
            self::ensureOptionsTable();
            try {
                $q = self::$db->getQuery(true)
                    ->delete(self::$db->quoteName(self::$optionsTable))
                    ->where(self::$db->quoteName('option_name') . ' = ' . self::$db->quote($name));
                self::$db->setQuery($q);
                self::$db->execute();
                return true;
            } catch (\Throwable) {
                return false;
            }
        }

        public static function flushAssetsToJoomla(): void
        {
            if (!class_exists(Factory::class)) {
                return;
            }
            try {
                $app = Factory::getApplication();
                $doc = $app->getDocument();
                if (!method_exists($doc, 'getWebAssetManager')) {
                    return;
                }
                $wa = $doc->getWebAssetManager();

                foreach (self::$styles as $handle => $style) {
                    if (!empty($style['enqueued']) && !empty($style['src'])) {
                        $wa->registerAndUseStyle('wp-' . $handle, (string) $style['src']);
                    }
                }
                foreach (self::$scripts as $handle => $script) {
                    if (!empty($script['enqueued'])) {
                        if (!empty($script['localized'])) {
                            foreach ($script['localized'] as $objName => $data) {
                                $wa->addInlineScript('window.' . preg_replace('/[^a-zA-Z0-9_$]/', '', $objName) . ' = ' . json_encode($data, JSON_UNESCAPED_UNICODE) . ';');
                            }
                        }
                        if (!empty($script['src'])) {
                            $wa->registerAndUseScript('wp-' . $handle, (string) $script['src'], [], ['defer' => !empty($script['in_footer'])]);
                        }
                    }
                }
            } catch (\Throwable) {
                // Non-HTML document or CLI context
            }
        }

        public static function maybeUnserialize(string $data): mixed
        {
            $trimmed = trim($data);
            if ($trimmed === 'b:0;') {
                return false;
            }
            if (preg_match('/^[aOsibd]:\d+:/', $trimmed) === 1) {
                $res = @unserialize($trimmed, ['allowed_classes' => false]);
                if ($res !== false) {
                    return $res;
                }
            }
            return $data;
        }

        public static function rootUrl(string $path = ''): string
        {
            $base = class_exists(Uri::class) ? rtrim(Uri::root(), '/') : '';
            return $path === '' ? $base . '/' : $base . '/' . ltrim($path, '/');
        }
    }
}
PHP;
    }

    public function renderWpFunctions(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Filter\OutputFilter;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Mail\MailerFactoryInterface;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\Http\HttpFactory;

// --- Constants ---
if (!defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
    define('ARRAY_A', 'ARRAY_A');
    define('ARRAY_N', 'ARRAY_N');
}

// --- Core WP Classes ---
if (!class_exists('WP_Error', false)) {
    #[AllowDynamicProperties]
    class WP_Error
    {
        public array $errors = [];
        public array $error_data = [];

        public function __construct(string|int $code = '', string $message = '', mixed $data = '')
        {
            if ($code !== '') {
                $this->errors[$code][] = $message;
                if ($data !== '') {
                    $this->error_data[$code] = $data;
                }
            }
        }

        public function get_error_code(): string|int
        {
            $codes = array_keys($this->errors);
            return $codes[0] ?? '';
        }

        public function get_error_message(string|int $code = ''): string
        {
            $code = $code === '' ? $this->get_error_code() : $code;
            return $this->errors[$code][0] ?? '';
        }

        public function has_errors(): bool
        {
            return $this->errors !== [];
        }
    }
}

if (!class_exists('WP_Widget', false)) {
    #[AllowDynamicProperties]
    class WP_Widget
    {
        public string $id_base;
        public string $name;
        public array $widget_options = [];
        public array $control_options = [];
        public string $id = '';
        public int $number = 1;

        public function __construct(string $id_base, string $name, array $widget_options = [], array $control_options = [])
        {
            $this->id_base         = strtolower($id_base);
            $this->name            = $name;
            $this->widget_options  = $widget_options;
            $this->control_options = $control_options;
            $this->id              = $this->id_base . '-1';
        }

        public function widget($args, $instance)
        {
        }

        public function form($instance)
        {
            return 'noform';
        }

        public function update($new_instance, $old_instance)
        {
            return $new_instance;
        }

        public function get_field_id(string $field_name): string
        {
            return 'widget-' . $this->id_base . '-' . $this->number . '-' . $field_name;
        }

        public function get_field_name(string $field_name): string
        {
            return 'widget-' . $this->id_base . '[' . $this->number . '][' . $field_name . ']';
        }
    }
}

if (!class_exists('WP_Query', false)) {
    #[AllowDynamicProperties]
    class WP_Query
    {
        public array $posts = [];
        public int $post_count = 0;
        public int $current_post = -1;
        public ?object $post = null;

        public function __construct(array|string $query = [])
        {
            if (is_string($query)) {
                parse_str($query, $parsed);
                $query = $parsed;
            }
            $this->query($query);
        }

        public function query(array $args): array
        {
            $db = \JoomlaWpCompatRegistry::$db;
            if ($db === null) {
                return [];
            }
            $limit = (int) ($args['posts_per_page'] ?? 10);
            try {
                $q = $db->getQuery(true)
                    ->select(['id AS ID', 'title AS post_title', 'introtext AS post_content', 'created AS post_date', 'alias AS post_name', 'state AS post_status'])
                    ->from($db->quoteName('#__content'))
                    ->where($db->quoteName('state') . ' = 1')
                    ->order($db->quoteName('created') . ' DESC');
                if ($limit > 0) {
                    $q->setLimit($limit);
                }
                $db->setQuery($q);
                $this->posts      = $db->loadObjectList() ?: [];
                $this->post_count = count($this->posts);
            } catch (\Throwable) {
                $this->posts = [];
                $this->post_count = 0;
            }
            return $this->posts;
        }

        public function have_posts(): bool
        {
            return ($this->current_post + 1) < $this->post_count;
        }

        public function the_post(): void
        {
            $this->current_post++;
            $this->post = $this->posts[$this->current_post] ?? null;
            $GLOBALS['post'] = $this->post;
        }
    }
}

// --- Hooks & Filters ---
if (!function_exists('add_action')) {
    function add_action(string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1): bool
    {
        \JoomlaWpCompatRegistry::addHook($hook_name, $callback, $priority, $accepted_args);
        return true;
    }
}
if (!function_exists('add_filter')) {
    function add_filter(string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1): bool
    {
        \JoomlaWpCompatRegistry::addHook($hook_name, $callback, $priority, $accepted_args);
        return true;
    }
}
if (!function_exists('remove_action')) {
    function remove_action(string $hook_name, callable $callback, int $priority = 10): bool
    {
        return \JoomlaWpCompatRegistry::removeHook($hook_name, $callback, $priority);
    }
}
if (!function_exists('remove_filter')) {
    function remove_filter(string $hook_name, callable $callback, int $priority = 10): bool
    {
        return \JoomlaWpCompatRegistry::removeHook($hook_name, $callback, $priority);
    }
}
if (!function_exists('do_action')) {
    function do_action(string $hook_name, mixed ...$arg): void
    {
        \JoomlaWpCompatRegistry::doAction($hook_name, ...$arg);
    }
}
if (!function_exists('apply_filters')) {
    function apply_filters(string $hook_name, mixed $value, mixed ...$args): mixed
    {
        return \JoomlaWpCompatRegistry::applyFilters($hook_name, $value, ...$args);
    }
}
if (!function_exists('has_action')) {
    function has_action(string $hook_name): bool
    {
        return !empty(\JoomlaWpCompatRegistry::$hooks[$hook_name]);
    }
}
if (!function_exists('has_filter')) {
    function has_filter(string $hook_name): bool
    {
        return !empty(\JoomlaWpCompatRegistry::$hooks[$hook_name]);
    }
}

// --- Shortcodes ---
if (!function_exists('add_shortcode')) {
    function add_shortcode(string $tag, callable $callback): void
    {
        \JoomlaWpCompatRegistry::$shortcodes[$tag] = $callback;
    }
}
if (!function_exists('do_shortcode')) {
    function do_shortcode(string $content): string
    {
        return \JoomlaWpCompatRegistry::doShortcode($content);
    }
}
if (!function_exists('shortcode_atts')) {
    function shortcode_atts(array $pairs, array|string $atts, string $shortcode = ''): array
    {
        $atts = (array) $atts;
        $out  = [];
        foreach ($pairs as $name => $default) {
            $out[$name] = array_key_exists($name, $atts) ? $atts[$name] : $default;
        }
        return $out;
    }
}
if (!function_exists('shortcode_exists')) {
    function shortcode_exists(string $tag): bool
    {
        return isset(\JoomlaWpCompatRegistry::$shortcodes[$tag]);
    }
}

// --- Options & Transients ---
if (!function_exists('get_option')) {
    function get_option(string $option, mixed $default = false): mixed
    {
        return \JoomlaWpCompatRegistry::getOption($option, $default);
    }
}
if (!function_exists('update_option')) {
    function update_option(string $option, mixed $value, string|bool|null $autoload = null): bool
    {
        return \JoomlaWpCompatRegistry::updateOption($option, $value);
    }
}
if (!function_exists('add_option')) {
    function add_option(string $option, mixed $value = '', string $deprecated = '', string|bool $autoload = 'yes'): bool
    {
        if (get_option($option, '__WP_MISSING__') !== '__WP_MISSING__') {
            return false;
        }
        return update_option($option, $value);
    }
}
if (!function_exists('delete_option')) {
    function delete_option(string $option): bool
    {
        return \JoomlaWpCompatRegistry::deleteOption($option);
    }
}
if (!function_exists('set_transient')) {
    function set_transient(string $transient, mixed $value, int $expiration = 0): bool
    {
        return update_option('_transient_' . $transient, ['v' => $value, 'exp' => $expiration > 0 ? time() + $expiration : 0]);
    }
}
if (!function_exists('get_transient')) {
    function get_transient(string $transient): mixed
    {
        $raw = get_option('_transient_' . $transient, false);
        if (!is_array($raw) || !array_key_exists('v', $raw)) {
            return false;
        }
        if (($raw['exp'] ?? 0) > 0 && time() > (int) $raw['exp']) {
            delete_option('_transient_' . $transient);
            return false;
        }
        return $raw['v'];
    }
}
if (!function_exists('delete_transient')) {
    function delete_transient(string $transient): bool
    {
        return delete_option('_transient_' . $transient);
    }
}

// --- Assets (WebAssetManager) ---
if (!function_exists('wp_register_script')) {
    function wp_register_script(string $handle, string|false $src, array $deps = [], string|bool|null $ver = false, bool|array $in_footer = false): bool
    {
        \JoomlaWpCompatRegistry::$scripts[$handle] = [
            'src'       => (string) $src,
            'deps'      => $deps,
            'in_footer' => is_array($in_footer) ? (bool) ($in_footer['in_footer'] ?? false) : $in_footer,
            'enqueued'  => \JoomlaWpCompatRegistry::$scripts[$handle]['enqueued'] ?? false,
            'localized' => \JoomlaWpCompatRegistry::$scripts[$handle]['localized'] ?? [],
        ];
        return true;
    }
}
if (!function_exists('wp_enqueue_script')) {
    function wp_enqueue_script(string $handle, string $src = '', array $deps = [], string|bool|null $ver = false, bool|array $in_footer = false): void
    {
        if ($src !== '' || !isset(\JoomlaWpCompatRegistry::$scripts[$handle])) {
            wp_register_script($handle, $src, $deps, $ver, $in_footer);
        }
        \JoomlaWpCompatRegistry::$scripts[$handle]['enqueued'] = true;
    }
}
if (!function_exists('wp_localize_script')) {
    function wp_localize_script(string $handle, string $object_name, array $l10n): bool
    {
        \JoomlaWpCompatRegistry::$scripts[$handle]['localized'][$object_name] = $l10n;
        return true;
    }
}
if (!function_exists('wp_register_style')) {
    function wp_register_style(string $handle, string|false $src, array $deps = [], string|bool|null $ver = false, string $media = 'all'): bool
    {
        \JoomlaWpCompatRegistry::$styles[$handle] = [
            'src'      => (string) $src,
            'deps'     => $deps,
            'media'    => $media,
            'enqueued' => \JoomlaWpCompatRegistry::$styles[$handle]['enqueued'] ?? false,
        ];
        return true;
    }
}
if (!function_exists('wp_enqueue_style')) {
    function wp_enqueue_style(string $handle, string $src = '', array $deps = [], string|bool|null $ver = false, string $media = 'all'): void
    {
        if ($src !== '' || !isset(\JoomlaWpCompatRegistry::$styles[$handle])) {
            wp_register_style($handle, $src, $deps, $ver, $media);
        }
        \JoomlaWpCompatRegistry::$styles[$handle]['enqueued'] = true;
    }
}

// --- Paths & URLs ---
if (!function_exists('plugin_dir_path')) {
    function plugin_dir_path(string $file): string
    {
        return rtrim(dirname($file), '/\\') . DIRECTORY_SEPARATOR;
    }
}
if (!function_exists('plugin_dir_url')) {
    function plugin_dir_url(string $file): string
    {
        return \JoomlaWpCompatRegistry::$pluginUrl;
    }
}
if (!function_exists('plugins_url')) {
    function plugins_url(string $path = '', string $plugin = ''): string
    {
        return rtrim(\JoomlaWpCompatRegistry::$pluginUrl, '/') . '/' . ltrim($path, '/');
    }
}
if (!function_exists('plugin_basename')) {
    function plugin_basename(string $file): string
    {
        return basename(dirname($file)) . '/' . basename($file);
    }
}
if (!function_exists('site_url')) {
    function site_url(string $path = ''): string
    {
        return \JoomlaWpCompatRegistry::rootUrl($path);
    }
}
if (!function_exists('home_url')) {
    function home_url(string $path = ''): string
    {
        return \JoomlaWpCompatRegistry::rootUrl($path);
    }
}
if (!function_exists('admin_url')) {
    function admin_url(string $path = ''): string
    {
        $base = class_exists(Uri::class) ? rtrim(Uri::base(), '/') : '';
        if ($path === 'admin-ajax.php') {
            return $base . '/index.php?option=' . \JoomlaWpCompatRegistry::$elementName . '&task=ajax';
        }
        return $base . '/' . ltrim($path, '/');
    }
}
if (!function_exists('trailingslashit')) {
    function trailingslashit(string $value): string
    {
        return rtrim($value, '/\\') . '/';
    }
}
if (!function_exists('untrailingslashit')) {
    function untrailingslashit(string $value): string
    {
        return rtrim($value, '/\\');
    }
}

// --- Escaping & Sanitization ---
if (!function_exists('esc_html')) {
    function esc_html(mixed $text): string
    {
        return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
if (!function_exists('esc_attr')) {
    function esc_attr(mixed $text): string
    {
        return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
if (!function_exists('esc_textarea')) {
    function esc_textarea(mixed $text): string
    {
        return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
if (!function_exists('esc_url')) {
    function esc_url(string $url): string
    {
        $clean = filter_var(trim($url), FILTER_SANITIZE_URL);
        return $clean !== false ? htmlspecialchars($clean, ENT_QUOTES, 'UTF-8') : '';
    }
}
if (!function_exists('esc_js')) {
    function esc_js(mixed $text): string
    {
        return addslashes(htmlspecialchars((string) $text, ENT_NOQUOTES, 'UTF-8'));
    }
}
if (!function_exists('wp_kses_post')) {
    function wp_kses_post(string $data): string
    {
        return strip_tags($data, '<p><br><b><strong><i><em><u><a><ul><ol><li><h1><h2><h3><h4><h5><h6><blockquote><code><pre><img><span><div><table><thead><tbody><tr><th><td>');
    }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(mixed $str): string
    {
        return trim(preg_replace('/[\r\n\t ]+/', ' ', strip_tags((string) $str)) ?? '');
    }
}
if (!function_exists('sanitize_email')) {
    function sanitize_email(string $email): string
    {
        return (string) filter_var(trim($email), FILTER_SANITIZE_EMAIL);
    }
}
if (!function_exists('sanitize_key')) {
    function sanitize_key(string $key): string
    {
        return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key)) ?? '';
    }
}
if (!function_exists('sanitize_title')) {
    function sanitize_title(string $title): string
    {
        return class_exists(OutputFilter::class)
            ? OutputFilter::stringURLSafe($title)
            : trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($title)) ?? '', '-');
    }
}
if (!function_exists('wp_unslash')) {
    function wp_unslash(mixed $value): mixed
    {
        return is_array($value) ? array_map('wp_unslash', $value) : (is_string($value) ? stripslashes($value) : $value);
    }
}
if (!function_exists('wp_parse_args')) {
    function wp_parse_args(array|string|object $args, array $defaults = []): array
    {
        if (is_object($args)) {
            $parsed = get_object_vars($args);
        } elseif (is_string($args)) {
            parse_str($args, $parsed);
        } else {
            $parsed = $args;
        }
        return array_merge($defaults, $parsed);
    }
}
if (!function_exists('absint')) {
    function absint(mixed $maybeint): int
    {
        return abs((int) $maybeint);
    }
}

// --- i18n ---
if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return class_exists(Text::class) ? Text::_($text) : $text;
    }
}
if (!function_exists('_e')) {
    function _e(string $text, string $domain = 'default'): void
    {
        echo __($text, $domain);
    }
}
if (!function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = 'default'): string
    {
        return esc_html(__($text, $domain));
    }
}
if (!function_exists('esc_html_e')) {
    function esc_html_e(string $text, string $domain = 'default'): void
    {
        echo esc_html__($text, $domain);
    }
}
if (!function_exists('esc_attr__')) {
    function esc_attr__(string $text, string $domain = 'default'): string
    {
        return esc_attr(__($text, $domain));
    }
}
if (!function_exists('_x')) {
    function _x(string $text, string $context, string $domain = 'default'): string
    {
        return __($text, $domain);
    }
}
if (!function_exists('_n')) {
    function _n(string $single, string $plural, int $number, string $domain = 'default'): string
    {
        return $number === 1 ? __($single, $domain) : __($plural, $domain);
    }
}
if (!function_exists('load_plugin_textdomain')) {
    function load_plugin_textdomain(string $domain, string|false $deprecated = false, string|false $plugin_rel_path = false): bool
    {
        return true;
    }
}

// --- Users, ACL & Security ---
if (!function_exists('is_admin')) {
    function is_admin(): bool
    {
        return \JoomlaWpCompatRegistry::isAdmin();
    }
}
if (!function_exists('is_user_logged_in')) {
    function is_user_logged_in(): bool
    {
        try {
            return !Factory::getApplication()->getIdentity()->guest;
        } catch (\Throwable) {
            return false;
        }
    }
}
if (!function_exists('get_current_user_id')) {
    function get_current_user_id(): int
    {
        try {
            return (int) Factory::getApplication()->getIdentity()->id;
        } catch (\Throwable) {
            return 0;
        }
    }
}
if (!function_exists('wp_get_current_user')) {
    function wp_get_current_user(): object
    {
        try {
            $u = Factory::getApplication()->getIdentity();
            return (object) [
                'ID'           => (int) $u->id,
                'user_login'   => (string) $u->username,
                'user_email'   => (string) $u->email,
                'display_name' => (string) $u->name,
            ];
        } catch (\Throwable) {
            return (object) ['ID' => 0, 'user_login' => '', 'user_email' => '', 'display_name' => ''];
        }
    }
}
if (!function_exists('current_user_can')) {
    function current_user_can(string $capability): bool
    {
        try {
            $user = Factory::getApplication()->getIdentity();
            if ($user->guest) {
                return false;
            }
            if ($user->authorise('core.admin')) {
                return true;
            }
            return match ($capability) {
                'manage_options', 'activate_plugins' => $user->authorise('core.manage'),
                'edit_posts', 'publish_posts'        => $user->authorise('core.edit', 'com_content'),
                'read'                               => true,
                default                              => $user->authorise('core.manage'),
            };
        } catch (\Throwable) {
            return false;
        }
    }
}
if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce(string|int $action = -1): string
    {
        $token = class_exists(Session::class) ? Session::getFormToken() : 'joomla_token';
        return substr(hash_hmac('sha256', (string) $action, $token), 0, 16);
    }
}
if (!function_exists('wp_verify_nonce')) {
    function wp_verify_nonce(string $nonce, string|int $action = -1): int|false
    {
        return hash_equals(wp_create_nonce($action), $nonce) ? 1 : false;
    }
}
if (!function_exists('wp_nonce_field')) {
    function wp_nonce_field(string|int $action = -1, string $name = '_wpnonce', bool $referer = true, bool $display = true): string
    {
        $html = '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr(wp_create_nonce($action)) . '" />';
        if (class_exists(HTMLHelper::class)) {
            $html .= HTMLHelper::_('form.token');
        }
        if ($display) {
            echo $html;
        }
        return $html;
    }
}
if (!function_exists('check_admin_referer')) {
    function check_admin_referer(string|int $action = -1, string $query_arg = '_wpnonce'): bool
    {
        $nonce = (string) ($_REQUEST[$query_arg] ?? '');
        return wp_verify_nonce($nonce, $action) !== false;
    }
}
if (!function_exists('check_ajax_referer')) {
    function check_ajax_referer(string|int $action = -1, string|false $query_arg = false, bool $stop = true): int|false
    {
        $key   = is_string($query_arg) ? $query_arg : '_ajax_nonce';
        $nonce = (string) ($_REQUEST[$key] ?? ($_REQUEST['_wpnonce'] ?? ''));
        $valid = wp_verify_nonce($nonce, $action);
        if ($valid === false && $stop) {
            wp_send_json_error(['message' => 'Invalid security token'], 403);
        }
        return $valid;
    }
}

// --- Mail & HTTP (Joomla 6 Native APIs) ---
if (!function_exists('wp_mail')) {
    function wp_mail(string|array $to, string $subject, string $message, string|array $headers = '', string|array $attachments = []): bool
    {
        try {
            $mailer = Factory::getContainer()->get(MailerFactoryInterface::class)->createMailer();
            $mailer->addRecipient($to);
            $mailer->setSubject($subject);
            $mailer->setBody($message);
            if (is_string($headers) && stripos($headers, 'text/html') !== false) {
                $mailer->isHtml(true);
            }
            return (bool) $mailer->Send();
        } catch (\Throwable) {
            return false;
        }
    }
}
if (!function_exists('wp_remote_request')) {
    function wp_remote_request(string $url, array $args = []): array|WP_Error
    {
        try {
            $http    = (new HttpFactory())->getHttp();
            $method  = strtoupper((string) ($args['method'] ?? 'GET'));
            $headers = (array) ($args['headers'] ?? []);
            $body    = $args['body'] ?? '';

            $response = match ($method) {
                'POST'   => $http->post($url, $body, $headers),
                'PUT'    => $http->put($url, $body, $headers),
                'DELETE' => $http->delete($url, $headers),
                'HEAD'   => $http->head($url, $headers),
                default  => $http->get($url, $headers),
            };

            return [
                'headers'  => $response->getHeaders(),
                'body'     => (string) $response->getBody(),
                'response' => ['code' => $response->getStatusCode(), 'message' => $response->getReasonPhrase()],
            ];
        } catch (\Throwable $e) {
            return new WP_Error('http_request_failed', $e->getMessage());
        }
    }
}
if (!function_exists('wp_remote_get')) {
    function wp_remote_get(string $url, array $args = []): array|WP_Error
    {
        $args['method'] = 'GET';
        return wp_remote_request($url, $args);
    }
}
if (!function_exists('wp_remote_post')) {
    function wp_remote_post(string $url, array $args = []): array|WP_Error
    {
        $args['method'] = 'POST';
        return wp_remote_request($url, $args);
    }
}
if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body(array|WP_Error $response): string
    {
        return is_array($response) ? (string) ($response['body'] ?? '') : '';
    }
}
if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code(array|WP_Error $response): int
    {
        return is_array($response) ? (int) ($response['response']['code'] ?? 0) : 0;
    }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error(mixed $thing): bool
    {
        return $thing instanceof WP_Error;
    }
}

// --- JSON / Redirect / Die ---
if (!function_exists('wp_json_encode')) {
    function wp_json_encode(mixed $data, int $options = 0, int $depth = 512): string|false
    {
        return json_encode($data, $options | JSON_UNESCAPED_UNICODE, $depth);
    }
}
if (!function_exists('wp_send_json')) {
    function wp_send_json(mixed $response, ?int $status_code = null): never
    {
        if ($status_code !== null && !headers_sent()) {
            http_response_code($status_code);
        }
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo wp_json_encode($response);
        exit;
    }
}
if (!function_exists('wp_send_json_success')) {
    function wp_send_json_success(mixed $data = null, ?int $status_code = null): never
    {
        wp_send_json(['success' => true, 'data' => $data], $status_code);
    }
}
if (!function_exists('wp_send_json_error')) {
    function wp_send_json_error(mixed $data = null, ?int $status_code = null): never
    {
        wp_send_json(['success' => false, 'data' => $data], $status_code);
    }
}
if (!function_exists('wp_die')) {
    function wp_die(string|WP_Error $message = '', string $title = ''): never
    {
        $text = $message instanceof WP_Error ? $message->get_error_message() : $message;
        throw new \RuntimeException($title !== '' ? $title . ': ' . $text : $text);
    }
}
if (!function_exists('wp_redirect')) {
    function wp_redirect(string $location, int $status = 302): bool
    {
        try {
            Factory::getApplication()->redirect($location, $status);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
if (!function_exists('wp_safe_redirect')) {
    function wp_safe_redirect(string $location, int $status = 302): bool
    {
        return wp_redirect($location, $status);
    }
}

// --- Admin Menus, Settings API, Widgets, CPTs, REST, Lifecycle ---
if (!function_exists('add_menu_page')) {
    function add_menu_page(string $page_title, string $menu_title, string $capability, string $menu_slug, callable|string $callback = '', string $icon_url = '', ?int $position = null): string
    {
        \JoomlaWpCompatRegistry::$adminPages[$menu_slug] = [
            'page_title' => $page_title,
            'menu_title' => $menu_title,
            'capability' => $capability,
            'menu_slug'  => $menu_slug,
            'callback'   => $callback,
            'parent'     => null,
        ];
        return 'toplevel_page_' . $menu_slug;
    }
}
if (!function_exists('add_submenu_page')) {
    function add_submenu_page(string $parent_slug, string $page_title, string $menu_title, string $capability, string $menu_slug, callable|string $callback = ''): string
    {
        \JoomlaWpCompatRegistry::$adminPages[$menu_slug] = [
            'page_title' => $page_title,
            'menu_title' => $menu_title,
            'capability' => $capability,
            'menu_slug'  => $menu_slug,
            'callback'   => $callback,
            'parent'     => $parent_slug,
        ];
        return $parent_slug . '_page_' . $menu_slug;
    }
}
if (!function_exists('add_options_page')) {
    function add_options_page(string $page_title, string $menu_title, string $capability, string $menu_slug, callable|string $callback = ''): string
    {
        return add_submenu_page('options-general.php', $page_title, $menu_title, $capability, $menu_slug, $callback);
    }
}
if (!function_exists('add_management_page')) {
    function add_management_page(string $page_title, string $menu_title, string $capability, string $menu_slug, callable|string $callback = ''): string
    {
        return add_submenu_page('tools.php', $page_title, $menu_title, $capability, $menu_slug, $callback);
    }
}
if (!function_exists('register_setting')) {
    function register_setting(string $option_group, string $option_name, array|callable $args = []): void
    {
        \JoomlaWpCompatRegistry::$settings[$option_group][$option_name] = $args;
    }
}
if (!function_exists('add_settings_section')) {
    function add_settings_section(string $id, string $title, callable|string $callback, string $page): void
    {
        \JoomlaWpCompatRegistry::$settingsSections[$page][$id] = ['id' => $id, 'title' => $title, 'callback' => $callback];
    }
}
if (!function_exists('add_settings_field')) {
    function add_settings_field(string $id, string $title, callable|string $callback, string $page, string $section = 'default', array $args = []): void
    {
        \JoomlaWpCompatRegistry::$settingsFields[$page][$section][] = ['id' => $id, 'title' => $title, 'callback' => $callback, 'args' => $args];
    }
}
if (!function_exists('settings_fields')) {
    function settings_fields(string $option_group): void
    {
        echo '<input type="hidden" name="wp_option_group" value="' . esc_attr($option_group) . '" />';
        wp_nonce_field($option_group . '-options');
    }
}
if (!function_exists('do_settings_sections')) {
    function do_settings_sections(string $page): void
    {
        foreach (\JoomlaWpCompatRegistry::$settingsSections[$page] ?? [] as $sectionId => $sec) {
            if (!empty($sec['title'])) {
                echo '<h2>' . esc_html((string) $sec['title']) . '</h2>';
            }
            if (is_callable($sec['callback'])) {
                ($sec['callback'])($sec);
            }
            $fields = \JoomlaWpCompatRegistry::$settingsFields[$page][$sectionId] ?? [];
            if ($fields !== []) {
                echo '<table class="table form-table"><tbody>';
                foreach ($fields as $field) {
                    echo '<tr><th scope="row">' . esc_html((string) $field['title']) . '</th><td>';
                    if (is_callable($field['callback'])) {
                        ($field['callback'])($field['args']);
                    }
                    echo '</td></tr>';
                }
                echo '</tbody></table>';
            }
        }
    }
}
if (!function_exists('submit_button')) {
    function submit_button(?string $text = null, string $type = 'primary', string $name = 'submit', bool $wrap = true): void
    {
        $label = $text ?: 'Save Changes';
        $btn = '<button type="submit" name="' . esc_attr($name) . '" class="btn btn-primary">' . esc_html($label) . '</button>';
        echo $wrap ? '<p class="submit">' . $btn . '</p>' : $btn;
    }
}
if (!function_exists('register_widget')) {
    function register_widget(string|WP_Widget $widget): void
    {
        if (is_string($widget) && class_exists($widget)) {
            \JoomlaWpCompatRegistry::$widgets[$widget] = new $widget();
        } elseif ($widget instanceof WP_Widget) {
            \JoomlaWpCompatRegistry::$widgets[$widget::class] = $widget;
        }
    }
}
if (!function_exists('register_post_type')) {
    function register_post_type(string $post_type, array $args = []): object
    {
        \JoomlaWpCompatRegistry::$postTypes[$post_type] = $args;
        return (object) array_merge(['name' => $post_type], $args);
    }
}
if (!function_exists('register_taxonomy')) {
    function register_taxonomy(string $taxonomy, array|string $object_type, array $args = []): void
    {
        // Recorded in registry
    }
}
if (!function_exists('register_rest_route')) {
    function register_rest_route(string $route_namespace, string $route, array $args = []): bool
    {
        \JoomlaWpCompatRegistry::$restRoutes[trim($route_namespace, '/') . '/' . ltrim($route, '/')] = $args;
        return true;
    }
}
if (!function_exists('register_activation_hook')) {
    function register_activation_hook(string $file, callable $callback): void
    {
        add_action('joomla_extension_activate', $callback);
    }
}
if (!function_exists('register_deactivation_hook')) {
    function register_deactivation_hook(string $file, callable $callback): void
    {
        add_action('joomla_extension_deactivate', $callback);
    }
}
if (!function_exists('dbDelta')) {
    function dbDelta(string|array $queries): array
    {
        $list = is_array($queries) ? $queries : [$queries];
        $done = [];
        foreach ($list as $sql) {
            if (isset($GLOBALS['wpdb']) && method_exists($GLOBALS['wpdb'], 'query')) {
                $GLOBALS['wpdb']->query((string) $sql);
                $done[] = (string) $sql;
            }
        }
        return $done;
    }
}
if (!function_exists('wp_schedule_event')) {
    function wp_schedule_event(int $timestamp, string $recurrence, string $hook, array $args = []): bool
    {
        return true;
    }
}
if (!function_exists('wp_next_scheduled')) {
    function wp_next_scheduled(string $hook, array $args = []): int|false
    {
        return false;
    }
}
if (!function_exists('get_bloginfo')) {
    function get_bloginfo(string $show = ''): string
    {
        try {
            $app = Factory::getApplication();
            return match ($show) {
                'name'        => (string) $app->get('sitename', 'Joomla 6'),
                'admin_email' => (string) $app->get('mailfrom', ''),
                'url', 'wpurl'=> Uri::root(),
                'charset'     => 'UTF-8',
                'version'     => '6.0',
                default       => (string) $app->get('sitename', 'Joomla 6'),
            };
        } catch (\Throwable) {
            return '';
        }
    }
}
PHP;
    }

    public function renderNativeHelper(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

defined('_JEXEC') or die;

if (!class_exists('SadjadiiNativeHelper', false)) {
    final class SadjadiiNativeHelper
    {
        public static function escHtml(mixed $value): string
        {
            return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        public static function escAttr(mixed $value): string
        {
            return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        public static function escUrl(mixed $url): string
        {
            $clean = filter_var(trim((string) $url), FILTER_SANITIZE_URL);
            return $clean !== false ? htmlspecialchars($clean, ENT_QUOTES, 'UTF-8') : '';
        }

        public static function sanitizeText(mixed $value): string
        {
            return trim(preg_replace('/[\r\n\t ]+/', ' ', strip_tags((string) $value)) ?? '');
        }
    }
}
PHP;
    }
}
