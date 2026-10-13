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

Moduły nie sięgają do swoich encji nawzajem. Magazyn przechowuje tylko identyfikatory produktów i kontrahentów, a o resztę pyta przez interfejsy z `Shared` (`ProductDirectory`, `PartnerDirectory`), które implementują moduły-właściciele.

## Magazyn

- Dokument (PZ, WZ, MM) powstaje jako szkic i nie zmienia stanów. Zmienia je dopiero zaksięgowanie, po którym dokumentu nie da się już edytować ani usunąć; pomyłkę naprawia dokument korygujący.
- Stan magazynowy jest sumą ruchów. Ruchy są tylko dopisywane, a stan jest aktualizowany w tej samej transakcji (`StockPosting`).
- Nie da się wydać więcej, niż jest dostępne. Wiersze stanów są blokowane na czas księgowania, więc dwa jednoczesne wydania tego samego towaru nie przejdą oba.
- Numery dokumentów (`PZ/2026/10/0001`) są nadawane przy księgowaniu, osobno dla typu i miesiąca, bez dziur.
- Ilości są przechowywane jako liczby całkowite w tysięcznych częściach jednostki, a kwoty w groszach.

Blokady i numeracja korzystają ze składni PostgreSQL (`INSERT … ON CONFLICT`).

## Uruchomienie

Wymagania: PHP 8.5, Composer, [Symfony CLI](https://symfony.com/download), Docker.

```bash
composer install
make up      # Postgres i Mailpit
make db      # baza i migracje
make serve   # serwer deweloperski, w tle przebudowuje CSS
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
