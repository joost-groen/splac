<?php declare(strict_types=1);

namespace Splac\Service;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

class ProductNumberGenerator
{
    public function __construct(
        private readonly EntityRepository $productRepository,
    ) {
    }

    /**
     * Sanitizes the proposed number and guarantees uniqueness by suffixing.
     */
    public function makeUnique(string $proposed, Context $context): string
    {
        $base = strtoupper(trim($proposed));
        $base = preg_replace('/[^A-Z0-9\-_.]/', '-', $base) ?? $base;
        $base = trim(preg_replace('/-{2,}/', '-', $base) ?? $base, '-');

        if ($base === '') {
            $base = 'SPLAC-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
        }

        $candidate = $base;
        $suffix = 2;

        while ($this->exists($candidate, $context)) {
            $candidate = $base . '-' . $suffix;
            ++$suffix;

            if ($suffix > 100) {
                return $base . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
            }
        }

        return $candidate;
    }

    private function exists(string $productNumber, Context $context): bool
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('productNumber', $productNumber));
        $criteria->setLimit(1);

        return $this->productRepository->searchIds($criteria, $context)->getTotal() > 0;
    }
}
