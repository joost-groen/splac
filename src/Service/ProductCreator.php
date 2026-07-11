<?php declare(strict_types=1);

namespace Splac\Service;

use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\Tax\TaxEntity;
use Splac\Core\Content\Process\ProcessEntity;

/**
 * Creates the final (inactive) product from the reviewed process output.
 */
class ProductCreator
{
    public function __construct(
        private readonly EntityRepository $productRepository,
        private readonly EntityRepository $categoryRepository,
        private readonly EntityRepository $manufacturerRepository,
        private readonly EntityRepository $tagRepository,
        private readonly EntityRepository $taxRepository,
        private readonly EntityRepository $languageRepository,
        private readonly ProductNumberGenerator $productNumberGenerator,
    ) {
    }

    /**
     * @return string the created product id
     */
    public function create(ProcessEntity $process, Context $context): string
    {
        $input = $process->getInput() ?? [];
        $output = $process->getOutput() ?? [];

        $productId = Uuid::randomHex();

        $taxId = (string) ($input['taxId'] ?? '');
        if ($taxId === '') {
            throw new \RuntimeException('No tax selected for this process');
        }
        $tax = $this->loadTax($taxId, $context);

        $gross = (float) ($input['price'] ?? 0.0);
        $net = $this->grossToNet($gross, $tax->getTaxRate());

        $languageMap = $this->buildLanguageMap($context);

        $productNumber = (string) ($output['productNumber'] ?? '');
        if ($productNumber === '') {
            $productNumber = (string) ($input['productName'] ?? 'SPLAC');
        }
        // Guarantee uniqueness even when the number was edited during review.
        $productNumber = $this->productNumberGenerator->makeUnique($productNumber, $context);

        $payload = [
            'id' => $productId,
            'productNumber' => $productNumber,
            'active' => false,
            'stock' => (int) ($input['stock'] ?? 0),
            'taxId' => $taxId,
            'price' => [[
                'currencyId' => Defaults::CURRENCY,
                'gross' => $gross,
                'net' => $net,
                'linked' => true,
            ]],
        ];

        $translations = $this->buildTranslations($output, $languageMap);
        if ($translations !== []) {
            $payload['translations'] = $translations;
        } else {
            $payload['name'] = (string) ($input['productName'] ?? $process->getName());
        }

        $ean = (string) ($output['ean'] ?? '');
        if ($ean !== '') {
            $payload['ean'] = $ean;
        }

        $manufacturerNumber = (string) ($output['manufacturerNumber'] ?? '');
        if ($manufacturerNumber !== '') {
            $payload['manufacturerNumber'] = $manufacturerNumber;
        }

        $manufacturerId = $this->resolveManufacturer($output, $context);
        if ($manufacturerId !== null) {
            $payload['manufacturerId'] = $manufacturerId;
        }

        $categoryId = $this->resolveCategory($input, $output, $languageMap, $context);
        if ($categoryId !== null) {
            $payload['categories'] = [['id' => $categoryId]];
        }

        $visibilities = [];
        foreach ((array) ($input['salesChannelIds'] ?? []) as $salesChannelId) {
            if (\is_string($salesChannelId) && Uuid::isValid($salesChannelId)) {
                $visibilities[] = [
                    'salesChannelId' => $salesChannelId,
                    'visibility' => 30,
                ];
            }
        }
        if ($visibilities !== []) {
            $payload['visibilities'] = $visibilities;
        }

        $propertyIds = [];
        foreach ((array) ($output['propertyOptionIds'] ?? []) as $optionId) {
            if (\is_string($optionId) && Uuid::isValid($optionId)) {
                $propertyIds[] = ['id' => $optionId];
            }
        }
        if ($propertyIds !== []) {
            $payload['properties'] = $propertyIds;
        }

        $tags = $this->resolveTags($output, $context);
        if ($tags !== []) {
            $payload['tags'] = $tags;
        }

        $advancedPrices = $this->buildAdvancedPrices($input, $tax);
        if ($advancedPrices !== []) {
            $payload['prices'] = $advancedPrices;
        }

        $this->productRepository->create([$payload], $context);

        return $productId;
    }

    private function loadTax(string $taxId, Context $context): TaxEntity
    {
        /** @var TaxEntity|null $tax */
        $tax = $this->taxRepository->search(new Criteria([$taxId]), $context)->first();
        if ($tax === null) {
            throw new \RuntimeException('Selected tax was not found');
        }

        return $tax;
    }

    private function grossToNet(float $gross, float $taxRate): float
    {
        return round($gross / (1 + $taxRate / 100), 2);
    }

    /**
     * @return array<string, string> locale => languageId
     */
    private function buildLanguageMap(Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addAssociation('locale');

        $map = [];
        foreach ($this->languageRepository->search($criteria, $context)->getEntities() as $language) {
            $locale = $language->getLocale();
            if ($locale !== null) {
                $map[$locale->getCode()] = $language->getId();
            }
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $output
     * @param array<string, string> $languageMap
     *
     * @return array<string, array<string, mixed>>
     */
    private function buildTranslations(array $output, array $languageMap): array
    {
        $translations = [];

        $locales = array_unique(array_merge(
            array_keys((array) ($output['productName'] ?? [])),
            array_keys((array) ($output['description'] ?? [])),
        ));

        foreach ($locales as $locale) {
            $languageId = $languageMap[$locale] ?? null;
            if ($languageId === null) {
                continue;
            }

            $translation = [];

            $name = $output['productName'][$locale] ?? '';
            if (\is_string($name) && $name !== '') {
                $translation['name'] = $name;
            }

            $description = $output['description'][$locale] ?? '';
            if (\is_string($description) && $description !== '') {
                $translation['description'] = $description;
            }

            $metaTitle = $output['metaTitle'][$locale] ?? '';
            if (\is_string($metaTitle) && $metaTitle !== '') {
                $translation['metaTitle'] = $metaTitle;
            }

            $metaDescription = $output['metaDescription'][$locale] ?? '';
            if (\is_string($metaDescription) && $metaDescription !== '') {
                $translation['metaDescription'] = $metaDescription;
            }

            $keywords = $output['keywords'][$locale] ?? '';
            if (\is_string($keywords) && $keywords !== '') {
                $translation['keywords'] = $keywords;
                $translation['customSearchKeywords'] = array_values(array_filter(array_map(
                    'trim',
                    explode(',', $keywords)
                )));
            }

            if ($translation !== []) {
                $translations[$languageId] = $translation;
            }
        }

        // The system default language must always have a name.
        $systemLanguageId = Defaults::LANGUAGE_SYSTEM;
        if (!isset($translations[$systemLanguageId]['name'])) {
            $fallbackName = '';
            foreach ((array) ($output['productName'] ?? []) as $name) {
                if (\is_string($name) && $name !== '') {
                    $fallbackName = $name;
                    break;
                }
            }
            if ($fallbackName !== '') {
                $translations[$systemLanguageId] ??= [];
                $translations[$systemLanguageId]['name'] = $fallbackName;
            }
        }

        return $translations;
    }

    /**
     * @param array<string, mixed> $output
     */
    private function resolveManufacturer(array $output, Context $context): ?string
    {
        $manufacturerId = (string) ($output['manufacturerId'] ?? '');
        if ($manufacturerId !== '' && Uuid::isValid($manufacturerId)) {
            return $manufacturerId;
        }

        $name = trim((string) ($output['manufacturerName'] ?? ''));
        if ($name === '') {
            return null;
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', $name));
        $criteria->setLimit(1);
        $existing = $this->manufacturerRepository->searchIds($criteria, $context)->firstId();
        if ($existing !== null) {
            return $existing;
        }

        $newId = Uuid::randomHex();
        $this->manufacturerRepository->create([[
            'id' => $newId,
            'name' => $name,
        ]], $context);

        return $newId;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $output
     * @param array<string, string> $languageMap
     */
    private function resolveCategory(array $input, array $output, array $languageMap, Context $context): ?string
    {
        $categoryId = (string) ($input['categoryId'] ?? '');
        if (($input['categoryMode'] ?? 'existing') === 'existing') {
            return $categoryId !== '' && Uuid::isValid($categoryId) ? $categoryId : null;
        }

        $generated = $output['category'] ?? null;
        if (!\is_array($generated)) {
            return null;
        }

        $translations = [];
        foreach ((array) ($generated['name'] ?? []) as $locale => $name) {
            $languageId = $languageMap[$locale] ?? null;
            if ($languageId === null || !\is_string($name) || $name === '') {
                continue;
            }

            $translations[$languageId] = ['name' => $name];

            $description = $generated['description'][$locale] ?? '';
            if (\is_string($description) && $description !== '') {
                $translations[$languageId]['description'] = $description;
            }
        }

        if (!isset($translations[Defaults::LANGUAGE_SYSTEM])) {
            $first = reset($translations);
            if ($first === false) {
                return null;
            }
            $translations[Defaults::LANGUAGE_SYSTEM] = $first;
        }

        $newCategoryId = Uuid::randomHex();
        $payload = [
            'id' => $newCategoryId,
            'active' => true,
            'displayNestedProducts' => true,
            'type' => 'page',
            'productAssignmentType' => 'product',
            'translations' => $translations,
        ];

        $parentId = (string) ($generated['parentCategoryId'] ?? '');
        if ($parentId !== '' && Uuid::isValid($parentId)) {
            $payload['parentId'] = $parentId;
        }

        $this->categoryRepository->create([$payload], $context);

        return $newCategoryId;
    }

    /**
     * @param array<string, mixed> $output
     *
     * @return list<array<string, string>>
     */
    private function resolveTags(array $output, Context $context): array
    {
        $names = [];
        foreach ((array) ($output['tags'] ?? []) as $tag) {
            if (\is_string($tag) && trim($tag) !== '') {
                $names[] = trim($tag);
            }
        }

        if ($names === []) {
            return [];
        }

        $tags = [];
        foreach (array_unique($names) as $name) {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('name', $name));
            $criteria->setLimit(1);
            $existingId = $this->tagRepository->searchIds($criteria, $context)->firstId();

            $tags[] = $existingId !== null
                ? ['id' => $existingId]
                : ['id' => Uuid::randomHex(), 'name' => $name];
        }

        return $tags;
    }

    /**
     * Advanced pricing ("Erweiterte Preise"): tiered prices bound to a rule,
     * with net computed from the same tax as the main price.
     *
     * @param array<string, mixed> $input
     *
     * @return list<array<string, mixed>>
     */
    private function buildAdvancedPrices(array $input, TaxEntity $tax): array
    {
        $prices = [];

        foreach ((array) ($input['advancedPrices'] ?? []) as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $ruleId = (string) ($row['ruleId'] ?? '');
            $gross = (float) ($row['price'] ?? 0.0);
            if ($ruleId === '' || !Uuid::isValid($ruleId) || $gross <= 0.0) {
                continue;
            }

            $quantityStart = max(1, (int) ($row['quantityStart'] ?? 1));
            $quantityEnd = isset($row['quantityEnd']) && (int) $row['quantityEnd'] > 0
                ? (int) $row['quantityEnd']
                : null;

            $prices[] = [
                'ruleId' => $ruleId,
                'quantityStart' => $quantityStart,
                'quantityEnd' => $quantityEnd,
                'price' => [[
                    'currencyId' => Defaults::CURRENCY,
                    'gross' => $gross,
                    'net' => $this->grossToNet($gross, $tax->getTaxRate()),
                    'linked' => true,
                ]],
            ];
        }

        return $prices;
    }
}
