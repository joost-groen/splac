<?php declare(strict_types=1);

namespace Splac\Service\Llm;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class GeminiClient implements LlmClientInterface
{
    private const MAX_ATTEMPTS = 3;

    private const RETRYABLE_STATUS_CODES = [500, 502, 503, 504];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    public function getName(): string
    {
        return 'gemini';
    }

    public function complete(string $apiKey, string $model, string $systemPrompt, string $userPrompt): string
    {
        $url = \sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent',
            rawurlencode($model)
        );

        // Gemma models are exposed through the Gemini API, but they do not
        // support the separate systemInstruction field. Sending the system
        // prompt as ordinary content also causes these models to return an
        // internal API error, so rely on the self-contained task prompt.
        $isGemma = str_starts_with(strtolower($model), 'gemma-');

        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [['text' => $userPrompt]],
                ],
            ],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'temperature' => 0.2,
            ],
        ];

        if (!$isGemma) {
            $payload['systemInstruction'] = [
                'parts' => [['text' => $systemPrompt]],
            ];
        }

        $data = null;
        $lastException = null;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; ++$attempt) {
            try {
                $response = $this->httpClient->request('POST', $url, [
                    'headers' => [
                        'x-goog-api-key' => $apiKey,
                        'Content-Type' => 'application/json',
                    ],
                    'json' => $payload,
                    'timeout' => 120,
                ]);

                $statusCode = $response->getStatusCode();
                $responseData = $response->toArray(false);

                if (\in_array($statusCode, self::RETRYABLE_STATUS_CODES, true) && $attempt < self::MAX_ATTEMPTS) {
                    sleep($attempt * 2);

                    continue;
                }

                if ($statusCode < 200 || $statusCode >= 300) {
                    $errorMessage = $responseData['error']['message'] ?? 'Unknown API error';

                    throw new LlmException(\sprintf(
                        'Gemini request failed (HTTP %d): %s',
                        $statusCode,
                        \is_string($errorMessage) ? $errorMessage : 'Unknown API error'
                    ));
                }

                $data = $responseData;

                break;
            } catch (LlmException $e) {
                throw $e;
            } catch (\Throwable $e) {
                $lastException = $e;
                if ($attempt < self::MAX_ATTEMPTS) {
                    sleep($attempt * 2);

                    continue;
                }
            }
        }

        if (!\is_array($data)) {
            throw new LlmException(
                'Gemini request failed: ' . ($lastException?->getMessage() ?? 'Unknown transport error'),
                0,
                $lastException
            );
        }

        $contentParts = [];
        foreach ($data['candidates'][0]['content']['parts'] ?? [] as $part) {
            if (($part['thought'] ?? false) === true) {
                continue;
            }

            $text = $part['text'] ?? null;
            if (\is_string($text) && $text !== '') {
                $contentParts[] = $text;
            }
        }

        $content = implode('', $contentParts);
        if ($content === '') {
            throw new LlmException('Gemini returned an empty response');
        }

        return $content;
    }
}
