# Wdrożenie

System ERP działa publicznie pod adresem **https://systemerp.tojest.dev** (konta demo w [README](../README.md#konta-demo)). Ten dokument opisuje, jak kod trafia na serwer, jak serwer jest zbudowany i co robić na co dzień.

## W skrócie

```
programista ── git push ──► GitHub (robZuk/symfony-erp)
                               │
                               ├─► GitHub Actions: lint + testy ──► obraz ──► GHCR (ghcr.io/robzuk/symfony-erp:<commit>)
                               │                                                   │
                               ▼                                                   │ docker pull
                        Argo CD w klastrze ── co ok. 3 min porównuje ──► klaster k3s na serwerze Mikrus
                        (GitOps)              repozytorium z klastrem     (web ×2, worker, PostgreSQL, Redis, RabbitMQ, backup)
```

- **Kod i konfiguracja są w Gicie.** Na serwerze nie wdraża się niczego ręcznie (`kubectl apply`, `helm upgrade`). Wdrożenie to commit na `main`.
- **CI buduje obraz.** Każdy commit na `main`, który przejdzie lint i testy, staje się obrazem z tagiem skrótu commita.
- **Argo CD wdraża.** Pilnuje, żeby klaster wyglądał dokładnie tak, jak opisuje repozytorium: wprowadza zmiany, usuwa to, co zniknęło z repozytorium, i cofa zmiany zrobione ręcznie w klastrze.

## Pliki

| Plik | Co opisuje |
|---|---|
| `Dockerfile` | obraz aplikacji (FrankenPHP, PHP 8.5). Jeden obraz dla serwera WWW, workera i migracji |
| `.github/workflows/ci.yaml` | CI: zadanie `ci` (lint, testy) i zadanie `image` (budowa i publikacja obrazu w GHCR) |
| `deploy/helm/system-erp/` | chart Helma: wszystkie obiekty Kubernetesa aplikacji |
| `deploy/helm/system-erp/values.yaml` | ustawienia domyślne (zasoby, rozmiary dysków, harmonogram backupu) |
| `deploy/helm/system-erp/values-k3d.yaml` | różnice dla lokalnego klastra |
| `deploy/helm/system-erp/values-mikrus.yaml` | różnice dla produkcji: **wersja obrazu**, adres, HTTPS |
| `deploy/argocd/values.yaml` | ustawienia samego Argo CD |
| `deploy/argocd/system-erp.yaml` | aplikacja Argo CD: chart z `values-mikrus.yaml` |
| `deploy/argocd/mikrus.yaml` | aplikacja Argo CD: wszystko z `deploy/mikrus/` |
| `deploy/mikrus/system-erp-secret.yaml` | hasła aplikacji jako SealedSecret (zaszyfrowane, bezpieczne w repozytorium) |
| `deploy/mikrus/traefik-config.yaml` | Traefik ufa nagłówkom `X-Forwarded-*` z sieci podów |
| `deploy/mikrus/https.yaml` | obsługa HTTPS za pośrednikiem Mikrusa (opis niżej) |
| `deploy/server/etc/…` | pliki systemowe serwera, poza Kubernetesem. Ścieżki odpowiadają ich miejscu na serwerze |
| `deploy/k3d/cluster.yaml` | lokalny klaster do testów ([README](../README.md#lokalny-kubernetes-k3d)) |

## Aplikacja w klastrze

| Obiekt | Rodzaj | Rola |
|---|---|---|
| `system-erp-web` | Deployment, 2 pody | serwer WWW. Dwie kopie, więc aktualizacja odbywa się bez przerwy (nowy pod startuje, zanim stary zniknie) |
| `system-erp-worker` | Deployment, 1 pod | `messenger:consume --all --exclude-receivers=failed`: PDF-y faktur i maile w tle, przekazywanie zdarzeń z outboxa do RabbitMQ i obsługa zdarzeń, które subskrybuje ERP. Co godzinę kończy się sam, a Kubernetes uruchamia nowy proces, co chroni przed wyciekami pamięci PHP |
| `system-erp-postgres` | StatefulSet | PostgreSQL 16 ze stałym dyskiem 5 GB |
| `system-erp-redis` | StatefulSet | Redis 8 z hasłem: sesje i cache. Dysk 256 MB na zapis AOF, więc restart nie wylogowuje użytkowników |
| `system-erp-rabbitmq` | StatefulSet | RabbitMQ 4 bez panelu (oszczędność pamięci): exchange `erp.events` i kolejki subskrybentów. Dysk 1 GB, więc oczekujące wiadomości przetrwają restart |
| `system-erp-migrations` | Job | `doctrine:migrations:migrate` przy każdym wdrożeniu. Pody aplikacji czekają na niego w init containerze |
| `system-erp-backup` | CronJob | codziennie o 2:30 UTC zrzut bazy na osobny dysk. Zrzuty starsze niż 7 dni są usuwane |
| `system-erp-invoices` | dysk (PVC) 1 GB | PDF-y faktur, wspólne dla podów web (pobieranie) i workera (generowanie) |

Kolejność wdrażania ustalają adnotacje `argocd.argoproj.io/sync-wave`: najpierw baza, Redis i RabbitMQ, potem migracje, na końcu web i worker. Dyski z fakturami i backupami mają `Prune=false,Delete=false`, więc Argo CD nigdy ich nie usunie, nawet razem z aplikacją.

## Nowa wersja aplikacji

1. Zmiany w kodzie trafiają na `main`. CI uruchamia testy i publikuje obraz `ghcr.io/robzuk/symfony-erp:<skrót commita>`.
2. Gdy zadanie `image` w GitHub Actions się skończy, w `deploy/helm/system-erp/values-mikrus.yaml` zmieniasz `image.tag` na ten skrót i robisz commit.
3. Argo CD zauważa zmianę (do ok. 3 minut), uruchamia migracje i wymienia pody po kolei.

Produkcja zawsze wskazuje konkretny obraz, a nie `latest`, więc wiadomo, który commit działa, i da się wrócić: wystarczy przywrócić poprzedni tag w `values-mikrus.yaml`. Zmiany w samym charcie albo w `deploy/mikrus/` wdrażają się od razu po pushu, bez zmiany tagu.

Stan wdrożenia (na serwerze):

```bash
kubectl -n argocd get applications          # SYNC: Synced = jak w repozytorium, HEALTH: Healthy = działa
kubectl -n argocd describe application system-erp   # szczegóły i błędy ostatniego wdrożenia
kubectl -n system-erp get pods
```

## Droga żądania

```
przeglądarka ──HTTPS──► Cloudflare ──► pośrednik Mikrusa ──HTTP, IPv6──► serwer [::]:80
  ──► traefik-ipv6 (systemd) ──► 127.0.0.1:80 ──► Traefik (k3s) ──► Ingress ──► Service ──► pod web
```

- **HTTPS kończy się w Cloudflare.** Certyfikatami zajmuje się Mikrus, a w klastrze nie ma TLS.
- **Mikrus łączy się z serwerem po IPv6**, a k3s wystawia Traefika tylko na IPv4. Dlatego na serwerze działa gniazdo systemd `traefik-ipv6` (`deploy/server/etc/systemd/system/`), które przekazuje połączenia z `[::]:80` do `127.0.0.1:80`. Docker robi to samo dla swoich kontenerów programem `docker-proxy`.
- **Pośrednik Mikrusa zawsze wysyła `X-Forwarded-Proto: http`** (i `HTTPS: on`), niezależnie od tego, czego użyła przeglądarka. Prawdziwy schemat zna tylko nagłówek Cloudflare `CF-Visitor`. Dlatego `deploy/mikrus/https.yaml`:
  - przekierowuje żądania z `CF-Visitor: {"scheme":"http"}` na `https://`, zanim dotrą do aplikacji,
  - wszystkim pozostałym ustawia `X-Forwarded-Proto: https` i `X-Forwarded-Port: 443`. Bez tego Symfony budowałoby adresy `http://…` albo `https://…:80`, a ciasteczko sesji nie miałoby flagi `secure`.
- **`X-Forwarded-For` niesie prawdziwy adres odwiedzającego.** Traefik mu ufa (`deploy/mikrus/traefik-config.yaml`), a Symfony ufa Traefikowi (`SYMFONY_TRUSTED_PROXIES`). Blokada po nieudanych logowaniach liczy więc każdy adres osobno.

## Hasła (Sealed Secrets)

Aplikacja czyta `APP_SECRET`, `POSTGRES_PASSWORD`, `REDIS_PASSWORD`, `RABBITMQ_PASSWORD` i `MAILER_DSN` z Secretu `system-erp`. W repozytorium leży jego zaszyfrowana wersja, SealedSecret. Szyfruje się ją kluczem publicznym klastra, a odszyfrować może tylko kontroler Sealed Secrets w tym klastrze, kluczem prywatnym, który nigdy go nie opuszcza.

Zmiana albo dodanie hasła (na serwerze). Generuje się od razu cały Secret, z wszystkimi kluczami:

```bash
kubectl create secret generic system-erp --namespace system-erp \
  --from-literal=APP_SECRET="$(openssl rand -hex 32)" \
  --from-literal=POSTGRES_PASSWORD='<obecne hasło bazy>' \
  --from-literal=REDIS_PASSWORD="$(openssl rand -hex 32)" \
  --from-literal=RABBITMQ_PASSWORD='<obecne hasło RabbitMQ albo nowe przy pierwszym wdrożeniu>' \
  --from-literal=MAILER_DSN='smtp://…' \
  --dry-run=client -o yaml | kubeseal --format yaml > /root/system-erp-secret.yaml
```

Potem plik trafia do `deploy/mikrus/system-erp-secret.yaml` przez commit. Jawne hasła nie lądują na dysku: `--dry-run` niczego nie tworzy, a `kubeseal` od razu szyfruje.

- **Hasło RabbitMQ działa tak samo**: broker zakłada użytkownika tylko przy pierwszym starcie. Obecne odczytasz tym samym poleceniem z kluczem `RABBITMQ_PASSWORD`. Hasło Redisa można zmieniać dowolnie, bo Redis czyta je przy każdym starcie.
- **Hasło bazy zostaw takie, jakie jest.** PostgreSQL ustawia je tylko przy pierwszym starcie z pustym dyskiem. Obecne odczytasz z klastra: `kubectl -n system-erp get secret system-erp -o jsonpath='{.data.POSTGRES_PASSWORD}' | base64 -d`.
- Hasła mają być szesnastkowe (`openssl rand -hex`), bo trafiają do adresów `DATABASE_URL` i `LOCK_DSN`, w których znaki `@ / : #` mają specjalne znaczenie.
- **Kopia klucza prywatnego** jest w KeePassXC. Bez niej po utracie serwera nie da się odszyfrować sekretów z repozytorium. Kontroler co 30 dni dodaje nowy klucz, więc kopię trzeba odświeżać:
  ```bash
  kubectl -n kube-system get secret -l sealedsecrets.bitnami.com/sealed-secrets-key -o yaml > sealed-secrets-key.yaml
  ```

## Dane i kopie zapasowe

Zrzuty bazy leżą na dysku `system-erp-backup`, czyli na serwerze w katalogu `/var/lib/rancher/k3s/storage/pvc-…_system-erp_system-erp-backup/`, jako pliki `app-RRRRMMDD-GGMMSS.sql.gz`.

Odtworzenie bazy ze zrzutu (na serwerze). Zrzut z CronJoba nie usuwa istniejących tabel, więc przed nim czyścimy schemat. Całość działa w jednej transakcji: zastępuje całą bazę, a przy błędzie nie zmienia nic:

```bash
{ echo 'DROP SCHEMA public CASCADE; CREATE SCHEMA public;'
  gunzip -c /var/lib/rancher/k3s/storage/pvc-…_system-erp_system-erp-backup/app-….sql.gz
} | kubectl -n system-erp exec -i system-erp-postgres-0 -- \
    psql -U app -d app --single-transaction -v ON_ERROR_STOP=1
```

Kopie leżą na tym samym serwerze. Chronią przed błędem w danych, ale nie przed utratą serwera, więc warto je okresowo pobierać (`scp`).

**Dane demo** na produkcji to kopia lokalnej bazy deweloperskiej (`make demo`). Obraz produkcyjny nie zawiera fixtures, bo to zależność deweloperska. Przeniesienie:

```bash
# lokalnie: baza bez sesji, cache i kolejki oraz PDF-y faktur
docker compose exec -T database pg_dump -U app -d app --clean --if-exists --no-owner --no-privileges \
  --exclude-table-data=sessions --exclude-table-data=cache_items --exclude-table-data=messenger_messages > demo.sql
tar -czf invoices.tar.gz -C var/invoices/dev .
scp -P 10250 demo.sql invoices.tar.gz root@eve250.mikrus.xyz:/root/

# na serwerze
kubectl -n system-erp exec -i system-erp-postgres-0 -- psql -U app -d app --single-transaction -v ON_ERROR_STOP=1 < /root/demo.sql
kubectl -n system-erp exec -i deploy/system-erp-web -- \
  sh -c 'mkdir -p /app/var/invoices/prod && tar -xzf - -C /app/var/invoices/prod' < /root/invoices.tar.gz
```

Schemat lokalnej bazy musi być w tej samej wersji co na produkcji (porównaj `doctrine_migration_versions`). Konta demo są publiczne, więc odwiedzający mogą zmieniać dane. Ponowne wczytanie zrzutu przywraca stan wyjściowy.

## Serwer

Mikrus `eve250`: Ubuntu 24.04, 1 rdzeń, 4 GB RAM, 40 GB dysku, publiczny IPv6. Serwer jest **nieuprzywilejowanym kontenerem LXC** na Proxmoxie. Ma wspólne jądro z gospodarzem, a „root” w środku nie jest rootem gospodarza. Działają na nim też inne aplikacje w Docker Compose. k3s ma własny containerd i ich nie dotyka.

Jednorazowa instalacja, w tej kolejności (jako root na serwerze):

1. **Pliki z `deploy/server/etc/`** skopiowane do `/etc/`:
   - `tmpfiles.d/kmsg.conf`: w LXC nie ma `/dev/kmsg`, bez którego kubelet nie startuje, więc w jego miejsce podstawiany jest `/dev/console`,
   - `rancher/k3s/config.yaml`: kubelet toleruje brak pełnego roota (`KubeletInUserNamespace`), kube-proxy nie zmienia limitów `conntrack`, wyłączone są polityki sieciowe (brak `ipset`),
   - `systemd/system/traefik-ipv6.*`: przekierowanie IPv6 opisane wyżej.
   ```bash
   systemd-tmpfiles --create /etc/tmpfiles.d/kmsg.conf
   systemctl daemon-reload && systemctl enable --now traefik-ipv6.socket
   ```
2. **k3s** w wersji z `deploy/k3d/cluster.yaml`. Instalator sam czyta `/etc/rancher/k3s/config.yaml`:
   ```bash
   curl -sfL https://get.k3s.io -o install-k3s.sh && INSTALL_K3S_VERSION=v1.35.5+k3s1 sh install-k3s.sh
   ```
   Komunikat `modprobe br_netfilter … FAILURE` przy starcie jest oczekiwany: w LXC nie można ładować modułów jądra, a ten moduł ładuje już gospodarz.
3. **Helm** (v4.3.0, z `https://get.helm.sh`, z kontrolą sumy SHA256) oraz `export KUBECONFIG=/etc/rancher/k3s/k3s.yaml` w `~/.bashrc`.
4. **Sealed Secrets** (chart 2.20.0) i narzędzie `kubeseal` 0.40.0:
   ```bash
   helm install sealed-secrets sealed-secrets/sealed-secrets --repo https://bitnami.github.io/sealed-secrets \
     --version 2.20.0 --namespace kube-system --set-string fullnameOverride=sealed-secrets-controller
   ```
   Przy odtwarzaniu serwera najpierw `kubectl apply -f sealed-secrets-key.yaml` z KeePassXC, żeby kontroler użył starych kluczy.
5. **Argo CD** (chart 10.9.6, Argo CD v3.5.3) z `deploy/argocd/values.yaml`. Tym samym poleceniem aktualizuje się jego ustawienia:
   ```bash
   helm upgrade --install argocd argo-cd --repo https://argoproj.github.io/argo-helm --version 10.9.6 \
     --namespace argocd --create-namespace \
     -f https://raw.githubusercontent.com/robZuk/symfony-erp/main/deploy/argocd/values.yaml
   ```
6. **Rejestracja aplikacji**, jedyne ręczne `kubectl apply`. Dalej wszystko robi Argo CD:
   ```bash
   kubectl apply -f https://raw.githubusercontent.com/robZuk/symfony-erp/main/deploy/argocd/mikrus.yaml \
                 -f https://raw.githubusercontent.com/robZuk/symfony-erp/main/deploy/argocd/system-erp.yaml
   ```
7. W panelu Mikrusa subdomena `systemerp.tojest.dev` wskazuje port **80** serwera.

Argo CD nie zarządza sam sobą ani Sealed Secrets. Oba zainstalowano przez Helma i aktualizuje się je tak samo. Panel Argo CD nie jest wystawiony do internetu. W razie potrzeby otwiera się go tunelem SSH:
`ssh -p 10250 -L 8443:127.0.0.1:8080 root@eve250.mikrus.xyz kubectl -n argocd port-forward svc/argocd-server 8080:443` i przeglądarką https://localhost:8443 (użytkownik `admin`, hasło w Secrecie `argocd-initial-admin-secret`).

Zużycie zasobów po uruchomieniu wszystkiego: ok. 1,7 GB pamięci z 4 GB (k3s ok. 0,6 GB, Argo CD ok. 0,35 GB, aplikacja ok. 0,3 GB, reszta to pozostałe aplikacje serwera).

## Diagnostyka

| Objaw | Gdzie patrzeć |
|---|---|
| aplikacja `OutOfSync` / `Degraded` | `kubectl -n argocd describe application system-erp`, sekcje `Conditions` i `Operation State` |
| pod nie startuje | `kubectl -n system-erp describe pod <pod>`, sekcja `Events` |
| błąd aplikacji | `kubectl -n system-erp logs deploy/system-erp-web` (`-f` na bieżąco, `--previous` dla poprzedniego kontenera) |
| migracje | `kubectl -n system-erp logs job/system-erp-migrations` |
| k3s | `journalctl -u k3s --no-pager \| tail -50` |
| strona nie odpowiada | `curl -I http://[2a01:4f9:3071:128b::250]/` na serwerze: `404` oznacza, że Traefik działa, a problem leży w routingu |

Licznik `RESTARTS` workera rośnie o ok. 1 na godzinę. To zamierzone (`--time-limit=3600`).

## Ograniczenia i dalsze kroki

- **Jeden węzeł.** Awaria serwera zatrzymuje wszystko, a dyski `ReadWriteOnce` (wspólne PDF-y) działają tylko dlatego, że wszystkie pody są na jednym węźle.
- **Port 80 po IPv6 jest dostępny z pominięciem Cloudflare.** Kto zna adres serwera, może podać fałszywy `X-Forwarded-For`. Rozwiązaniem byłaby reguła zapory przepuszczająca tylko adresy Cloudflare.
- **Tag obrazu zmienia się ręcznie.** Mógłby to robić krok w CI albo Argo CD Image Updater.
- **Poczta wyłączona** (`MAILER_DSN=null://null`). Wystarczy nowy SealedSecret z prawdziwym serwerem SMTP.
- **Publiczne demo nie resetuje się samo.** Nocne wczytywanie zrzutu demo przywracałoby stan wyjściowy.
- **Kopie zapasowe są na tym samym serwerze**, a nie poza nim.
