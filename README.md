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
