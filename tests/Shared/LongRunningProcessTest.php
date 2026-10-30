<?php

namespace App\Tests\Shared;

use App\Catalog\Entity\Product;
use App\Shared\Audit\ActivityLog;
use App\Shared\Audit\Actor;
use App\Shared\Audit\AuditListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * In the FrankenPHP worker and in the Messenger worker one process handles request after request,
 * so nothing of one may leak into the next.
 */
final class LongRunningProcessTest extends KernelTestCase
{
    public function testFlushThatFailedLeavesNothingForTheNextOne(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $listener = self::getContainer()->get(AuditListener::class);

        // A flush that got as far as onFlush and then failed, so postFlush never ran
        $failed = $this->product('KAB-1');
        $entityManager->persist($failed);
        $entityManager->getUnitOfWork()->computeChangeSets();
        $listener->onFlush(new OnFlushEventArgs($entityManager));
        $entityManager->clear();

        // The next, unrelated flush
        $entityManager->persist($this->product('ZAR-1'));
        $entityManager->flush();

        $logged = self::getContainer()->get('doctrine.dbal.default_connection')->fetchFirstColumn("SELECT summary FROM shared_activity_log WHERE action = 'catalog.product.created'");
        self::assertCount(1, $logged);
        self::assertStringContainsString('ZAR-1', $logged[0]);
    }

    public function testOnBehalfOfIsForgottenBetweenRequests(): void
    {
        self::bootKernel();
        $log = self::getContainer()->get(ActivityLog::class);
        $log->onBehalfOf(new class implements Actor {
            public function actorId(): int
            {
                return 1;
            }

            public function actorName(): string
            {
                return 'Anna z poprzedniego żądania';
            }
        });

        // What the kernel does between two requests of the worker, and between two messages
        self::getContainer()->get('services_resetter')->reset();

        self::assertNull($log->currentActor());
    }

    private function product(string $sku): Product
    {
        $product = new Product();
        $product->setSku($sku);
        $product->setName('Produkt '.$sku);

        return $product;
    }
}
