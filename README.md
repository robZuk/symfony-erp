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

Każdy moduł leży w `src/<Moduł>` i może zależeć tylko od `Shared`. Granic pilnuje Deptrac (`deptrac.yaml`).

## Uruchomienie

Wymagania: PHP 8.5, Composer, [Symfony CLI](https://symfony.com/download), Docker.

```bash
composer install
make up      # Postgres i Mailpit
make db      # baza i migracje
make serve   # serwer deweloperski
```

## Jakość

```bash
make test    # PHPUnit
make lint    # PHPStan, Deptrac, PHP-CS-Fixer, lintery Symfony
make fix     # automatyczna poprawa stylu
```
