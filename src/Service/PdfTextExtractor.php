<?php declare(strict_types=1);

namespace Splac\Service;

use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;

class PdfTextExtractor
{
    private const MAX_CHARS = 60000;

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

        // Normalize whitespace so prompts stay compact.
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
        $text = trim($text);

        if (mb_strlen($text) > self::MAX_CHARS) {
            $text = mb_substr($text, 0, self::MAX_CHARS) . "\n[... truncated ...]";
        }

        return $text;
    }
}
