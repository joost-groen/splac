<?php declare(strict_types=1);

namespace Splac\Command;

use Shopware\Core\Content\Media\MediaService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;
use Splac\Core\Content\Process\ProcessDefinition;
use Splac\Core\Content\Process\ProcessEntity;
use Splac\Core\Content\ProcessSource\ProcessSourceDefinition;
use Splac\MessageQueue\Handler\ExtractSourcesHandler;
use Splac\MessageQueue\Handler\GenerateProcessHandler;
use Splac\MessageQueue\Message\ExtractSourcesMessage;
use Splac\MessageQueue\Message\GenerateProcessMessage;
use Splac\Service\ProductCreator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Temporary development command: runs the full Splac pipeline against a local PDF
 * without going through the admin UI. Remove before release.
 */
#[AsCommand(name: 'splac:e2e-test', description: 'Runs the Splac pipeline end-to-end with a local PDF')]
class SplacE2eTestCommand extends Command
{
    public function __construct(
        private readonly EntityRepository $templateRepository,
        private readonly EntityRepository $processRepository,
        private readonly EntityRepository $processSourceRepository,
        private readonly EntityRepository $taxRepository,
        private readonly EntityRepository $salesChannelRepository,
        private readonly EntityRepository $productRepository,
        private readonly MediaService $mediaService,
        private readonly ExtractSourcesHandler $extractSourcesHandler,
        private readonly GenerateProcessHandler $generateProcessHandler,
        private readonly ProductCreator $productCreator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('pdf', InputArgument::REQUIRED, 'Path to a PDF file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $context = Context::createDefaultContext();
        $pdfPath = (string) $input->getArgument('pdf');

        // 1. Template
        $templateId = Uuid::randomHex();
        $this->templateRepository->create([[
            'id' => $templateId,
            'name' => 'E2E Laptops',
            'active' => true,
            'descriptionTemplates' => [
                'de-DE' => '<h2>{{titel}}</h2><p>{{einleitung}}</p><table><tr><td>Prozessor</td><td>{{prozessor}}</td></tr><tr><td>RAM</td><td>{{ram}}</td></tr></table><p>Rechtlicher Hinweis: Alle Angaben ohne Gewaehr.</p>',
                'en-GB' => '<h2>{{title}}</h2><p>{{intro}}</p><table><tr><td>Processor</td><td>{{processor}}</td></tr><tr><td>RAM</td><td>{{ram}}</td></tr></table><p>Legal: subject to change.</p>',
            ],
            'config' => [
                'languages' => ['de-DE', 'en-GB'],
                'features' => [
                    'description' => true,
                    'seo' => true,
                    'tags' => true,
                    'keywords' => true,
                    'properties' => true,
                    'manufacturer' => true,
                    'identifiers' => true,
                    'productNumber' => true,
                ],
                'fieldModes' => [
                    'metaTitle' => ['mode' => 'instruction', 'de-DE' => 'Titel mit Marke und Modell'],
                    'metaDescription' => ['mode' => 'placeholder', 'de-DE' => 'Kaufe das [modell] jetzt online', 'en-GB' => 'Buy the [model] online now'],
                ],
                'productNumberPattern' => 'LAP-{model}',
            ],
        ]], $context);
        $output->writeln('<info>Template created: ' . $templateId . '</info>');

        // 2. Process
        $taxCriteria = new Criteria();
        $taxCriteria->addSorting(new \Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting('taxRate', 'DESC'));
        $taxCriteria->setLimit(1);
        $taxId = $this->taxRepository->searchIds($taxCriteria, $context)->firstId();
        $salesChannelId = $this->salesChannelRepository->searchIds(new Criteria(), $context)->firstId();

        $processId = Uuid::randomHex();
        $this->processRepository->create([[
            'id' => $processId,
            'name' => 'Lenovo ThinkPad T14 Gen 5',
            'status' => ProcessDefinition::STATUS_DRAFT,
            'templateId' => $templateId,
            'input' => [
                'language' => 'de-DE',
                'productName' => 'Lenovo ThinkPad T14 Gen 5',
                'price' => 1199.0,
                'taxId' => $taxId,
                'stock' => 5,
                'categoryMode' => 'existing',
                'categoryId' => null,
                'salesChannelIds' => [$salesChannelId],
            ],
        ]], $context);
        $output->writeln('<info>Process created: ' . $processId . '</info>');

        // 3. Source PDF
        $mediaId = $this->mediaService->saveFile(
            (string) file_get_contents($pdfPath),
            'pdf',
            'application/pdf',
            'e2e-datasheet-' . Uuid::randomHex(),
            $context,
            null,
            null,
            true
        );
        $this->processSourceRepository->create([[
            'id' => Uuid::randomHex(),
            'processId' => $processId,
            'mediaId' => $mediaId,
            'filename' => basename($pdfPath),
            'status' => ProcessSourceDefinition::STATUS_PENDING,
        ]], $context);
        $output->writeln('<info>Source uploaded, media: ' . $mediaId . '</info>');

        // 4. Extraction (also dispatches generation to the queue; we run it inline below)
        ($this->extractSourcesHandler)(new ExtractSourcesMessage($processId));
        $process = $this->loadProcess($processId, $context);
        $output->writeln('Status after extraction: ' . $process->getStatus());
        $source = $process->getSources()?->first();
        $output->writeln('Extracted text (first 120 chars): ' . substr((string) $source?->getExtractedText(), 0, 120));

        if ($process->getStatus() === ProcessDefinition::STATUS_FAILED) {
            $output->writeln('<error>' . $process->getErrorMessage() . '</error>');

            return Command::FAILURE;
        }

        // 5. Generation (runs inline; requires a configured API key)
        ($this->generateProcessHandler)(new GenerateProcessMessage($processId));
        $process = $this->loadProcess($processId, $context);
        $output->writeln('Status after generation: ' . $process->getStatus());

        if ($process->getStatus() === ProcessDefinition::STATUS_FAILED) {
            $output->writeln('<comment>Generation failed (expected without API key): ' . $process->getErrorMessage() . '</comment>');
            $output->writeln('<comment>Injecting simulated LLM output to test product creation...</comment>');

            $this->processRepository->update([[
                'id' => $processId,
                'status' => ProcessDefinition::STATUS_REVIEW,
                'output' => $this->simulatedOutput(),
            ]], $context);
            $process = $this->loadProcess($processId, $context);
        } else {
            $output->writeln('Generated output: ' . json_encode($process->getOutput(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE));
        }

        // 6. Product creation
        $productId = $this->productCreator->create($process, $context);
        $output->writeln('<info>Product created: ' . $productId . '</info>');

        $criteria = new Criteria([$productId]);
        $criteria->addAssociation('visibilities');
        $criteria->addAssociation('tags');
        $criteria->addAssociation('properties');
        $criteria->addAssociation('manufacturer');
        $product = $this->productRepository->search($criteria, $context)->first();

        $price = $product->getPrice()?->first();
        $output->writeln('  name: ' . $product->getTranslation('name'));
        $output->writeln('  number: ' . $product->getProductNumber());
        $output->writeln('  active: ' . var_export($product->getActive(), true));
        $output->writeln('  price gross/net: ' . $price?->getGross() . ' / ' . $price?->getNet());
        $output->writeln('  stock: ' . $product->getStock());
        $output->writeln('  ean: ' . $product->getEan());
        $output->writeln('  mpn: ' . $product->getManufacturerNumber());
        $output->writeln('  manufacturer: ' . $product->getManufacturer()?->getName());
        $output->writeln('  visibilities: ' . $product->getVisibilities()?->count());
        $output->writeln('  tags: ' . implode(', ', $product->getTags()?->map(fn ($t) => $t->getName()) ?? []));
        $output->writeln('  metaTitle: ' . $product->getTranslation('metaTitle'));
        $output->writeln('  description (first 100): ' . substr((string) $product->getTranslation('description'), 0, 100));

        $output->writeln('<info>E2E test finished.</info>');

        return Command::SUCCESS;
    }

    private function loadProcess(string $processId, Context $context): ProcessEntity
    {
        $criteria = new Criteria([$processId]);
        $criteria->addAssociation('template');
        $criteria->addAssociation('sources');

        /** @var ProcessEntity $process */
        $process = $this->processRepository->search($criteria, $context)->first();

        return $process;
    }

    /**
     * @return array<string, mixed>
     */
    private function simulatedOutput(): array
    {
        return [
            'productName' => [
                'de-DE' => 'Lenovo ThinkPad T14 Gen 5',
            ],
            'description' => [
                'de-DE' => '<h2>Lenovo ThinkPad T14 Gen 5</h2><p>Business-Notebook mit Intel Core Ultra 7.</p><table><tr><td>Prozessor</td><td>Intel Core Ultra 7 155U</td></tr><tr><td>RAM</td><td>32 GB DDR5</td></tr></table><p>Rechtlicher Hinweis: Alle Angaben ohne Gewaehr.</p>',
            ],
            'metaTitle' => [
                'de-DE' => 'Lenovo ThinkPad T14 Gen 5 kaufen',
            ],
            'metaDescription' => [
                'de-DE' => 'Kaufe das ThinkPad T14 Gen 5 jetzt online',
            ],
            'keywords' => [
                'de-DE' => 'laptop, notebook, thinkpad',
            ],
            'tags' => ['Laptop', 'Business', 'Lenovo'],
            'propertyOptionIds' => [],
            'manufacturerId' => '',
            'manufacturerName' => 'Lenovo',
            'ean' => '0198153428358',
            'manufacturerNumber' => '21ML004QGE',
            'productNumber' => 'LAP-THINKPAD-T14-G5',
        ];
    }
}
