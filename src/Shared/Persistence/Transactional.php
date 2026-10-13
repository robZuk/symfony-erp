<?php

namespace App\Shared\Persistence;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Runs a piece of work in a database transaction and saves the changes at the end.
 *
 * A \DomainException means a business rule refused the operation: everything is rolled back
 * and the entity manager stays usable, so the caller can show the reason to the user.
 * Any other error also closes the entity manager, as Doctrine normally does.
 *
 * Calls can be nested; inner ones become savepoints of the outer transaction.
 */
final class Transactional
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function run(callable $work): mixed
    {
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $result = $work();
            $this->entityManager->flush();
            $connection->commit();

            return $result;
        } catch (\DomainException $exception) {
            $connection->rollBack();

            throw $exception;
        } catch (\Throwable $exception) {
            $connection->rollBack();
            $this->entityManager->close();

            throw $exception;
        }
    }
}
