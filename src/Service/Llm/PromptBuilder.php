<?php declare(strict_types=1);

namespace Splac\Service\Llm;

/**
 * Builds the prompts for every generation step. All prompts share the same
 * ground rule: only facts present in the provided sources may be used and
 * fields must stay empty when the information cannot be found.
 */
class PromptBuilder
{
    private const LOCALE_NAMES = [
        'de-DE' => 'German',
        'en-GB' => 'English',
    ];

    public function buildSystemPrompt(): string
    {
        return <<<'PROMPT'
You are Splac, an assistant that prepares product listings for a Shopware online shop.

STRICT RULES:
- Only use facts that are explicitly present in the provided source documents or user inputs.
- Never invent, guess or extrapolate technical specifications, identifiers or facts.
- If a piece of information cannot be found in the sources, return an empty string "" (or an empty array) for that field.
- Always answer with a single valid JSON object and nothing else. No markdown, no explanations.
PROMPT;
    }

    /**
     * Adds generated table placeholders that were omitted by older versions
     * of the administration template editor.
     *
     * @param array<string, string> $descriptionTemplates
     * @param array<string, mixed> $templateConfig
     * @param list<string> $locales
     *
     * @return array<string, string>
     */
    public function prepareDescriptionTemplates(
        array $descriptionTemplates,
        array $templateConfig,
        array $locales,
    ): array {
        $blocksByLocale = \is_array($templateConfig['descriptionBlocks'] ?? null)
            ? $templateConfig['descriptionBlocks']
            : [];

        foreach ($locales as $locale) {
            $html = $descriptionTemplates[$locale] ?? null;
            $blocks = $blocksByLocale[$locale] ?? null;
            if (!\is_string($html) || !\is_array($blocks)) {
                continue;
            }

            foreach ($blocks as $block) {
                if (!\is_array($block) || ($block['type'] ?? null) !== 'table') {
                    continue;
                }

                $rows = \is_array($block['rows'] ?? null) ? $block['rows'] : [];
                foreach ($rows as $row) {
                    if (!\is_array($row) || ($row['mode'] ?? 'placeholder') === 'static') {
                        continue;
                    }

                    $label = trim((string) ($row['label'] ?? ''));
                    $placeholder = $this->normalizePlaceholder(
                        (string) ($row['placeholder'] ?? '') ?: $label
                    );
                    if ($label === '' || $placeholder === '') {
                        continue;
                    }

                    $escapedLabel = htmlspecialchars($label, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
                    $emptyRow = "<tr><th>{$escapedLabel}</th><td></td></tr>";
                    $generatedRow = "<tr><th>{$escapedLabel}</th><td>{{"
                        . $placeholder
                        . '}}</td></tr>';
                    $html = str_replace($emptyRow, $generatedRow, $html);
                }
            }

            $descriptionTemplates[$locale] = $html;
        }

        return $descriptionTemplates;
    }

    /**
     * @param array<string, string> $descriptionTemplates locale => HTML template with {{placeholders}}
     * @param list<string> $locales
     * @param array<string, array<string, array{type: string, instruction: string}>> $generatedBlocks
     */
    public function buildDescriptionPrompt(
        array $descriptionTemplates,
        array $locales,
        array $generatedBlocks,
        string $sourceText,
        string $productNameHint,
        ?string $userInstruction,
    ): string {
        $filteredTemplates = $this->filterLocales($descriptionTemplates, $locales);
        $placeholders = [];
        foreach ($filteredTemplates as $html) {
            preg_match_all('/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/', $html, $matches);
            $placeholders = array_merge($placeholders, $matches[1]);
        }
        $placeholders = array_values(array_unique($placeholders));

        $templatesJson = json_encode($filteredTemplates, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        $generatedBlocksJson = json_encode($this->filterLocales($generatedBlocks, $locales), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        $generatedBlockRules = $generatedBlocks !== []
            ? "\nSome placeholders represent complete generated blocks. For those placeholders, write the complete block content according to its instruction. Do not include the surrounding heading or paragraph HTML tag; it is already present in the template:\n{$generatedBlocksJson}\n"
            : '';
        $placeholderList = implode(', ', $placeholders);
        $localeList = $this->describeLocales($locales);
        $instruction = $userInstruction !== null && $userInstruction !== ''
            ? "\nAdditional user instruction: " . $userInstruction
            : '';

        return <<<PROMPT
Task: Fill the placeholders of an HTML product description template.

Product: {$productNameHint}{$instruction}

The description templates per language (JSON, locale => HTML). Everything that is NOT a {{placeholder}} must remain byte-for-byte unchanged (static blocks like legal disclaimers, shipping info, driver links):
{$templatesJson}
{$generatedBlockRules}

Placeholders to fill: {$placeholderList}

Return a JSON object of this shape:
{"placeholders": {"<locale>": {"<placeholder>": "<value>"}}}

Fill the values in the correct language for each locale ({$localeList}).
Placeholder values may contain simple inline HTML (e.g. <br>, <strong>) when appropriate for table cells or paragraphs.
If a placeholder's information is not present in the sources, use an empty string.

SOURCE DOCUMENTS:
{$sourceText}
PROMPT;
    }

    /**
     * @param array<string, mixed> $fieldModes config of the text field modes
     * @param list<string> $locales
     * @param list<string> $fields e.g. ["metaTitle", "metaDescription"]
     */
    public function buildTextFieldsPrompt(
        array $fieldModes,
        array $fields,
        array $locales,
        string $sourceText,
        string $productNameHint,
        ?string $userInstruction,
    ): string {
        $fieldSpecs = [];
        foreach ($fields as $field) {
            $mode = $fieldModes[$field] ?? ['mode' => 'instruction'];
            $modeType = $mode['mode'] ?? 'instruction';

            if ($modeType === 'placeholder') {
                $texts = [];
                foreach ($locales as $locale) {
                    $texts[$locale] = (string) ($mode[$locale] ?? '');
                }
                $fieldSpecs[] = \sprintf(
                    "- \"%s\": PLACEHOLDER MODE. Take the following text per locale and ONLY replace the [tokens] in square brackets with fitting values; keep all other words exactly as written: %s",
                    $field,
                    json_encode($texts, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)
                );
            } else {
                $instructionText = '';
                foreach ($locales as $locale) {
                    if (!empty($mode[$locale])) {
                        $instructionText .= \sprintf(' [%s: %s]', $locale, $mode[$locale]);
                    }
                }
                $fieldSpecs[] = \sprintf(
                    '- "%s": INSTRUCTION MODE. Write the text yourself following this instruction:%s',
                    $field,
                    $instructionText !== '' ? $instructionText : ' (no specific instruction, use sensible e-commerce best practice)'
                );
            }
        }

        $fieldSpecList = implode("\n", $fieldSpecs);
        $localeList = $this->describeLocales($locales);
        $fieldList = implode('", "', $fields);
        $extra = $userInstruction !== null && $userInstruction !== ''
            ? "\nAdditional user instruction: " . $userInstruction
            : '';

        return <<<PROMPT
Task: Generate SEO/marketing text fields for a product listing.

Product: {$productNameHint}{$extra}

Fields and their generation mode:
{$fieldSpecList}

Guidelines: metaTitle should be at most 60 characters, metaDescription at most 160 characters and encourage the click on Google.

Return a JSON object of this shape:
{"fields": {"<locale>": {"{$fieldList}": "<value>"}}}

Generate each field in the correct language for each locale ({$localeList}).

SOURCE DOCUMENTS:
{$sourceText}
PROMPT;
    }

    /**
     * @param list<array{groupId: string, groupName: string, options: list<array{id: string, name: string}>}> $propertyGroups
     */
    public function buildPropertiesPrompt(array $propertyGroups, string $sourceText, string $productNameHint): string
    {
        $groupsJson = json_encode($propertyGroups, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

        return <<<PROMPT
Task: Select the matching property options for a product from the shop's existing properties.

Product: {$productNameHint}

Available property groups and options (you may ONLY pick option ids from this list, never invent ids):
{$groupsJson}

Rules:
- Pick an option only when the sources clearly confirm it applies to the product.
- Groups that do not apply to this product type must simply not appear in the result.

Return a JSON object of this shape:
{"optionIds": ["<option id>", "..."]}

SOURCE DOCUMENTS:
{$sourceText}
PROMPT;
    }

    /**
     * @param list<array{id: string, name: string}> $manufacturers
     * @param list<string> $locales
     */
    public function buildClassificationPrompt(
        array $manufacturers,
        array $locales,
        string $sourceText,
        string $productNameHint,
        ?string $productNumberPattern,
    ): string {
        $manufacturersJson = json_encode($manufacturers, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        $localeList = $this->describeLocales($locales);
        $patternInfo = $productNumberPattern !== null && $productNumberPattern !== ''
            ? \sprintf('Follow this pattern: "%s" where {model} is replaced by a short slug of the product model (uppercase letters, digits, dashes only).', $productNumberPattern)
            : 'Build it as a short, unique, human-readable slug of manufacturer and model (uppercase letters, digits, dashes only).';

        return <<<PROMPT
Task: Extract identifiers and classification data for a product listing.

Product: {$productNameHint}

1. "productName": the exact full product name per locale ({$localeList}), based on the sources.
2. "manufacturerId": the id of the matching manufacturer from this list of existing shop manufacturers, or "" if none matches: {$manufacturersJson}
3. "manufacturerName": the manufacturer name as stated in the sources (used to create a new manufacturer when no id matched).
4. "ean": the EAN/GTIN of the product, digits only. Empty string if not present in the sources.
5. "manufacturerNumber": the manufacturer part number (MPN). Empty string if not present in the sources.
6. "productNumber": a proposed product number. {$patternInfo}
7. "tags": 3 to 8 short tags (single words or short phrases) describing the product, in the shop's primary language.
8. "keywords": search keywords per locale as a single comma-separated string, e.g. {"de-DE": "laptop, notebook", "en-GB": "laptop, notebook"}.

Return a JSON object of this shape:
{"productName": {"<locale>": ""}, "manufacturerId": "", "manufacturerName": "", "ean": "", "manufacturerNumber": "", "productNumber": "", "tags": [], "keywords": {"<locale>": ""}}

SOURCE DOCUMENTS:
{$sourceText}
PROMPT;
    }

    /**
     * @param array<string, mixed> $categoryTemplateConfig
     * @param list<string> $locales
     */
    public function buildCategoryPrompt(
        array $categoryTemplateConfig,
        array $locales,
        string $sourceText,
        string $productNameHint,
    ): string {
        $nameSpec = $this->textModeSpec($categoryTemplateConfig['name'] ?? [], $locales, 'category name');
        $descriptionSpec = $this->textModeSpec($categoryTemplateConfig['description'] ?? [], $locales, 'category description');
        $localeList = $this->describeLocales($locales);

        return <<<PROMPT
Task: Create a new shop category that fits the product.

Product: {$productNameHint}

Category name: {$nameSpec}
Category description: {$descriptionSpec}

Return a JSON object of this shape:
{"name": {"<locale>": ""}, "description": {"<locale>": ""}}

Generate in the correct language for each locale ({$localeList}).

SOURCE DOCUMENTS:
{$sourceText}
PROMPT;
    }

    /**
     * @param array<string, mixed> $mode
     * @param list<string> $locales
     */
    private function textModeSpec(array $mode, array $locales, string $what): string
    {
        $modeType = $mode['mode'] ?? 'instruction';

        if ($modeType === 'placeholder') {
            $texts = [];
            foreach ($locales as $locale) {
                $texts[$locale] = (string) ($mode[$locale] ?? '');
            }

            return \sprintf(
                'PLACEHOLDER MODE. Take the following text per locale and ONLY replace the [tokens] in square brackets, keep all other words exactly as written: %s',
                json_encode($texts, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)
            );
        }

        $instructionText = '';
        foreach ($locales as $locale) {
            if (!empty($mode[$locale])) {
                $instructionText .= \sprintf(' [%s: %s]', $locale, $mode[$locale]);
            }
        }

        return \sprintf(
            'INSTRUCTION MODE. Write the %s yourself following this instruction:%s',
            $what,
            $instructionText !== '' ? $instructionText : ' (no specific instruction)'
        );
    }

    /**
     * @param array<string, string> $templates
     * @param list<string> $locales
     *
     * @return array<string, string>
     */
    private function filterLocales(array $templates, array $locales): array
    {
        $filtered = array_intersect_key($templates, array_flip($locales));

        return $filtered !== [] ? $filtered : $templates;
    }

    private function normalizePlaceholder(string $value): string
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

    /**
     * @param list<string> $locales
     */
    private function describeLocales(array $locales): string
    {
        $parts = [];
        foreach ($locales as $locale) {
            $parts[] = \sprintf('%s = %s', $locale, self::LOCALE_NAMES[$locale] ?? $locale);
        }

        return implode(', ', $parts);
    }
}
