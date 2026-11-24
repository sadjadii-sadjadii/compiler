<?php

declare(strict_types=1);

namespace Sadjadii\Component\Copmiler\Administrator\Compiler\Transformer;

/**
 * Converts WordPress dbDelta / $wpdb CREATE TABLE SQL definitions into
 * valid Joomla 6 `#__` MySQL/MariaDB install & uninstall SQL scripts.
 */
final class SqlSchemaConverter
{
    /**
     * @param list<string> $rawStatements SQL CREATE TABLE statements extracted from WP plugin
     * @return array{install: string, uninstall: string, tables: list<string>}
     */
    public function convert(array $rawStatements, string $slug, bool $includeOptionsTable = true): array
    {
        $installParts   = [];
        $uninstallParts = [];
        $tables         = [];

        // Always create a dedicated Joomla options/transients table for Hybrid WP compatibility when needed
        if ($includeOptionsTable) {
            $optTable = '#__' . $slug . '_wp_options';
            $tables[] = $optTable;
            $installParts[] = <<<SQL
CREATE TABLE IF NOT EXISTS `{$optTable}` (
    `option_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `option_name` VARCHAR(191) NOT NULL,
    `option_value` LONGTEXT NOT NULL,
    `autoload` VARCHAR(20) NOT NULL DEFAULT 'yes',
    PRIMARY KEY (`option_id`),
    UNIQUE KEY `idx_option_name` (`option_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
            $uninstallParts[] = "DROP TABLE IF EXISTS `{$optTable}`;";
        }

        foreach ($rawStatements as $raw) {
            $converted = $this->convertSingleCreateTable($raw, $slug);
            if ($converted === null) {
                continue;
            }

            if (in_array($converted['table'], $tables, true)) {
                continue;
            }

            $tables[]         = $converted['table'];
            $installParts[]   = $converted['sql'];
            $uninstallParts[] = "DROP TABLE IF EXISTS `{$converted['table']}`;";
        }

        return [
            'install'   => implode("\n\n", $installParts) . "\n",
            'uninstall' => implode("\n", $uninstallParts) . "\n",
            'tables'    => $tables,
        ];
    }

    /**
     * @return array{table: string, sql: string}|null
     */
    private function convertSingleCreateTable(string $raw, string $slug): ?array
    {
        $sql = trim($raw);

        // Replace WP PHP interpolation patterns for table prefix
        $sql = preg_replace('/\{\$wpdb->prefix\}|\$wpdb->prefix\s*\.\s*[\'"]?|\$table_prefix\s*\.\s*[\'"]?/i', '#__', $sql) ?? $sql;
        $sql = preg_replace('/\$[a-zA-Z_][a-zA-Z0-9_]*table[a-zA-Z0-9_]*/i', '#__' . $slug . '_data', $sql) ?? $sql;
        // Replace $charset_collate variable
        $sql = preg_replace('/\{\$charset_collate\}|\$charset_collate/i', 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', $sql) ?? $sql;
        // Remove stray PHP string concatenation quotes/dots
        $sql = str_replace(['".', '."', "'.", ".'"], '', $sql);
        $sql = rtrim($sql, "; \t\n\r") . ';';

        // Ensure IF NOT EXISTS
        if (stripos($sql, 'IF NOT EXISTS') === false) {
            $sql = preg_replace('/CREATE\s+TABLE\s+/i', 'CREATE TABLE IF NOT EXISTS ', $sql, 1) ?? $sql;
        }

        // Extract table name
        if (preg_match('/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+`?([a-zA-Z0-9_#]+)`?/i', $sql, $m) !== 1) {
            return null;
        }

        $tableName = $m[1];
        if (!str_starts_with($tableName, '#__')) {
            $newTableName = '#__' . ltrim($tableName, '_');
            $sql = preg_replace(
                '/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+`?' . preg_quote($tableName, '/') . '`?/i',
                'CREATE TABLE IF NOT EXISTS `' . $newTableName . '`',
                $sql,
                1
            ) ?? $sql;
            $tableName = $newTableName;
        }

        // Ensure ENGINE & CHARSET if absent
        if (stripos($sql, 'CHARSET') === false) {
            $sql = rtrim($sql, ';') . ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';
        }

        return [
            'table' => $tableName,
            'sql'   => $sql,
        ];
    }
}
