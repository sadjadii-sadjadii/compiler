<?php

declare(strict_types=1);

namespace Sadjadii\Component\Copmiler\Administrator\Compiler\Model;

/**
 * Immutable metadata extracted from a WordPress plugin header.
 */
final readonly class PluginMetadata
{
    public function __construct(
        public string $name,
        public string $slug,
        public string $mainFile,
        public string $version = '1.0.0',
        public string $description = '',
        public string $author = 'Unknown',
        public string $authorUri = '',
        public string $pluginUri = '',
        public string $license = 'GPL-2.0-or-later',
        public string $textDomain = '',
        public string $domainPath = '/languages',
        public string $requiresPhp = '8.3',
        public string $requiresWp = '',
    ) {
    }

    public function toArray(): array
    {
        return [
            'name'        => $this->name,
            'slug'        => $this->slug,
            'mainFile'    => $this->mainFile,
            'version'     => $this->version,
            'description' => $this->description,
            'author'      => $this->author,
            'authorUri'   => $this->authorUri,
            'pluginUri'   => $this->pluginUri,
            'license'     => $this->license,
            'textDomain'  => $this->textDomain,
            'domainPath'  => $this->domainPath,
            'requiresPhp' => $this->requiresPhp,
            'requiresWp'  => $this->requiresWp,
        ];
    }
}
