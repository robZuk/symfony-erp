<?php

namespace App\Tests\Smoke;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class KernelBootTest extends KernelTestCase
{
    public function testKernelBootsAndDatabaseIsReachable(): void
    {
        self::bootKernel();

        $connection = self::getContainer()->get(Connection::class);

        self::assertSame(1, $connection->fetchOne('SELECT 1'));
    }
}
