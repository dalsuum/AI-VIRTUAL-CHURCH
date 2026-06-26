<?php

namespace Tests\Unit\Knowledge;

use App\Services\Knowledge\Ingestion\PdfTextExtractor;
use PHPUnit\Framework\TestCase;

/**
 * Guards for the PDF extractor: it fails clearly on a missing file or a missing binary rather
 * than producing silent/garbage output. (Actual extraction is exercised manually once
 * poppler-utils is installed.)
 */
class PdfTextExtractorTest extends TestCase
{
    public function test_missing_file_throws_clear_error(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PDF not found');

        (new PdfTextExtractor())->extract('/no/such/file.pdf');
    }

    public function test_available_returns_bool(): void
    {
        // Either outcome is valid depending on the host; the point is it never throws.
        $this->assertIsBool((new PdfTextExtractor())->available());
    }
}
