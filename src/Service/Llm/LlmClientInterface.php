<?php declare(strict_types=1);

namespace Splac\Service\Llm;

interface LlmClientInterface
{
    public const PDF_OCR_PROMPT = <<<'PROMPT'
Perform a complete OCR pass on the attached PDF.

Return only the document content in reading order. Preserve headings, lists, tables, labels,
values, identifiers, units, and page boundaries as Markdown where possible. Transcribe text
visible in images as well as embedded PDF text. Do not summarize, interpret, translate, or
omit repeated content.
PROMPT;

    /**
     * Provider key as used in the plugin configuration (e.g. "openai").
     */
    public function getName(): string;

    public function supportsReasoning(): bool;

    public function supportsBatchProcessing(): bool;

    /**
     * Sends a chat completion request and returns its text and provider-reported usage.
     * Implementations should request JSON output from the provider where supported.
     *
     * @throws LlmException
     */
    public function complete(
        string $apiKey,
        string $model,
        string $systemPrompt,
        string $userPrompt,
        CompletionOptions $options,
        ?string $cacheableContext = null,
    ): LlmResponse;

    /**
     * Sends raw PDF bytes directly to the provider and returns its OCR/document transcription.
     *
     * @throws LlmException
     */
    public function ocrPdf(string $apiKey, string $model, string $pdfContent, string $filename): LlmResponse;
}
