<?php

declare(strict_types=1);

namespace Sadjadii\Component\Copmiler\Administrator\Compiler\Model;

/**
 * Output of a completed compilation run.
 */
final readonly class CompileResult
{
    /**
     * @param AnalysisResult       $analysis
     * @param ClassificationResult $classification
     * @param CompileOptions       $options
     * @param string               $elementName     e.g. com_myplugin, plg_content_myplugin, mod_myplugin
     * @param string               $zipPath         Absolute path to generated Joomla 6 installable ZIP
     * @param string               $zipFilename     Filename of the generated ZIP
     * @param int                  $zipSize         File size in bytes
     * @param list<string>         $generatedFiles  Relative paths inside the generated ZIP
     * @param list<string>         $transformLogs   Transformation notes
     * @param float                $durationSeconds Build time in seconds
     */
    public function __construct(
        public AnalysisResult $analysis,
        public ClassificationResult $classification,
        public CompileOptions $options,
        public string $elementName,
        public string $zipPath,
        public string $zipFilename,
        public int $zipSize,
        public array $generatedFiles,
        public array $transformLogs,
        public float $durationSeconds,
    ) {
    }

    public function toArray(): array
    {
        return [
            'elementName'     => $this->elementName,
            'zipPath'         => $this->zipPath,
            'zipFilename'     => $this->zipFilename,
            'zipSize'         => $this->zipSize,
            'generatedFiles'  => $this->generatedFiles,
            'transformLogs'   => $this->transformLogs,
            'durationSeconds' => round($this->durationSeconds, 3),
            'options'         => $this->options->toArray(),
            'classification'  => $this->classification->toArray(),
            'analysis'        => $this->analysis->toArray(),
        ];
    }
}
