<?php declare(strict_types=1);

namespace Splac\Service\Llm;

final readonly class LlmResponse
{
    public function __construct(
        public string $text,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public int $ocrPages = 0,
    ) {
    }
}
