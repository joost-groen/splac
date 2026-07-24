<?php declare(strict_types=1);

namespace Splac\Service\Llm;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class AnthropicClient implements LlmClientInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    public function getName(): string
    {
        return 'anthropic';
    }

    public function complete(string $apiKey, string $model, string $systemPrompt, string $userPrompt): string
    {
        try {
            $response = $this->httpClient->request('POST', 'https://api.anthropic.com/v1/messages', [
                'headers' => [
                    'x-api-key' => $apiKey,
                    'anthropic-version' => '2023-06-01',
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $model,
                    'max_tokens' => 8192,
                    'system' => $systemPrompt,
                    'messages' => [
                        ['role' => 'user', 'content' => $userPrompt],
                    ],
                    'temperature' => 0.2,
                ],
                'timeout' => 120,
            ]);

            $data = $response->toArray();
        } catch (\Throwable $e) {
            throw new LlmException('Anthropic request failed: ' . $e->getMessage(), 0, $e);
        }

        $content = $data['content'][0]['text'] ?? null;
        if (!\is_string($content) || $content === '') {
            throw new LlmException('Anthropic returned an empty response');
        }

        return $content;
    }

    public function ocrPdf(string $apiKey, string $model, string $pdfContent, string $filename): string
    {
        try {
            $response = $this->httpClient->request('POST', 'https://api.anthropic.com/v1/messages', [
                'headers' => [
                    'x-api-key' => $apiKey,
                    'anthropic-version' => '2023-06-01',
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $model,
                    'max_tokens' => 16384,
                    'messages' => [[
                        'role' => 'user',
                        'content' => [
                            [
                                'type' => 'document',
                                'source' => [
                                    'type' => 'base64',
                                    'media_type' => 'application/pdf',
                                    'data' => base64_encode($pdfContent),
                                ],
                            ],
                            [
                                'type' => 'text',
                                'text' => self::PDF_OCR_PROMPT,
                            ],
                        ],
                    ]],
                    'temperature' => 0,
                ],
                'timeout' => 180,
            ]);

            $data = $response->toArray();
        } catch (\Throwable $e) {
            throw new LlmException('Anthropic PDF OCR request failed: ' . $e->getMessage(), 0, $e);
        }

        $parts = [];
        foreach ($data['content'] ?? [] as $content) {
            if (\is_array($content) && ($content['type'] ?? null) === 'text' && \is_string($content['text'] ?? null)) {
                $parts[] = $content['text'];
            }
        }

        $text = trim(implode("\n", $parts));
        if ($text === '') {
            throw new LlmException('Anthropic returned an empty PDF OCR response');
        }

        return $text;
    }
}
