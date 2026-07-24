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
You are Splac, a meticulous e-commerce editor that prepares trustworthy, easy-to-scan product listings for a Shopware online shop.

SOURCE GROUNDING:
- Treat the supplied source documents and explicit user inputs as the only factual authority for this exact product.
- Never invent, guess, merge variants, or extrapolate specifications, compatibility, certifications, identifiers, included items, or performance claims.
- You may turn a supported feature into its direct, conservative customer benefit, but do not promise an outcome that the sources do not support.
- When sources are incomplete or conflicting, prefer an empty value over an uncertain claim. Do not silently resolve ambiguity.
- Use an empty string "" or empty array for missing factual data unless the task explicitly asks you to create a proposed value or grounded marketing copy.

EDITORIAL QUALITY:
- Write concise, specific, natural copy in the requested locale. Preserve product names, trademarks, identifiers, and units accurately.
- Prioritize useful information and concrete customer value. Avoid filler, repetition, keyword stuffing, hype, and unsupported superlatives.
- Keep technical detail in the field intended for it; do not repeat the same information across prose, tables, and metadata unless the task requires it.

OUTPUT CONTRACT:
- Follow the task-specific schema and instructions exactly.
- Return one valid JSON object and nothing else: no markdown fences, commentary, or additional keys.
- Properly JSON-escape quotation marks, backslashes, control characters, and line breaks inside string values.
PROMPT;
    }

    /**
     * Rebuilds configured description blocks as semantic, readable HTML.
     * Legacy raw-HTML templates remain unchanged.
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
        $descriptionStyle = \is_array($templateConfig['descriptionStyle'] ?? null)
            ? $templateConfig['descriptionStyle']
            : [];
        $configuredBlockSpacing = $this->clampInteger(
            $descriptionStyle['blockSpacing'] ?? null,
            0,
            200,
            16
        );
        $blockSpacingEnabled = \is_bool($descriptionStyle['blockSpacingEnabled'] ?? null)
            ? $descriptionStyle['blockSpacingEnabled']
            : (\is_numeric($descriptionStyle['blockSpacing'] ?? null) && $configuredBlockSpacing > 0);
        $blockSpacing = $blockSpacingEnabled ? $configuredBlockSpacing : 0;
        $tableFormattingEnabled = ($descriptionStyle['tableFormattingEnabled'] ?? true) !== false;

        foreach ($locales as $locale) {
            $blocks = $blocksByLocale[$locale] ?? null;
            if (\is_array($blocks) && $blocks !== []) {
                $descriptionTemplates[$locale] = $this->renderDescriptionBlocks(
                    $blocks,
                    $blockSpacing,
                    $tableFormattingEnabled,
                );
            }
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
            ? "\nSome placeholders represent complete generated blocks. Follow each block's instruction while also applying the content rules below. A heading value must be plain text. A paragraph value may use the allowed inner HTML, but must not include its surrounding heading or paragraph tag because that tag already exists in the template:\n{$generatedBlocksJson}\n"
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

CONTENT RULES:
- Treat specification tables as the quick-reference source of truth for exact product data.
- Table-cell placeholders must be compact and scannable: normally only the value, unit, and a short qualifier. Do not repeat the row label, write full sentences, add sales language, or combine unrelated specifications.
- Use plain text for a single table value. Use <br> for two closely related values, or <ul><li>...</li></ul> only when three or more distinct items genuinely benefit from a list. Never return a nested <table>.
- Descriptive headings and paragraphs must focus on what makes the product distinctive, its practical advantages, and the result or experience a customer can expect from using it.
- By default, keep exact specifications (measurements, capacities, model-number lists, clock rates, resolutions, standards, and similar catalogue data) out of descriptive prose when a table placeholder covers them. Mention a feature category only when needed to explain a customer benefit.
- Do not turn the description into a prose version of the table. Avoid repeated claims, generic introductions, empty praise, and unsupported superlatives.
- Prefer short paragraphs with one clear idea each. Use <strong> sparingly for a genuinely useful scan point and <ul><li>...</li></ul> for three or more parallel benefits; do not add presentational styling, classes, or attributes.
- Keep all HTML semantic and minimal. Allowed placeholder HTML is <br>, <strong>, <em>, <ul>, <ol>, and <li>. Do not include document wrappers, scripts, styles, headings, paragraphs, or tables inside placeholder values.
- A specific template block instruction or additional user instruction may request a different emphasis or exact detail, but it never permits unsupported facts.

Return a JSON object of this shape:
{"placeholders": {"<locale>": {"<placeholder>": "<value>"}}}

Fill the values in the correct language for each locale ({$localeList}).
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
     * @param list<mixed> $blocks
     */
    private function renderDescriptionBlocks(
        array $blocks,
        int $blockSpacing,
        bool $tableFormattingEnabled,
    ): string
    {
        $html = [];
        $blockStyle = $blockSpacing > 0
            ? ' style="margin-bottom: ' . $blockSpacing . 'px;"'
            : '';

        foreach ($blocks as $block) {
            if (!\is_array($block)) {
                continue;
            }

            $type = (string) ($block['type'] ?? 'paragraph');
            $contentMode = (string) ($block['contentMode'] ?? 'static');
            $content = $contentMode === 'generated'
                ? '{{' . $this->generatedBlockPlaceholder($block) . '}}'
                : (string) ($block['content'] ?? '');

            if ($type === 'heading') {
                $level = \in_array($block['level'] ?? null, ['h2', 'h3', 'h4'], true)
                    ? (string) $block['level']
                    : 'h2';
                $value = $contentMode === 'generated' ? $content : $this->escapeHtml($content);
                $html[] = "<{$level}{$blockStyle}>{$value}</{$level}>";
                continue;
            }

            if ($type === 'paragraph') {
                $value = $contentMode === 'generated'
                    ? $content
                    : str_replace(["\r\n", "\r", "\n"], '<br>', $this->escapeHtml($content));
                $html[] = "<p{$blockStyle}>{$value}</p>";
                continue;
            }

            if ($type === 'table') {
                $html[] = $this->renderDescriptionTable(
                    $block,
                    $blockSpacing,
                    $tableFormattingEnabled,
                );
                continue;
            }

            // Compatibility blocks preserve their hand-authored HTML. When
            // spacing is explicitly enabled, a separate spacer follows it.
            $legacyHtml = (string) ($block['content'] ?? '');
            if ($legacyHtml !== '' && $blockSpacing > 0) {
                $legacyHtml .= "\n<div aria-hidden=\"true\" style=\"height: {$blockSpacing}px;\"></div>";
            }
            $html[] = $legacyHtml;
        }

        return implode("\n", $html);
    }

    /**
     * @param array<string, mixed> $block
     */
    private function renderDescriptionTable(
        array $block,
        int $blockSpacing,
        bool $tableFormattingEnabled,
    ): string
    {
        if (!$tableFormattingEnabled) {
            return $this->renderUnformattedDescriptionTable($block, $blockSpacing);
        }

        $style = $this->normalizeTableStyle(
            \is_array($block['tableStyle'] ?? null) ? $block['tableStyle'] : []
        );
        $rightColumnWidth = 100 - $style['leftColumnWidth'];
        $collapse = $style['cellSpacing'] > 0 ? 'separate' : 'collapse';
        $tableDeclarations = [
            'width: 100%;',
            'table-layout: fixed;',
            'border-collapse: ' . $collapse . ';',
            'border-spacing: ' . $style['cellSpacing'] . 'px;',
        ];
        if ($blockSpacing > 0) {
            $tableDeclarations[] = 'margin-bottom: ' . $blockSpacing . 'px;';
        }

        $border = $style['borderStyle'] === 'none' || $style['borderWidth'] === 0
            ? 'none'
            : $style['borderWidth'] . 'px ' . $style['borderStyle'] . ' ' . $style['borderColor'];
        $headerCellStyle = $this->cellStyleDeclarations(
            $border,
            $style['paddingVertical'],
            $style['paddingHorizontal'],
            $style['headerAlignment'],
            $style['verticalAlignment'],
            $style['labelBackgroundColor'],
        );
        $valueCellStyle = $this->cellStyleDeclarations(
            $border,
            $style['paddingVertical'],
            $style['paddingHorizontal'],
            $style['valueAlignment'],
            $style['verticalAlignment'],
            $style['valueBackgroundColor'],
        );

        $lines = ['<table', '    style="'];
        foreach ($tableDeclarations as $declaration) {
            $lines[] = '        ' . $declaration;
        }
        $lines[] = '    "';
        $lines[] = '>';

        $title = (string) ($block['title'] ?? '');
        if ($title !== '') {
            $lines[] = '    <caption style="caption-side: top; padding: 0 0 10px; text-align: left; font-weight: 600;">'
                . $this->escapeHtml($title)
                . '</caption>';
        }
        $lines[] = '    <colgroup>';
        $lines[] = '        <col style="width: ' . $style['leftColumnWidth'] . '%;">';
        $lines[] = '        <col style="width: ' . $rightColumnWidth . '%;">';
        $lines[] = '    </colgroup>';
        $lines[] = '    <tbody>';

        $rows = \is_array($block['rows'] ?? null) ? $block['rows'] : [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $label = (string) ($row['label'] ?? '');
            if (($row['mode'] ?? 'placeholder') === 'static') {
                $value = str_replace(
                    ["\r\n", "\r", "\n"],
                    '<br>',
                    $this->escapeHtml((string) ($row['content'] ?? ''))
                );
            } else {
                $placeholder = $this->normalizePlaceholder(
                    (string) ($row['placeholder'] ?? '') ?: $label
                );
                $value = $placeholder !== '' ? '{{' . $placeholder . '}}' : '';
            }

            $lines[] = '        <tr>';
            $lines[] = '            <th';
            $lines[] = '                scope="row"';
            $lines[] = '                style="';
            foreach ($headerCellStyle as $declaration) {
                $lines[] = '                    ' . $declaration;
            }
            $lines[] = '                "';
            $lines[] = '            >';
            $lines[] = '                ' . $this->escapeHtml($label);
            $lines[] = '            </th>';
            $lines[] = '            <td';
            $lines[] = '                style="';
            foreach ($valueCellStyle as $declaration) {
                $lines[] = '                    ' . $declaration;
            }
            $lines[] = '                "';
            $lines[] = '            >';
            $lines[] = '                ' . $value;
            $lines[] = '            </td>';
            $lines[] = '        </tr>';
        }

        $lines[] = '    </tbody>';
        $lines[] = '</table>';

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $block
     */
    private function renderUnformattedDescriptionTable(array $block, int $blockSpacing): string
    {
        $tableStyle = $blockSpacing > 0
            ? ' style="margin-bottom: ' . $blockSpacing . 'px;"'
            : '';
        $lines = ['<table' . $tableStyle . '>'];

        $title = (string) ($block['title'] ?? '');
        if ($title !== '') {
            $lines[] = '    <caption>' . $this->escapeHtml($title) . '</caption>';
        }
        $lines[] = '    <tbody>';

        $rows = \is_array($block['rows'] ?? null) ? $block['rows'] : [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $label = (string) ($row['label'] ?? '');
            if (($row['mode'] ?? 'placeholder') === 'static') {
                $value = str_replace(
                    ["\r\n", "\r", "\n"],
                    '<br>',
                    $this->escapeHtml((string) ($row['content'] ?? ''))
                );
            } else {
                $placeholder = $this->normalizePlaceholder(
                    (string) ($row['placeholder'] ?? '') ?: $label
                );
                $value = $placeholder !== '' ? '{{' . $placeholder . '}}' : '';
            }

            $lines[] = '        <tr>';
            $lines[] = '            <th scope="row">' . $this->escapeHtml($label) . '</th>';
            $lines[] = '            <td>' . $value . '</td>';
            $lines[] = '        </tr>';
        }

        $lines[] = '    </tbody>';
        $lines[] = '</table>';

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $style
     *
     * @return array{
     *     borderStyle: string,
     *     borderWidth: int,
     *     borderColor: string,
     *     leftColumnWidth: int,
     *     headerAlignment: string,
     *     valueAlignment: string,
     *     verticalAlignment: string,
     *     paddingVertical: int,
     *     paddingHorizontal: int,
     *     cellSpacing: int,
     *     labelBackgroundColor: string,
     *     valueBackgroundColor: string
     * }
     */
    private function normalizeTableStyle(array $style): array
    {
        $borderColor = (string) ($style['borderColor'] ?? '');
        if (preg_match('/^#[0-9a-f]{6}$/i', $borderColor) !== 1) {
            $borderColor = '#d9e0e8';
        }

        $labelBackgroundColor = (string) ($style['labelBackgroundColor'] ?? '');
        if (preg_match('/^#[0-9a-f]{6}$/i', $labelBackgroundColor) !== 1) {
            $labelBackgroundColor = '#ffffff';
        }

        $valueBackgroundColor = (string) ($style['valueBackgroundColor'] ?? '');
        if (preg_match('/^#[0-9a-f]{6}$/i', $valueBackgroundColor) !== 1) {
            $valueBackgroundColor = '#ffffff';
        }

        return [
            'borderStyle' => $this->enumValue(
                $style['borderStyle'] ?? null,
                ['none', 'solid', 'dashed', 'dotted', 'double'],
                'solid'
            ),
            'borderWidth' => $this->clampInteger($style['borderWidth'] ?? null, 0, 10, 1),
            'borderColor' => $borderColor,
            'leftColumnWidth' => $this->clampInteger($style['leftColumnWidth'] ?? null, 10, 90, 30),
            'headerAlignment' => $this->enumValue(
                $style['headerAlignment'] ?? null,
                ['left', 'center', 'right'],
                'left'
            ),
            'valueAlignment' => $this->enumValue(
                $style['valueAlignment'] ?? null,
                ['left', 'center', 'right'],
                'left'
            ),
            'verticalAlignment' => $this->enumValue(
                $style['verticalAlignment'] ?? null,
                ['top', 'middle', 'bottom'],
                'middle'
            ),
            'paddingVertical' => $this->clampInteger($style['paddingVertical'] ?? null, 0, 50, 10),
            'paddingHorizontal' => $this->clampInteger($style['paddingHorizontal'] ?? null, 0, 80, 12),
            'cellSpacing' => $this->clampInteger($style['cellSpacing'] ?? null, 0, 30, 0),
            'labelBackgroundColor' => $labelBackgroundColor,
            'valueBackgroundColor' => $valueBackgroundColor,
        ];
    }

    /**
     * @return list<string>
     */
    private function cellStyleDeclarations(
        string $border,
        int $paddingVertical,
        int $paddingHorizontal,
        string $horizontalAlignment,
        string $verticalAlignment,
        string $backgroundColor,
    ): array {
        return [
            'border: ' . $border . ';',
            'padding: ' . $paddingVertical . 'px ' . $paddingHorizontal . 'px;',
            'text-align: ' . $horizontalAlignment . ';',
            'vertical-align: ' . $verticalAlignment . ';',
            'background-color: ' . $backgroundColor . ';',
            'overflow-wrap: anywhere;',
        ];
    }

    /**
     * @param list<string> $allowed
     */
    private function enumValue(mixed $value, array $allowed, string $fallback): string
    {
        return \is_string($value) && \in_array($value, $allowed, true) ? $value : $fallback;
    }

    private function clampInteger(mixed $value, int $minimum, int $maximum, int $fallback): int
    {
        if (!\is_numeric($value)) {
            return $fallback;
        }

        return min($maximum, max($minimum, (int) round((float) $value)));
    }

    /**
     * @param array<string, mixed> $block
     */
    private function generatedBlockPlaceholder(array $block): string
    {
        $id = preg_replace('/[^a-zA-Z0-9_.-]/', '_', (string) ($block['id'] ?? '')) ?? '';

        return 'splac_block_' . $id;
    }

    private function escapeHtml(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
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
