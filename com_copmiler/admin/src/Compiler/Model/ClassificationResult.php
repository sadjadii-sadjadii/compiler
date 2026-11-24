<?php

declare(strict_types=1);

namespace Sadjadii\Component\Copmiler\Administrator\Compiler\Model;

/**
 * Result of classifying a WordPress plugin into an exact Joomla 6 extension type.
 */
final readonly class ClassificationResult
{
    public const TYPE_COMPONENT = 'component';
    public const TYPE_PLUGIN    = 'plugin';
    public const TYPE_MODULE    = 'module';

    /**
     * @param string                $type        One of component | plugin | module
     * @param string                $pluginGroup Only meaningful when type === 'plugin' (system|content|user|authentication|webservices|task)
     * @param array<string, int>    $scores      Raw weighted scores for component, plugin, module
     * @param int                   $confidence  0..100 confidence percentage
     * @param list<string>          $reasons     Human-readable classification evidence
     * @param bool                  $overridden  Whether the user manually forced the target type
     */
    public function __construct(
        public string $type,
        public string $pluginGroup,
        public array $scores,
        public int $confidence,
        public array $reasons,
        public bool $overridden = false,
    ) {
    }

    public function getElementName(string $slug): string
    {
        $clean = preg_replace('/[^a-z0-9_]/', '', strtolower(str_replace('-', '_', $slug))) ?: 'wpext';

        return match ($this->type) {
            self::TYPE_COMPONENT => 'com_' . $clean,
            self::TYPE_MODULE    => 'mod_' . $clean,
            default              => 'plg_' . $this->pluginGroup . '_' . $clean,
        };
    }

    public function toArray(): array
    {
        return [
            'type'        => $this->type,
            'pluginGroup' => $this->pluginGroup,
            'scores'      => $this->scores,
            'confidence'  => $this->confidence,
            'reasons'     => $this->reasons,
            'overridden'  => $this->overridden,
        ];
    }
}
