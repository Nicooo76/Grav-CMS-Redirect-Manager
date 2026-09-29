<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\ImportExport;

final readonly class ExportResult
{
    /**
     * @param list<ExportNote> $skipped rules that are not part of the file, with the reason
     * @param list<ExportNote> $lossy   rules that are in the file but lost attributes the format cannot express
     * @param int              $exported number of rules written
     */
    public function __construct(
        public string $content,
        public string $filename = '',
        public string $mimeType = 'text/plain',
        public array $skipped = [],
        public array $lossy = [],
        public int $exported = 0,
    ) {
    }

    public function withFile(string $filename, string $mimeType): self
    {
        return new self($this->content, $filename, $mimeType, $this->skipped, $this->lossy, $this->exported);
    }
}
