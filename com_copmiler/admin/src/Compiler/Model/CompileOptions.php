<?php

declare(strict_types=1);

namespace Sadjadii\Component\Copmiler\Administrator\Compiler\Model;

/**
 * Compilation options provided by the user or CLI.
 */
final readonly class CompileOptions
{
    public const MODE_HYBRID = 'hybrid';
    public const MODE_NATIVE = 'native';

    public const TARGET_AUTO      = 'auto';
    public const TARGET_COMPONENT = 'component';
    public const TARGET_PLUGIN    = 'plugin';
    public const TARGET_MODULE    = 'module';

    public function __construct(
        public string $mode = self::MODE_HYBRID,
        public string $targetOverride = self::TARGET_AUTO,
        public string $pluginGroupOverride = 'auto',
        public string $vendorNamespace = 'WpCompiled',
        public string $targetJoomlaVersion = '6.0',
    ) {
    }

    public function isHybrid(): bool
    {
        return $this->mode === self::MODE_HYBRID;
    }

    public function isNative(): bool
    {
        return $this->mode === self::MODE_NATIVE;
    }

    public function toArray(): array
    {
        return [
            'mode'                => $this->mode,
            'targetOverride'      => $this->targetOverride,
            'pluginGroupOverride' => $this->pluginGroupOverride,
            'vendorNamespace'     => $this->vendorNamespace,
            'targetJoomlaVersion' => $this->targetJoomlaVersion,
        ];
    }
}
