<?php

namespace App\DataFixtures;

use App\Catalog\Entity\Product;
use App\Catalog\Entity\ProductKind;
use App\Catalog\Entity\UnitOfMeasure;
use App\Identity\Entity\User;
use App\Inventory\Entity\DocumentType;
use App\Inventory\Entity\StockDocument;
use App\Inventory\Entity\Warehouse;
use App\Inventory\Service\StockPosting;
use App\Invoicing\Entity\PaymentMethod;
use App\Invoicing\Message\GenerateInvoicePdf;
use App\Invoicing\Message\GenerateInvoicePdfHandler;
use App\Invoicing\Service\InvoiceIssuing;
use App\Partner\Entity\Partner;
use App\Sales\Entity\SalesOrder;
use App\Sales\Service\OrderLifecycle;
use App\Shared\Audit\ActivityLog;
use App\Shared\Tax\VatRate;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Demo data: `make demo`. Everything goes through the same services the screens use,
 * so stock levels, reservations, document numbers and invoices are all consistent.
 *
 * It tells a small story: half a year of deliveries, orders and invoices of an electronics
 * wholesaler, ending with a few orders still in progress and a few products running low.
 * The same data is produced on every run.
 */
final class DemoFixtures extends Fixture
{
    /** Sign-in data of the demo accounts: e-mail => [name, role, password]. For local use only. */
    public const array USERS = [
        'admin@example.com' => ['Adam Administrator', User::ROLE_ADMIN, 'admin12345'],
        'magazyn@example.com' => ['Marek Magazynier', User::ROLE_WAREHOUSE, 'magazyn12345'],
        'sprzedaz@example.com' => ['Hanna Handlowiec', User::ROLE_SALES, 'sprzedaz12345'],
        'ksiegowosc@example.com' => ['Krystyna Księgowa', User::ROLE_ACCOUNTING, 'ksiegowosc12345'],
    ];

    /** SKU, name, kind, unit, net price in grosze, VAT rate, minimum stock in pieces. */
    private const array PRODUCTS = [
        ['KAB-HDMI-2M', 'Kabel HDMI 2.1, 2 m', ProductKind::Goods, UnitOfMeasure::Piece, 3490, VatRate::Standard, 40],
        ['KAB-USBC-1M', 'Kabel USB-C 100 W, 1 m', ProductKind::Goods, UnitOfMeasure::Piece, 2190, VatRate::Standard, 80],
        ['KAB-ETH-5M', 'Patchcord Cat 6, 5 m', ProductKind::Goods, UnitOfMeasure::Piece, 1290, VatRate::Standard, 50],
        ['LAD-65W', 'Ładowarka sieciowa GaN 65 W', ProductKind::Goods, UnitOfMeasure::Piece, 8900, VatRate::Standard, 20],
        ['MYSZ-BT', 'Mysz bezprzewodowa Bluetooth', ProductKind::Goods, UnitOfMeasure::Piece, 6900, VatRate::Standard, 25],
        ['KLAW-MECH', 'Klawiatura mechaniczna, układ PL', ProductKind::Goods, UnitOfMeasure::Piece, 24900, VatRate::Standard, 10],
        ['MON-27-QHD', 'Monitor 27" QHD 165 Hz', ProductKind::Goods, UnitOfMeasure::Piece, 109900, VatRate::Standard, 6],
        ['HUB-USB-7', 'Hub USB 3.2, 7 portów', ProductKind::Goods, UnitOfMeasure::Piece, 11900, VatRate::Standard, 15],
        ['SSD-1TB', 'Dysk SSD NVMe 1 TB', ProductKind::Goods, UnitOfMeasure::Piece, 31900, VatRate::Standard, 12],
        ['SLUCH-BT', 'Słuchawki nauszne Bluetooth', ProductKind::Goods, UnitOfMeasure::Piece, 18900, VatRate::Standard, 0],
        ['PRZEW-YDY', 'Przewód YDY 3x2,5', ProductKind::Goods, UnitOfMeasure::Meter, 690, VatRate::Standard, 300],
        ['KS-SIECI', 'Książka „Sieci komputerowe w praktyce”', ProductKind::Goods, UnitOfMeasure::Piece, 5900, VatRate::Reduced5, 0],
        ['USL-MONTAZ', 'Montaż i konfiguracja stanowiska', ProductKind::Service, UnitOfMeasure::Hour, 15000, VatRate::Standard, 0],
        ['USL-DOSTAWA', 'Dostawa kurierska', ProductKind::Service, UnitOfMeasure::Piece, 2500, VatRate::Standard, 0],
    ];

    /** Name, street, postal code, city, customer?, supplier? */
    private const array PARTNERS = [
        ['Biuro Rachunkowe Saldo sp. z o.o.', 'Grunwaldzka 82', '80-244', 'Gdańsk', true, false],
        ['Kancelaria Prawna Lex i Wspólnicy', 'Świdnicka 12', '50-068', 'Wrocław', true, false],
        ['Pracownia Architektury Kontur', 'Piotrkowska 101', '90-425', 'Łódź', true, false],
        ['Szkoła Językowa Lingua', 'Krakowska 7', '31-062', 'Kraków', true, false],
        ['Serwis Komputerowy Bajt', 'Długa 5', '85-032', 'Bydgoszcz', true, true],
        ['Sklep Internetowy TechDom', 'Marszałkowska 55', '00-676', 'Warszawa', true, false],
        ['Hurtownia Elektro-Import S.A.', 'Poznańska 210', '60-650', 'Poznań', false, true],
        ['Dystrybucja IT Polska sp. z o.o.', 'Aleje Jerozolimskie 181', '02-222', 'Warszawa', false, true],
    ];

    private const int MONTHS = 6;

    /** @var array<string, User> the demo users, indexed by role */
    private array $users = [];

    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly StockPosting $posting,
        private readonly OrderLifecycle $orders,
        private readonly InvoiceIssuing $invoicing,
        private readonly GenerateInvoicePdfHandler $pdfHandler,
        private readonly ClockInterface $clock,
        private readonly ActivityLog $activity,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        // A fixed seed: the "random" choices below come out the same on every run
        mt_srand(2026);

        $this->users = $this->loadUsers($manager);
        [$main, $shop] = $this->loadWarehouses($manager);
        $products = $this->loadProducts($manager);
        [$customers, $suppliers] = $this->loadPartners($manager);
        $manager->flush();

        $goods = array_values(array_filter($products, static fn (Product $product): bool => ProductKind::Goods === $product->getKind()));
        $today = $this->clock->now()->setTime(12, 0);
        $firstOfThisMonth = $today->modify('first day of this month');

        // Five closed months and the current one, oldest first
        for ($monthsAgo = self::MONTHS - 1; $monthsAgo >= 0; --$monthsAgo) {
            $monthStart = $firstOfThisMonth->modify(\sprintf('-%d months', $monthsAgo));
            $lastDay = 0 === $monthsAgo ? (int) $today->format('j') : (int) $monthStart->format('t');
            $isCurrent = 0 === $monthsAgo;

            $this->travelTo($monthStart);
            $this->receiveDelivery($manager, $main, $suppliers[mt_rand(0, \count($suppliers) - 1)], $goods, $monthStart, self::MONTHS - 1 === $monthsAgo);

            // Business grows a little month by month
            $orderCount = 4 + (self::MONTHS - 1 - $monthsAgo);
            $days = [];
            for ($i = 0; $i < $orderCount; ++$i) {
                $days[] = mt_rand(0, max(0, $lastDay - 1));
            }
            sort($days);

            foreach ($days as $i => $offset) {
                $day = $monthStart->modify(\sprintf('+%d days', $offset));
                $this->travelTo($day);
                $order = $this->createOrder($manager, $customers[mt_rand(0, \count($customers) - 1)], $main, $products, $day);

                // In the current month the three newest orders stay at earlier steps, so every status can be seen
                $fromNewest = $orderCount - 1 - $i;
                $stopAt = $isCurrent ? (['draft', 'confirmed', 'shipped'][$fromNewest] ?? 'invoiced') : 'invoiced';
                $this->advance($order, $stopAt, $day);
            }
        }

        $this->travelTo($today);
        $this->addLooseEnds($manager, $main, $shop, $customers, $suppliers, $products, $goods, $today);
        $manager->flush();

        // Back to the real time and the real user
        Clock::set(new NativeClock());
        $this->activity->onBehalfOf(null);
    }

    /**
     * Sets the application clock, so that timestamps written by the services (posted, shipped, created)
     * fall on the day the demo event is supposed to have happened.
     */
    private function travelTo(\DateTimeImmutable $moment): void
    {
        Clock::set(new MockClock($moment));
    }

    /**
     * @return array<string, User> indexed by role
     */
    private function loadUsers(ObjectManager $manager): array
    {
        $users = [];
        foreach (self::USERS as $email => [$name, $role, $password]) {
            $user = new User($email, $name, [$role]);
            $user->setPassword($this->passwordHasher->hashPassword($user, $password));
            $manager->persist($user);
            $users[$role] = $user;
        }

        return $users;
    }

    /**
     * Makes the activity log attribute what follows to the demo user with the given role.
     */
    private function actAs(string $role): void
    {
        $this->activity->onBehalfOf($this->users[$role]);
    }

    /**
     * @return array{Warehouse, Warehouse}
     */
    private function loadWarehouses(ObjectManager $manager): array
    {
        $warehouses = [];
        foreach ([['MG', 'Magazyn główny'], ['SK', 'Sklep firmowy']] as [$code, $name]) {
            $warehouse = new Warehouse();
            $warehouse->setCode($code);
            $warehouse->setName($name);
            $manager->persist($warehouse);
            $warehouses[] = $warehouse;
        }

        return [$warehouses[0], $warehouses[1]];
    }

    /**
     * @return list<Product>
     */
    private function loadProducts(ObjectManager $manager): array
    {
        $products = [];
        foreach (self::PRODUCTS as [$sku, $name, $kind, $unit, $price, $vatRate, $minimum]) {
            $product = new Product();
            $product->setSku($sku);
            $product->setName($name);
            $product->setKind($kind);
            $product->setUnit($unit);
            $product->setNetPrice($price);
            $product->setVatRate($vatRate);
            $product->setMinimumStock($minimum * 1000);
            $manager->persist($product);
            $products[] = $product;
        }

        return $products;
    }

    /**
     * @return array{list<Partner>, list<Partner>} customers and suppliers
     */
    private function loadPartners(ObjectManager $manager): array
    {
        $customers = [];
        $suppliers = [];
        foreach (self::PARTNERS as $index => [$name, $street, $postalCode, $city, $isCustomer, $isSupplier]) {
            $partner = new Partner();
            $partner->setName($name);
            $partner->setNip(self::fictionalNip($index));
            $partner->setCustomer($isCustomer);
            $partner->setSupplier($isSupplier);
            $partner->setEmail(\sprintf('biuro%d@example.com', $index + 1));
            $partner->getAddress()->setStreet($street);
            $partner->getAddress()->setPostalCode($postalCode);
            $partner->getAddress()->setCity($city);
            $manager->persist($partner);

            if ($isCustomer) {
                $customers[] = $partner;
            }
            if ($isSupplier) {
                $suppliers[] = $partner;
            }
        }

        return [$customers, $suppliers];
    }

    /**
     * A monthly delivery that tops every product up, posted as a PZ.
     *
     * @param list<Product> $goods
     */
    private function receiveDelivery(ObjectManager $manager, Warehouse $warehouse, Partner $supplier, array $goods, \DateTimeImmutable $date, bool $opening): void
    {
        $document = new StockDocument(DocumentType::Receipt, $warehouse, $date, $date);
        $document->setPartnerId($supplier->getId());
        $document->setNote($opening ? 'Zatowarowanie magazynu' : 'Dostawa miesięczna');
        foreach ($goods as $product) {
            // Cheap items come by the dozen, expensive ones a few at a time; wire by the metre
            $quantity = match (true) {
                UnitOfMeasure::Meter === $product->getUnit() => 120,
                $product->getNetPrice() >= 50000 => mt_rand(1, 2),
                $product->getNetPrice() >= 10000 => mt_rand(3, 6),
                default => mt_rand(8, 16),
            };
            // The opening delivery fills the shelves; later ones only replace roughly what was sold
            $document->addLine((int) $product->getId(), $quantity * ($opening ? 3000 : 1000));
        }
        $manager->persist($document);
        $manager->flush();
        $this->actAs(User::ROLE_WAREHOUSE);
        $this->posting->post($document);
    }

    /**
     * @param list<Product> $products
     */
    private function createOrder(ObjectManager $manager, Partner $customer, Warehouse $warehouse, array $products, \DateTimeImmutable $date): SalesOrder
    {
        $picked = (array) array_rand($products, mt_rand(1, 4));
        $lines = [];
        foreach ($picked as $index) {
            $product = $products[$index];
            $quantity = match (true) {
                UnitOfMeasure::Meter === $product->getUnit() => mt_rand(2, 12) * 2500,
                UnitOfMeasure::Hour === $product->getUnit() => mt_rand(1, 6) * 500,
                $product->getNetPrice() >= 50000 => 1000,
                default => mt_rand(1, 5) * 1000,
            };
            $lines[] = [
                'productId' => (int) $product->getId(),
                'quantity' => $quantity,
                'unitNetPrice' => $product->getNetPrice(),
                'vatRate' => $product->getVatRate(),
            ];
        }

        $order = new SalesOrder((int) $customer->getId(), (int) $warehouse->getId(), $date, $date);
        $order->revise((int) $customer->getId(), (int) $warehouse->getId(), $date, null, $lines);
        $manager->persist($order);
        $manager->flush();

        return $order;
    }

    /**
     * Takes an order as far as the given status, issuing its documents on the way.
     */
    private function advance(SalesOrder $order, string $stopAt, \DateTimeImmutable $date): void
    {
        if ('draft' === $stopAt) {
            return;
        }

        try {
            $this->actAs(User::ROLE_SALES);
            $this->orders->confirm($order);
        } catch (\DomainException) {
            // Not enough stock for this one: it simply stays a draft, as it would in real life
            return;
        }
        if ('confirmed' === $stopAt) {
            return;
        }

        $this->actAs(User::ROLE_WAREHOUSE);
        $this->orders->ship($order);
        if ('shipped' === $stopAt) {
            return;
        }

        $this->actAs(User::ROLE_ACCOUNTING);

        $invoice = $this->invoicing->issueForOrder(
            (int) $order->getId(),
            $date,
            [7, 14, 14, 30][mt_rand(0, 3)],
            [PaymentMethod::Transfer, PaymentMethod::Transfer, PaymentMethod::Card][mt_rand(0, 2)],
        );
        // Rendered right away so the demo does not depend on a queue worker being up
        ($this->pdfHandler)(new GenerateInvoicePdf((int) $invoice->getId()));
    }

    /**
     * The things one finds on a working day: a transfer to the shop, a delivery not posted yet,
     * a cancelled order and an invoice that had to be corrected.
     *
     * @param list<Partner> $customers
     * @param list<Partner> $suppliers
     * @param list<Product> $products
     * @param list<Product> $goods
     */
    private function addLooseEnds(
        ObjectManager $manager,
        Warehouse $main,
        Warehouse $shop,
        array $customers,
        array $suppliers,
        array $products,
        array $goods,
        \DateTimeImmutable $today,
    ): void {
        $transfer = new StockDocument(DocumentType::Transfer, $main, $today, $today, $shop);
        $transfer->setNote('Uzupełnienie ekspozycji');
        foreach ([0, 1, 4] as $index) {
            $transfer->addLine((int) $goods[$index]->getId(), 5000);
        }
        $manager->persist($transfer);

        $draft = new StockDocument(DocumentType::Receipt, $main, $today, $today);
        $draft->setPartnerId($suppliers[0]->getId());
        $draft->setNote('Dostawa w drodze, do zaksięgowania po rozładunku');
        $draft->addLine((int) $goods[6]->getId(), 4000);
        $draft->addLine((int) $goods[8]->getId(), 10000);
        $manager->persist($draft);
        $manager->flush();
        $this->actAs(User::ROLE_WAREHOUSE);
        $this->posting->post($transfer);

        $this->actAs(User::ROLE_SALES);
        $cancelled = $this->createOrder($manager, $customers[0], $main, $products, $today);
        $this->orders->cancel($cancelled);

        // Two cables sold, one comes back: a correcting invoice
        $returned = new SalesOrder((int) $customers[1]->getId(), (int) $main->getId(), $today, $today);
        $returned->revise((int) $customers[1]->getId(), (int) $main->getId(), $today, 'Zamówienie telefoniczne', [[
            'productId' => (int) $goods[0]->getId(),
            'quantity' => 2000,
            'unitNetPrice' => $goods[0]->getNetPrice(),
            'vatRate' => $goods[0]->getVatRate(),
        ]]);
        $manager->persist($returned);
        $manager->flush();
        $this->orders->confirm($returned);
        $this->actAs(User::ROLE_WAREHOUSE);
        $this->orders->ship($returned);
        $this->actAs(User::ROLE_ACCOUNTING);
        $invoice = $this->invoicing->issueForOrder((int) $returned->getId(), $today, 14, PaymentMethod::Transfer);
        ($this->pdfHandler)(new GenerateInvoicePdf((int) $invoice->getId()));

        $line = $invoice->getLines()->first();
        if (false !== $line) {
            $correction = $this->invoicing->correct($invoice, [(int) $line->getId() => 1000], 'Zwrot jednej sztuki, uszkodzone opakowanie', $today);
            ($this->pdfHandler)(new GenerateInvoicePdf((int) $correction->getId()));
        }
    }

    /**
     * A made-up tax number with a correct checksum, different for every index.
     */
    private static function fictionalNip(int $index): string
    {
        for ($attempt = 0;; ++$attempt) {
            $digits = \sprintf('%09d', 525000000 + $index * 1117 + $attempt);
            $sum = 0;
            foreach ([6, 5, 7, 2, 3, 4, 5, 6, 7] as $position => $weight) {
                $sum += $weight * (int) $digits[$position];
            }

            // A remainder of ten has no digit to stand for it, so that number does not exist
            if (10 !== $sum % 11) {
                return $digits.($sum % 11);
            }
        }
    }
}
