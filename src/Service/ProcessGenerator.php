<?php declare(strict_types=1);

namespace Splac\Service;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Splac\Core\Content\Process\ProcessEntity;
use Splac\Core\Content\ProcessSource\ProcessSourceDefinition;
use Splac\Core\Content\Template\TemplateEntity;
use Splac\Service\Llm\CompletionOptions;
use Splac\Service\Llm\LlmService;
use Splac\Service\Llm\PromptBuilder;

/**
 * Runs the LLM generation steps of a process and writes the results into
 * the process output JSON.
 */
class ProcessGenerator
{
    public const STEP_CLASSIFICATION = 'classification';
    public const STEP_DESCRIPTION = 'description';
    public const STEP_SEO = 'seo';
    public const STEP_PROPERTIES = 'properties';
    public const STEP_CATEGORY = 'category';

    public const DEFAULT_LOCALES = ['de-DE', 'en-GB'];

    public function __construct(
        private readonly EntityRepository $processRepository,
        private readonly EntityRepository $propertyGroupRepository,
        private readonly EntityRepository $manufacturerRepository,
        private readonly LlmService $llmService,
        private readonly PromptBuilder $promptBuilder,
        private readonly ProductNumberGenerator $productNumberGenerator,
    ) {
    }

    /**
     * @return list<string> ordered steps enabled for this process
     */
    public function resolveSteps(ProcessEntity $process): array
    {
        $template = $process->getTemplate();
        $features = $template?->getConfig()['features'] ?? [];
        $input = $process->getInput() ?? [];

        $steps = [self::STEP_CLASSIFICATION];

        if (($features['description'] ?? true) !== false) {
            $steps[] = self::STEP_DESCRIPTION;
        }
        if (($features['seo'] ?? true) !== false) {
            $steps[] = self::STEP_SEO;
        }
        if (($features['properties'] ?? true) !== false) {
            $steps[] = self::STEP_PROPERTIES;
        }
        if ((($features['categoryCreation'] ?? true) !== false) && ($input['categoryMode'] ?? 'existing') === 'template') {
            $steps[] = self::STEP_CATEGORY;
        }

        return $steps;
    }

    /**
     * Executes one step and persists the merged output.
     */
    public function runStep(
        ProcessEntity $process,
        string $step,
        Context $context,
        ?string $batchId = null,
        bool $forceAdaptiveThinking = false,
    ): void
    {
        $template = $process->getTemplate();
        if ($template === null) {
            throw new \RuntimeException('Process has no template');
        }

        $locales = $this->resolveLocales($process);
        $sourceText = $this->collectSourceText($process);
        $input = $process->getInput() ?? [];
        $productNameHint = (string) ($input['productName'] ?? $process->getName());
        $output = $process->getOutput() ?? [];
        $completionOptions = CompletionOptions::fromProcessInput($input, $batchId, $forceAdaptiveThinking);

        $result = match ($step) {
            self::STEP_CLASSIFICATION => $this->runClassification($process->getId(), $template, $locales, $sourceText, $productNameHint, $context, $completionOptions),
            self::STEP_DESCRIPTION => $this->runDescription($process->getId(), $template, $locales, $sourceText, $productNameHint, $input, $completionOptions),
            self::STEP_SEO => $this->runSeo($process->getId(), $template, $locales, $sourceText, $productNameHint, $input, $completionOptions),
            self::STEP_PROPERTIES => $this->runProperties($process->getId(), $template, $sourceText, $productNameHint, $context, $completionOptions),
            self::STEP_CATEGORY => $this->runCategory($process, $locales, $sourceText, $productNameHint, $completionOptions),
            default => throw new \RuntimeException(\sprintf('Unknown generation step "%s"', $step)),
        };

        $output = array_merge($output, $result);

        $this->processRepository->update([[
            'id' => $process->getId(),
            'output' => $output,
        ]], $context);

        $process->setOutput($output);
    }

    /**
     * @return list<string>
     */
    public function resolveLocales(ProcessEntity $process): array
    {
        $template = $process->getTemplate();
        $selected = $process->getInput()['language'] ?? null;
        $configured = $template->getConfig()['languages'] ?? null;
        $available = \is_array($configured) && $configured !== []
            ? array_values(array_filter($configured, static fn ($locale) => \is_string($locale) && $locale !== ''))
            : self::DEFAULT_LOCALES;

        if (\is_string($selected) && \in_array($selected, $available, true)) {
            return [$selected];
        }

        // Existing processes created before language selection was introduced
        // still generate exactly one language instead of all template locales.
        return [$available[0] ?? self::DEFAULT_LOCALES[0]];
    }

    private function collectSourceText(ProcessEntity $process): string
    {
        $parts = [];

        $sources = $process->getSources();
        if ($sources !== null) {
            foreach ($sources as $source) {
                if ($source->getStatus() !== ProcessSourceDefinition::STATUS_DONE) {
                    continue;
                }
                $text = $source->getExtractedText();
                if ($text !== null && $text !== '') {
                    $parts[] = \sprintf("=== Source: %s ===\n%s", $source->getFilename(), $text);
                }
            }
        }

        $notes = $process->getInput()['notes'] ?? null;
        if (\is_string($notes) && trim($notes) !== '') {
            $parts[] = "=== User provided notes ===\n" . trim($notes);
        }

        if ($parts === []) {
            return '(no sources provided)';
        }

        return implode("\n\n", $parts);
    }

    /**
     * @param list<string> $locales
     *
     * @return array<string, mixed>
     */
    private function runClassification(
        string $processId,
        TemplateEntity $template,
        array $locales,
        string $sourceText,
        string $productNameHint,
        Context $context,
        CompletionOptions $completionOptions,
    ): array {
        $config = $template->getConfig() ?? [];
        $features = $config['features'] ?? [];

        $manufacturers = [];
        $criteria = new Criteria();
        $criteria->addSorting(new FieldSorting('name'));
        $criteria->setLimit(500);
        foreach ($this->manufacturerRepository->search($criteria, $context)->getEntities() as $manufacturer) {
            $manufacturers[] = [
                'id' => $manufacturer->getId(),
                'name' => (string) $manufacturer->getTranslation('name'),
            ];
        }

        $pattern = $config['productNumberPattern'] ?? null;

        $prompt = $this->promptBuilder->buildClassificationPrompt(
            $manufacturers,
            $locales,
            $productNameHint,
            \is_string($pattern) ? $pattern : null,
            $this->stringConfigValue($config, 'targetMarket'),
        );

        $data = $this->llmService->completeJson(
            $this->promptBuilder->buildSystemPrompt(),
            $prompt,
            $processId,
            self::STEP_CLASSIFICATION,
            $completionOptions,
            $sourceText,
        );

        $result = [
            'productName' => $this->localeMap($data['productName'] ?? [], $locales),
        ];

        if (($features['manufacturer'] ?? true) !== false) {
            $result['manufacturerId'] = \is_string($data['manufacturerId'] ?? null) ? $data['manufacturerId'] : '';
            $result['manufacturerName'] = \is_string($data['manufacturerName'] ?? null) ? $data['manufacturerName'] : '';
        }

        if (($features['identifiers'] ?? true) !== false) {
            $result['ean'] = \is_string($data['ean'] ?? null) ? preg_replace('/\D/', '', $data['ean']) : '';
            $result['manufacturerNumber'] = \is_string($data['manufacturerNumber'] ?? null) ? $data['manufacturerNumber'] : '';
        }

        if (($features['productNumber'] ?? true) !== false) {
            $proposed = \is_string($data['productNumber'] ?? null) ? $data['productNumber'] : '';
            $result['productNumber'] = $this->productNumberGenerator->makeUnique($proposed, $context);
        }

        if (($features['tags'] ?? true) !== false) {
            $result['tags'] = array_values(array_filter(
                \is_array($data['tags'] ?? null) ? $data['tags'] : [],
                static fn ($tag) => \is_string($tag) && trim($tag) !== ''
            ));
        }

        if (($features['keywords'] ?? true) !== false) {
            $result['keywords'] = $this->localeMap($data['keywords'] ?? [], $locales);
        }

        return $result;
    }

    /**
     * @param list<string> $locales
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function runDescription(
        string $processId,
        TemplateEntity $template,
        array $locales,
        string $sourceText,
        string $productNameHint,
        array $input,
        CompletionOptions $completionOptions,
    ): array {
        $config = $template->getConfig() ?? [];
        $descriptionTemplates = $this->promptBuilder->prepareDescriptionTemplates(
            $template->getDescriptionTemplates() ?? [],
            $config,
            $locales,
        );
        if ($descriptionTemplates === []) {
            return ['description' => []];
        }

        $prompt = $this->promptBuilder->buildDescriptionPrompt(
            $descriptionTemplates,
            $locales,
            $this->descriptionPlaceholderGuidance($config, $locales),
            $productNameHint,
            \is_string($input['descriptionInstruction'] ?? null) ? $input['descriptionInstruction'] : null,
            $this->stringConfigValue($config, 'targetMarket'),
        );

        $data = $this->llmService->completeJson(
            $this->promptBuilder->buildSystemPrompt(),
            $prompt,
            $processId,
            self::STEP_DESCRIPTION,
            $completionOptions,
            $sourceText,
        );
        $placeholderValues = \is_array($data['placeholders'] ?? null) ? $data['placeholders'] : [];
        $blocksByLocale = \is_array($config['descriptionBlocks'] ?? null)
            ? $config['descriptionBlocks']
            : [];

        $descriptions = [];
        foreach ($locales as $locale) {
            $html = $descriptionTemplates[$locale] ?? reset($descriptionTemplates);
            if (!\is_string($html)) {
                continue;
            }

            $values = \is_array($placeholderValues[$locale] ?? null) ? $placeholderValues[$locale] : [];
            $blocks = \is_array($blocksByLocale[$locale] ?? null) ? $blocksByLocale[$locale] : [];
            $html = $this->promptBuilder->resolveDescriptionConditionals($html, $values, $blocks);

            $resolvedHtml = preg_replace_callback(
                '/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/',
                function (array $matches) use ($values): string {
                    $value = $values[$matches[1]] ?? '';

                    return \is_string($value)
                        ? $this->promptBuilder->sanitizeProductDescription($value)
                        : '';
                },
                $html
            ) ?? $html;
            $descriptions[$locale] = $this->promptBuilder->sanitizeProductDescription($resolvedHtml);
        }

        return ['description' => $descriptions];
    }

    /**
     * @param array<string, mixed> $config
     * @param list<string> $locales
     *
     * @return array<string, array<string, array{type: string, instruction: string}>>
     */
    private function descriptionPlaceholderGuidance(array $config, array $locales): array
    {
        $blocksByLocale = \is_array($config['descriptionBlocks'] ?? null) ? $config['descriptionBlocks'] : [];
        $result = [];

        foreach ($locales as $locale) {
            $blocks = \is_array($blocksByLocale[$locale] ?? null) ? $blocksByLocale[$locale] : [];
            foreach ($blocks as $block) {
                if (!\is_array($block)) {
                    continue;
                }

                $type = (string) ($block['type'] ?? 'paragraph');
                if (
                    \in_array($type, ['heading', 'paragraph'], true)
                    && ($block['contentMode'] ?? null) === 'generated'
                ) {
                    $id = preg_replace('/[^a-zA-Z0-9_.-]/', '_', (string) ($block['id'] ?? '')) ?? '';
                    if ($id !== '') {
                        $result[$locale]['splac_block_' . $id] = [
                            'type' => $type,
                            'instruction' => (string) ($block['instruction'] ?? ''),
                        ];
                    }
                }

                if ($type !== 'table') {
                    continue;
                }

                $rows = \is_array($block['rows'] ?? null) ? $block['rows'] : [];
                foreach ($rows as $row) {
                    if (
                        !\is_array($row)
                        || ($row['mode'] ?? 'placeholder') === 'static'
                        || !\is_string($row['instruction'] ?? null)
                        || trim($row['instruction']) === ''
                    ) {
                        continue;
                    }

                    $placeholder = $this->normalizeDescriptionPlaceholder(
                        (string) ($row['placeholder'] ?? '') ?: (string) ($row['label'] ?? '')
                    );
                    if ($placeholder === '') {
                        continue;
                    }

                    $result[$locale][$placeholder] = [
                        'type' => 'tableValue',
                        'instruction' => trim($row['instruction']),
                    ];
                }
            }
        }

        return $result;
    }

    /**
     * @param list<string> $locales
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function runSeo(
        string $processId,
        TemplateEntity $template,
        array $locales,
        string $sourceText,
        string $productNameHint,
        array $input,
        CompletionOptions $completionOptions,
    ): array {
        $config = $template->getConfig() ?? [];
        $fieldModes = $config['fieldModes'] ?? [];

        $prompt = $this->promptBuilder->buildTextFieldsPrompt(
            \is_array($fieldModes) ? $fieldModes : [],
            ['metaTitle', 'metaDescription'],
            $locales,
            $productNameHint,
            \is_string($input['seoInstruction'] ?? null) ? $input['seoInstruction'] : null,
            $this->stringConfigValue($config, 'targetMarket'),
        );

        $data = $this->llmService->completeJson(
            $this->promptBuilder->buildSystemPrompt(),
            $prompt,
            $processId,
            self::STEP_SEO,
            $completionOptions,
            $sourceText,
        );
        $fields = \is_array($data['fields'] ?? null) ? $data['fields'] : [];

        $metaTitle = [];
        $metaDescription = [];
        foreach ($locales as $locale) {
            $localeFields = \is_array($fields[$locale] ?? null) ? $fields[$locale] : [];
            $metaTitle[$locale] = \is_string($localeFields['metaTitle'] ?? null) ? $localeFields['metaTitle'] : '';
            $metaDescription[$locale] = \is_string($localeFields['metaDescription'] ?? null) ? $localeFields['metaDescription'] : '';
        }

        return [
            'metaTitle' => $metaTitle,
            'metaDescription' => $metaDescription,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function runProperties(
        string $processId,
        TemplateEntity $template,
        string $sourceText,
        string $productNameHint,
        Context $context,
        CompletionOptions $completionOptions,
    ): array
    {
        $criteria = new Criteria();
        $criteria->addAssociation('options');
        $criteria->addSorting(new FieldSorting('name'));
        $criteria->setLimit(200);

        $groups = [];
        foreach ($this->propertyGroupRepository->search($criteria, $context)->getEntities() as $group) {
            $options = [];
            foreach ($group->getOptions() ?? [] as $option) {
                $options[] = [
                    'id' => $option->getId(),
                    'name' => (string) $option->getTranslation('name'),
                ];
            }

            if ($options === []) {
                continue;
            }

            $groups[] = [
                'groupId' => $group->getId(),
                'groupName' => (string) $group->getTranslation('name'),
                'options' => $options,
            ];
        }

        if ($groups === []) {
            return ['propertyOptionIds' => []];
        }

        $config = $template->getConfig() ?? [];
        $prompt = $this->promptBuilder->buildPropertiesPrompt(
            $groups,
            $productNameHint,
            $this->stringConfigValue($config, 'targetMarket'),
        );
        $data = $this->llmService->completeJson(
            $this->promptBuilder->buildSystemPrompt(),
            $prompt,
            $processId,
            self::STEP_PROPERTIES,
            $completionOptions,
            $sourceText,
        );

        $validIds = [];
        foreach ($groups as $group) {
            foreach ($group['options'] as $option) {
                $validIds[strtolower($option['id'])] = $option['id'];
            }
        }

        $selected = [];
        foreach (\is_array($data['optionIds'] ?? null) ? $data['optionIds'] : [] as $optionId) {
            if (\is_string($optionId) && isset($validIds[strtolower($optionId)])) {
                $selected[] = $validIds[strtolower($optionId)];
            }
        }

        return ['propertyOptionIds' => array_values(array_unique($selected))];
    }

    /**
     * @param list<string> $locales
     *
     * @return array<string, mixed>
     */
    private function runCategory(
        ProcessEntity $process,
        array $locales,
        string $sourceText,
        string $productNameHint,
        CompletionOptions $completionOptions,
    ): array {
        $categoryTemplate = $process->getCategoryTemplate();
        if ($categoryTemplate === null) {
            return [];
        }

        $prompt = $this->promptBuilder->buildCategoryPrompt(
            $categoryTemplate->getConfig() ?? [],
            $locales,
            $productNameHint,
            $this->stringConfigValue($process->getTemplate()?->getConfig() ?? [], 'targetMarket'),
        );

        $data = $this->llmService->completeJson(
            $this->promptBuilder->buildSystemPrompt(),
            $prompt,
            $process->getId(),
            self::STEP_CATEGORY,
            $completionOptions,
            $sourceText,
        );

        return [
            'category' => [
                'name' => $this->localeMap($data['name'] ?? [], $locales),
                'description' => $this->localeMap($data['description'] ?? [], $locales),
                'metaTitle' => $this->localeMap($data['metaTitle'] ?? [], $locales),
                'metaDescription' => $this->localeMap($data['metaDescription'] ?? [], $locales),
                'keywords' => $this->localeMap($data['keywords'] ?? [], $locales),
                'parentCategoryId' => $categoryTemplate->getParentCategoryId(),
            ],
        ];
    }

    /**
     * @param mixed $value
     * @param list<string> $locales
     *
     * @return array<string, string>
     */
    private function localeMap(mixed $value, array $locales): array
    {
        $map = [];
        foreach ($locales as $locale) {
            $entry = \is_array($value) ? ($value[$locale] ?? '') : '';
            $map[$locale] = \is_string($entry) ? $entry : '';
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function stringConfigValue(array $config, string $key): ?string
    {
        $value = $config[$key] ?? null;

        return \is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function normalizeDescriptionPlaceholder(string $value): string
    {
        $value = strtr(trim($value), [
            'ß' => 'ss',
            'Æ' => 'AE',
            'æ' => 'ae',
            'Ø' => 'O',
            'ø' => 'o',
        ]);

        if (class_exists(\Normalizer::class)) {
            $value = \Normalizer::normalize($value, \Normalizer::FORM_KD) ?: $value;
            $value = preg_replace('/\p{Mn}+/u', '', $value) ?? $value;
        }

        $value = preg_replace('/\s+/', '_', $value) ?? $value;

        return preg_replace('/[^a-zA-Z0-9_.-]/', '', $value) ?? '';
    }
}
