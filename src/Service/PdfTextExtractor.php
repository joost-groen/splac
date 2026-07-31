<?php declare(strict_types=1);

namespace Splac\Service;

use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;
use Symfony\Component\Process\Process;

class PdfTextExtractor
{
    private const MAX_CHARS = 60000;

    private const MAX_OCR_PAGES = 20;

    public function __construct(
        private readonly ?string $pdftoppmBinary = null,
        private readonly ?string $tesseractBinary = null,
        private readonly ?string $pdftotextBinary = null,
    ) {
    }

    /**
     * Extracts plain text from raw PDF bytes.
     */
    public function extract(string $pdfContent): string
    {
        $config = new Config();
        $config->setRetainImageContent(false);

        $parser = new Parser([], $config);
        $document = $parser->parseContent($pdfContent);

        $text = $document->getText();

        if ($this->needsOcrFallback($text)) {
            $recoveredText = $this->extractWithPdftotext($pdfContent);
            if ($this->isBetterExtraction($recoveredText, $text)) {
                $text = $recoveredText;
            }
        }

        if ($this->needsOcrFallback($text)) {
            $ocrText = $this->extractWithOcr($pdfContent);
            if ($ocrText !== '') {
                // Put OCR first so its recovered values survive the global
                // prompt-size limit even for unusually long datasheets.
                $text = "=== OCR fallback for text missing from embedded PDF fonts ===\n"
                    . $ocrText
                    . "\n\n=== Embedded PDF text ===\n"
                    . $text;
            }
        }

        // Normalize whitespace so prompts stay compact.
        $text = str_replace("\f", "\n\n", $text);
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
        $text = trim($text);

        if (mb_strlen($text) > self::MAX_CHARS) {
            $text = mb_substr($text, 0, self::MAX_CHARS) . "\n[... truncated ...]";
        }

        return $text;
    }

    private function needsOcrFallback(string $text): bool
    {
        if (!$this->hasVisibleText($text)) {
            return true;
        }

        // Broken or missing ToUnicode maps commonly leave labels intact while
        // dropping the bold value after the colon. A few such lines can be
        // legitimate; several in one document are a strong OCR signal.
        preg_match_all('/^[^\n:]{2,100}:\s*$/m', $text, $matches);

        return \count($matches[0]) >= 3;
    }

    private function isBetterExtraction(string $candidate, string $current): bool
    {
        if (!$this->hasVisibleText($candidate)) {
            return false;
        }

        if (!$this->hasVisibleText($current)) {
            return true;
        }

        if ($this->needsOcrFallback($current) && !$this->needsOcrFallback($candidate)) {
            return true;
        }

        return mb_strlen($candidate) > mb_strlen($current);
    }

    private function hasVisibleText(string $text): bool
    {
        return preg_match('/\S/u', $text) === 1;
    }

    private function extractWithPdftotext(string $pdfContent): string
    {
        $pdftotext = $this->resolveBinary($this->pdftotextBinary, [
            '/usr/bin/pdftotext',
            '/usr/local/bin/pdftotext',
            '/opt/homebrew/bin/pdftotext',
        ]);
        if ($pdftotext === null) {
            return '';
        }

        $temporaryDirectory = $this->createTemporaryDirectory();
        if ($temporaryDirectory === null) {
            return '';
        }

        $pdfPath = $temporaryDirectory . '/source.pdf';

        try {
            if (file_put_contents($pdfPath, $pdfContent) === false) {
                return '';
            }

            $extract = new Process([
                $pdftotext,
                '-layout',
                '-enc',
                'UTF-8',
                $pdfPath,
                '-',
            ]);
            $extract->setTimeout(120);
            $extract->run();

            return $extract->isSuccessful()
                ? trim($extract->getOutput(), " \n\r\t\v\0\x0C")
                : '';
        } catch (\Throwable) {
            return '';
        } finally {
            $this->removeTemporaryDirectory($temporaryDirectory);
        }
    }

    private function extractWithOcr(string $pdfContent): string
    {
        $pdftoppm = $this->resolveBinary($this->pdftoppmBinary, [
            '/usr/bin/pdftoppm',
            '/usr/local/bin/pdftoppm',
            '/opt/homebrew/bin/pdftoppm',
        ]);
        $tesseract = $this->resolveBinary($this->tesseractBinary, [
            '/usr/bin/tesseract',
            '/usr/local/bin/tesseract',
            '/opt/homebrew/bin/tesseract',
        ]);

        if ($pdftoppm === null || $tesseract === null) {
            return '';
        }

        $temporaryDirectory = $this->createTemporaryDirectory();
        if ($temporaryDirectory === null) {
            return '';
        }

        $pdfPath = $temporaryDirectory . '/source.pdf';
        $imagePrefix = $temporaryDirectory . '/page';

        try {
            if (file_put_contents($pdfPath, $pdfContent) === false) {
                return '';
            }

            $render = new Process([
                $pdftoppm,
                '-f',
                '1',
                '-l',
                (string) self::MAX_OCR_PAGES,
                '-r',
                '180',
                '-png',
                $pdfPath,
                $imagePrefix,
            ]);
            $render->setTimeout(120);
            $render->run();
            if (!$render->isSuccessful()) {
                return '';
            }

            $pages = glob($imagePrefix . '-*.png') ?: [];
            natsort($pages);

            $parts = [];
            foreach ($pages as $pageNumber => $pagePath) {
                $ocr = new Process([$tesseract, $pagePath, 'stdout', '-l', 'eng']);
                $ocr->setTimeout(60);
                $ocr->run();
                if (!$ocr->isSuccessful()) {
                    continue;
                }

                $pageText = trim($ocr->getOutput());
                if ($pageText !== '') {
                    $parts[] = \sprintf("=== OCR page %d ===\n%s", $pageNumber + 1, $pageText);
                }
            }

            return implode("\n\n", $parts);
        } catch (\Throwable) {
            return '';
        } finally {
            $this->removeTemporaryDirectory($temporaryDirectory);
        }
    }

    private function createTemporaryDirectory(): ?string
    {
        try {
            $temporaryDirectory = sys_get_temp_dir() . '/splac-pdf-' . bin2hex(random_bytes(8));
        } catch (\Throwable) {
            return null;
        }

        if (!mkdir($temporaryDirectory, 0700) && !is_dir($temporaryDirectory)) {
            return null;
        }

        return $temporaryDirectory;
    }

    private function removeTemporaryDirectory(string $temporaryDirectory): void
    {
        foreach (glob($temporaryDirectory . '/*') ?: [] as $temporaryFile) {
            if (is_file($temporaryFile)) {
                @unlink($temporaryFile);
            }
        }
        @rmdir($temporaryDirectory);
    }

    /**
     * @param list<string> $candidates
     */
    private function resolveBinary(?string $configuredPath, array $candidates): ?string
    {
        if ($configuredPath !== null && is_executable($configuredPath)) {
            return $configuredPath;
        }

        foreach ($candidates as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
