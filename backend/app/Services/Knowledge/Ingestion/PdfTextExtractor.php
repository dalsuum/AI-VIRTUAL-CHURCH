<?php

namespace App\Services\Knowledge\Ingestion;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Extracts plain text from a PDF using poppler's `pdftotext`. Invoked with array arguments via
 * Symfony Process (no shell), so a malicious filename cannot inject a command. The OverlapText
 * chunker cleans the extracted text downstream, so light layout noise is acceptable.
 *
 * Requires the `pdftotext` binary (Debian/Ubuntu: `sudo apt-get install poppler-utils`).
 */
final class PdfTextExtractor
{
    public function __construct(private readonly int $timeoutSeconds = 120) {}

    public function available(): bool
    {
        return (new \Symfony\Component\Process\ExecutableFinder())->find('pdftotext') !== null;
    }

    /** Extract UTF-8 text from a single PDF file path. Throws on a missing binary or parse error. */
    public function extract(string $path): string
    {
        if (! is_file($path)) {
            throw new \RuntimeException("PDF not found: {$path}");
        }
        if (! $this->available()) {
            throw new \RuntimeException('pdftotext not installed. Run: sudo apt-get install poppler-utils');
        }

        // -layout keeps reading order sane; "-" writes the text to stdout.
        $process = new Process(['pdftotext', '-layout', '-enc', 'UTF-8', $path, '-']);
        $process->setTimeout($this->timeoutSeconds);

        try {
            $process->mustRun();
        } catch (ProcessFailedException $e) {
            throw new \RuntimeException("Failed to extract PDF [{$path}]: {$e->getMessage()}", 0, $e);
        }

        return trim($process->getOutput());
    }
}
