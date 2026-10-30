# Wydajność

Co zostało zmierzone, co z tego wynikło i jak powtórzyć pomiar. Zasada: najpierw pomiar, potem zmiana, potem znowu pomiar. Każda liczba poniżej pochodzi z testu, a nie z założeń.

## Jak mierzone

- **Obraz produkcyjny** (`APP_ENV=prod`, bez debugowania i profilera), a nie serwer deweloperski, który mierzyłby przede wszystkim siebie.
- **Osobna baza** `app_perf` z danymi demo, osobny indeks wyszukiwarki i osobna baza Redisa, żeby test składający setki zamówień nie dotknął danych deweloperskich. Przed każdym pomiarem dane są odtwarzane, a pierwsze żądania (rozgrzanie OPcache) nie są liczone.
- **k6** (`tests/load/k6.js`), trzy scenariusze naraz przez minutę:

| Scenariusz | Obciążenie | Co sprawdza |
|---|---|---|
| `browse` | 10 osób w przeglądarce bez przerw | logowanie, pulpit, lista produktów, lista zamówień (Twig, sesja w Redisie) |
| `api_search` | 10 wątków sklepu bez przerw | `GET /api/products?q=…` z losową frazą (Elasticsearch) |
| `api_orders` | 5 zamówień na sekundę | `POST /api/orders` (transakcja, blokada, rezerwacja, outbox) |

- Jeden komputer (12 rdzeni) obsługuje aplikację, bazę, Redisa, RabbitMQ, Elasticsearch i samo k6. Liczby bezwzględne są więc przybliżone, ale porównania między wariantami są uczciwe, bo warunki są te same.

```bash
docker run --rm --network host -v "$PWD/tests/load:/load" grafana/k6:1.4.2 run \
  -e BASE_URL=http://127.0.0.1:8090 -e API_KEY=erp_… -e EMAIL=admin@example.com -e PASSWORD=admin12345 /load/k6.js
```

Test potrzebuje klucza z zakresami `catalog:read` i `orders:write` oraz limitu zapytań podniesionego na czas testu (`API_RATE_LIMIT=1000000`). Mierzona ma być aplikacja, a nie limit.

## Wyniki

| | Tryb klasyczny | Tryb worker, 24 workery (domyślnie przy 12 rdzeniach) | **Tryb worker, 4 workery (produkcja)** |
|---|---|---|---|
| Żądań na sekundę | 546 | 2196 | **1068** |
| Błędy | 13,4% | 0% | **0%** |
| p95: strony | 50 ms | 9 ms | **22 ms** |
| p95: wyszukiwanie API | 179 ms | 39 ms | **25 ms** |
| p95: zamówienie API | 976 ms | 89 ms | **42 ms** |
| Nowe połączenia do Redisa w ciągu minuty | 28 270 | 280 | 421 |
| Pamięć poda web | ok. 60 MB | 191 MB | **101 MB** |

## Co się okazało

### 1. Jedno połączenie z Redisem na żądanie

Pierwszy pomiar dał 37% błędów. Część to był limit zapytań API (120 na minutę na klucz) działający zgodnie z założeniem, ale strony bez limitu też się sypały. W logu było 6610 razy `Redis connection failed: read error on connection`.

Przyczyna: w trybie klasycznym każde żądanie startuje aplikację od zera, łączy się z Redisem (sesja, cache, licznik limitu) i rozłącza na końcu. Przy około 550 żądaniach na sekundę Redis przyjął około 29 000 połączeń w minutę, a ponad 1000 gniazd czekało w `TIME_WAIT`. Lokalnie dochodził do tego `docker-proxy`, który przy takim tempie gubił połączenia. Na produkcji tego pośrednika nie ma, ale sam koszt nawiązywania połączenia przy każdym żądaniu zostaje.

### 2. `persistent=1` nie pomogło

Pierwszy pomysł to trwałe połączenia `phpredis` (`?persistent=1` w adresie Redisa). Pomiar je obalił: 200 żądań dało 201 nowych połączeń. `phpredis` nie utrzymuje połączeń między żądaniami w klasycznym trybie FrankenPHP, więc ta zmiana nie trafiła do kodu. Zostały same timeouty (`timeout=2&read_timeout=2`), żeby zawieszony Redis nie blokował żądań.

### 3. Tryb worker FrankenPHP

W trybie worker aplikacja startuje raz i zostaje w pamięci. Kolejne żądania obsługuje ten sam kontener Symfony z gotowymi serwisami, załadowanymi klasami i otwartymi połączeniami (200 żądań to 2 połączenia z Redisem, a nie 201). Wynik to 2× więcej żądań na sekundę przy 4 workerach, 0 błędów i p95 zamówienia z 976 ms do 42 ms. Symfony 8.1 obsługuje ten tryb natywnie, a włącza go `FRANKENPHP_CONFIG="worker ./public/index.php"` w `Dockerfile`.

**Cena: stan żyje między żądaniami.** Symfony sam resetuje swoje serwisy (Doctrine, Security, sesja). Własne serwisy przejrzałem pod kątem pól, które się zmieniają:

- `ActivityLog::$onBehalfOf` („działaj w imieniu”) przetrwałoby do następnego żądania i zapisało w dzienniku nie tę osobę. Teraz implementuje `ResetInterface`, więc kernel czyści je między żądaniami.
- `AuditListener::$pending` (zmiany zebrane w `onFlush`, zapisywane w `postFlush`) to błąd, który istniał już wcześniej w workerze Messengera. Gdy zapis do bazy się nie udał, lista zostawała, a następny, niezwiązany zapis w tym samym procesie dopisywał do dziennika zmiany z nieudanej operacji. Teraz każdy `onFlush` zaczyna od pustej listy, a do tego działa `ResetInterface`.
- `tests/Shared/LongRunningProcessTest` sprawdza oba przypadki i bez tych poprawek nie przechodzi.
- Ręcznie sprawdzone: żądanie bez sesji zaraz po żądaniu zalogowanego użytkownika trafia na stronę logowania, więc tożsamość nie przecieka.

Symfony restartuje worker po 500 żądaniach (`FRANKENPHP_LOOP_MAX`), co chroni przed powolnymi wyciekami pamięci.

### 4. Liczba workerów

FrankenPHP domyślnie uruchamia dwa workery na każdy widoczny rdzeń. W kontenerze to rdzenie całego węzła, a nie limit poda. Lokalnie dało to 24 kopie aplikacji i 191 MB. Chart ustawia więc stałą liczbę (`web.phpWorkers: 4`): około 1000 żądań na sekundę przy około 100 MB, w limicie 256 MB na pod. Dwa pody web na produkcji to łącznie 8 workerów.

## Co dalej

- Metryki aplikacji (Prometheus) i dashboard (Grafana): czasy i błędy z produkcji, a nie tylko z testu.
- Ten sam test na k3d z limitami zasobów jak na serwerze (1 rdzeń), żeby sprawdzić `web.phpWorkers` w warunkach produkcji.
- Blackfire nie był użyty, bo wymaga konta i agenta. Profilowanie pojedynczych żądań daje lokalnie profiler Symfony (`/_profiler`).
