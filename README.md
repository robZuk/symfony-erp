# Mini-ERP

Modularny monolit w Symfony obsługujący proces od zamówienia do faktury: magazyn, sprzedaż i fakturowanie.

Projekt w budowie — moduły powstają etapami.

## Moduły

| Moduł | Zakres |
|-------|--------|
| `Identity` | użytkownicy, role, logowanie |
| `Catalog` | produkty, jednostki miary, stawki VAT |
| `Partner` | kontrahenci |
| `Inventory` | magazyny, dokumenty PZ/WZ/MM, stany |
| `Sales` | zamówienia sprzedaży, rezerwacje |
| `Invoicing` | faktury VAT, PDF |
| `Shared` | wspólne typy (pieniądze, numeracja) |
| `Dashboard` | pulpit i raporty; jedyny moduł, który może czytać ze wszystkich pozostałych |

Każdy moduł leży w `src/<Moduł>` i może zależeć tylko od `Shared` (wyjątkiem jest `Dashboard`). Granic pilnuje Deptrac (`deptrac.yaml`).

Moduły nie sięgają do swoich encji nawzajem. Magazyn przechowuje tylko identyfikatory produktów i kontrahentów, a o resztę pyta przez interfejsy z `Shared` (`ProductDirectory`, `PartnerDirectory`, `WarehouseDirectory`, `StockReservations`, `BillableOrders`), które implementują moduły-właściciele.

## Magazyn

- Dokument (PZ, WZ, MM) powstaje jako szkic i nie zmienia stanów. Zmienia je dopiero zaksięgowanie, po którym dokumentu nie da się już edytować ani usunąć; pomyłkę naprawia dokument korygujący.
- Stan magazynowy jest sumą ruchów. Ruchy są tylko dopisywane, a stan jest aktualizowany w tej samej transakcji (`StockPosting`).
- Nie da się wydać więcej, niż jest dostępne. Wiersze stanów są blokowane na czas księgowania, więc dwa jednoczesne wydania tego samego towaru nie przejdą oba.
- Numery dokumentów (`PZ/2026/10/0001`) są nadawane przy księgowaniu, osobno dla typu i miesiąca, bez dziur.
- Ilości są przechowywane jako liczby całkowite w tysięcznych częściach jednostki, a kwoty w groszach.

Blokady i numeracja korzystają ze składni PostgreSQL (`INSERT … ON CONFLICT`).

## Sprzedaż

- Zamówienie przechodzi przez statusy opisane w workflow `sales_order` (`config/packages/workflow.yaml`): szkic → potwierdzone → wydane → zafakturowane, z możliwością anulowania przed wydaniem.
- Potwierdzenie nadaje numer (`ZS/2026/10/0001`) i rezerwuje towar; zarezerwowanego towaru nie da się wydać ani obiecać komuś innemu. Wydanie zamienia rezerwację w zaksięgowany dokument WZ. Anulowanie zwalnia rezerwację.
- Każdy krok to jedna transakcja obejmująca zamówienie i magazyn (`OrderLifecycle`). Sprzedaż rozmawia z magazynem przez interfejs `StockReservations` z `Shared`.
- Handlowiec potwierdza i anuluje, magazynier wydaje.
- Formularz zamówienia to Live Component (`App\Sales\Twig\OrderEditor`): pozycje, ceny z kartoteki i sumy odświeżają się po stronie serwera, bez własnego JavaScriptu.
- Ceny i stawki VAT są kopiowane na pozycję, więc późniejsze zmiany w kartotece nie zmieniają zamówienia. VAT jest liczony od sumy netto w każdej stawce.

## Faktury

- Fakturę wystawia się do wydanego zamówienia, jedną na zamówienie. Wystawienie to jedna transakcja: numer (`FV/2026/10/0001`), zapis faktury i przejście zamówienia w status „zafakturowane”.
- Faktura jest migawką: dane sprzedawcy, nabywcy i pozycji są na nią kopiowane, a sumy zapisywane. Późniejsze zmiany w kartotekach jej nie ruszają.
- Wystawionej faktury nie da się edytować. Pomyłkę naprawia faktura korygująca (`FK/…`), która zmniejsza ilości i pokazuje „było / jest / różnica”. VAT korekty to różnica między VAT-em dokumentu przed i po, dzięki czemu faktura i jej korekty zawsze sumują się co do grosza.
- PDF generuje w tle worker Messengera (`messenger:consume async`, uruchamiany przez `make serve`). Wiadomość trafia do kolejki w tej samej transakcji bazy co faktura. Strona faktury sama odświeża status, aż plik będzie gotowy.
- Dane sprzedawcy są w `config/services.yaml` (`app.seller`).
- Fakturuje księgowość; przeglądać może każdy zalogowany.

## Uruchomienie

Wymagania: PHP 8.5, Composer, [Symfony CLI](https://symfony.com/download), Docker.

```bash
composer install
make up      # Postgres i Mailpit
make db      # baza i migracje
make serve   # serwer deweloperski; w tle przebudowuje CSS i obsługuje kolejkę (PDF faktur)
symfony console app:user:create admin@example.com "Jan Kowalski" --role=ROLE_ADMIN
```

Role: `ROLE_ADMIN`, `ROLE_WAREHOUSE`, `ROLE_SALES`, `ROLE_ACCOUNTING`.

## Interfejs

Tailwind CSS i komponenty Twig z [Symfony UX Toolkit](https://ux.symfony.com/toolkit) (kit Shadcn). Komponenty leżą w `templates/components/` i są częścią projektu, więc można je dowolnie zmieniać. Kolejne dodaje się komendą:

```bash
symfony console ux:install <komponent> --kit shadcn
```

## Jakość

```bash
make test    # PHPUnit
make lint    # PHPStan, Deptrac, PHP-CS-Fixer, lintery Symfony
make fix     # automatyczna poprawa stylu
```
