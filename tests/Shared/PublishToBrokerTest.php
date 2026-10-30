<?php

namespace App\Tests\Shared;

use App\Shared\Event\InvoiceIssued;
use App\Shared\Event\OrderShipped;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

final class PublishToBrokerTest extends KernelTestCase
{
    private MessageBusInterface $bus;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->bus = self::getContainer()->get(MessageBusInterface::class);
    }

    public function testEventFromTheOutboxIsPublishedUnderItsRoutingKey(): void
    {
        $event = new OrderShipped(7, 'ZS/2026/10/0007', 3, 'WZ/2026/10/0002', new \DateTimeImmutable('2026-10-13 12:00'));

        // What the worker does with a row it read from the outbox table
        $this->bus->dispatch(new Envelope($event, [new ReceivedStamp('outbox')]));

        $published = $this->events()->getSent();
        self::assertCount(1, $published);
        self::assertSame($event, $published[0]->getMessage());
        self::assertSame('sales.order.shipped', $published[0]->last(AmqpStamp::class)?->getRoutingKey());
    }

    public function testEventFromTheBrokerIsNotPublishedAgain(): void
    {
        $this->bus->dispatch(new Envelope($this->invoiceIssued(), [new ReceivedStamp('events')]));

        self::assertSame([], [...$this->events()->getSent()]);
    }

    public function testEventsTravelAsPlainJson(): void
    {
        $serializer = self::getContainer()->get('messenger.transport.symfony_serializer');
        self::assertInstanceOf(SerializerInterface::class, $serializer);
        $event = $this->invoiceIssued();

        $encoded = $serializer->encode(new Envelope($event));
        $body = json_decode($encoded['body'], true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame(InvoiceIssued::class, $encoded['headers']['type'] ?? null);
        self::assertSame('FV/2026/10/0001', $body['number']);
        self::assertSame(-4990, $body['netTotal']);
        self::assertStringStartsWith('2026-10-13T', $body['issueDate']);

        self::assertEquals($event, $serializer->decode($encoded)->getMessage());
    }

    private function invoiceIssued(): InvoiceIssued
    {
        return new InvoiceIssued(1, 'FV/2026/10/0001', true, 7, -4990, -6138, new \DateTimeImmutable('2026-10-13'), new \DateTimeImmutable('2026-10-13 12:00'));
    }

    private function events(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.events');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
