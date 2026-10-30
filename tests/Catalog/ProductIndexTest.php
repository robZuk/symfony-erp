<?php

namespace App\Tests\Catalog;

use App\Catalog\Dto\ProductFilter;
use App\Catalog\Entity\Product;
use App\Catalog\Repository\ProductRepository;
use App\Catalog\Search\ProductIndex;
use App\Catalog\Search\ProductSearch;
use App\Shared\Event\ProductChanged;
use App\Tests\Support\CreatesRecords;
use App\Tests\Support\IndexesProducts;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Response\Elasticsearch;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class ProductIndexTest extends KernelTestCase
{
    use CreatesRecords;
    use IndexesProducts;

    private ProductIndex $index;
    private Client $client;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->index = self::getContainer()->get(ProductIndex::class);
        $this->client = self::getContainer()->get(Client::class);
    }

    public function testRebuildSwitchesTheAliasAndDropsThePreviousIndex(): void
    {
        $this->createProduct('KAB-1', 'Kabel HDMI');
        $this->indexProducts();
        $first = $this->indicesBehindAlias();

        $this->indexProducts();
        $second = $this->indicesBehindAlias();

        self::assertCount(1, $first);
        self::assertCount(1, $second);
        self::assertNotSame($first, $second);
        // Nothing is left of the first index, behind the alias or not
        self::assertSame($second, $this->indicesNamed($this->index->alias().'-*'));
    }

    public function testWritingBeforeTheIndexExistsFails(): void
    {
        $this->dropEverything();
        $product = $this->createProduct('KAB-1', 'Kabel HDMI');

        try {
            $this->index->index($product);
            self::fail('Writing without the alias should have been refused.');
        } catch (ClientResponseException $exception) {
            self::assertSame(404, $exception->getCode());
        }

        // Elasticsearch did not create an index of that name on its own
        self::assertFalse($this->index->exists());
    }

    public function testSavedProductReachesTheIndexThroughItsEvent(): void
    {
        $this->indexProducts();
        $product = $this->createProduct('ZAR-1', 'Żarówka LED');

        // The worker: the event goes from the outbox to RabbitMQ and comes back on erp.search
        $this->deliver($this->lastProductChanged());
        self::assertSame(['ZAR-1'], $this->found('zarowka'));

        $product->setName('Świetlówka kompaktowa');
        self::getContainer()->get(ProductRepository::class)->save($product);
        $this->deliver($this->lastProductChanged());

        self::assertSame([], $this->found('zarowka'));
        self::assertSame(['ZAR-1'], $this->found('swietlowka'));
    }

    public function testLateEventStillLeavesTheLatestVersion(): void
    {
        $this->indexProducts();
        $product = $this->createProduct('ZAR-1', 'Żarówka LED');
        $early = $this->lastProductChanged();
        $product->setName('Świetlówka kompaktowa');
        self::getContainer()->get(ProductRepository::class)->save($product);

        // The first event is handled after the change it predates
        $this->deliver($early);

        self::assertSame(['ZAR-1'], $this->found('swietlowka'));
    }

    private function lastProductChanged(): ProductChanged
    {
        $outbox = self::getContainer()->get('messenger.transport.outbox');
        self::assertInstanceOf(InMemoryTransport::class, $outbox);
        $events = array_values(array_filter(
            array_map(static fn (Envelope $envelope): object => $envelope->getMessage(), [...$outbox->getSent()]),
            static fn (object $message): bool => $message instanceof ProductChanged,
        ));
        $last = end($events);
        self::assertInstanceOf(ProductChanged::class, $last);

        return $last;
    }

    private function deliver(ProductChanged $event): void
    {
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new Envelope($event, [new ReceivedStamp('events')]));
        $this->index->refresh();
    }

    /**
     * @return list<string> SKUs of the active products found by the words
     */
    private function found(string $q): array
    {
        $filter = new ProductFilter();
        $filter->q = $q;

        return array_map(
            static fn (Product $product): string => $product->getSku(),
            self::getContainer()->get(ProductSearch::class)->search($filter)->page->items,
        );
    }

    /**
     * @return list<string>
     */
    private function indicesBehindAlias(): array
    {
        $response = $this->client->indices()->getAlias(['name' => $this->index->alias()]);
        \assert($response instanceof Elasticsearch);

        return array_keys($response->asArray());
    }

    /**
     * @return list<string>
     */
    private function indicesNamed(string $pattern): array
    {
        $response = $this->client->indices()->get(['index' => $pattern]);
        \assert($response instanceof Elasticsearch);

        return array_keys($response->asArray());
    }

    private function dropEverything(): void
    {
        foreach ($this->indicesNamed($this->index->alias().'*') as $index) {
            $this->client->indices()->delete(['index' => $index]);
        }
    }
}
