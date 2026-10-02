# SmartStay ONE — Instrucțiuni

> **Ultima actualizare**: 2 octombrie 2026 (21:45)
> **Versiune**: 1.3.3 (Menajeră: + Rezervări citire, Inventar, Rapoarte doar curățeniile ei) · 1.3.2 (Rapoarte · Prezentare extinsă: perioade, KPI, per apartament, trend lunar; opțiunile numărate)
> **Țintă**: `one.smartstay.ro` — producție directă, fără staging
> **Local**: `/Users/romeo/Projects/SmartStay/smartstay-one` — același path pe Mac Mini și Mac Studio
> **Stack**: PHP 8.2+ · PDO · MariaDB 11.4 · vanilla JS · cPanel shared hosting

---

## 0. Stare curentă

✅ Repo `zlkstudio/smartstay-one` pe GitHub (main) · `one.smartstay.ro` live, docroot `/public`, SSL
✅ Etapa 2 pe GitHub — `e73fe09` (push de pe Mac Studio)
✅ Mac Studio configurat: cheie `id_ed25519_one` + alias `github-one`, clone în `~/Projects/SmartStay/smartstay-one`
✅ Server: `php bin/doctor.php` → 4/4 baze conectate (inclusiv `smartconcept_reservations`), menajere potrivite
✅ Server: `previo.php`, `nuki.php`, `database-reservations.php` completate
🔲 Deploy key server re-adăugat pe GitHub (`ssh -T git@github-one` → „Hi zlkstudio/smartstay-one!")
🔲 `checkin-sync.php` completat + `./deploy-one.sh production` → doctor arată **1.1.0-stage2** și `whatsapp_outreach`
🔲 Test pe telefon: Rezervări (toggle, Nuki) + Curățenie (alocare, checklist cu menajera)
🔲 Conturi menajere (rol Menajeră + numele din listă)
🔲 Rotit tokenul Nuki (a apărut în clar într-o sesiune de lucru pe 02.10.2026) — în ONE, reservations și guest-app
🔲 Mac Mini: `git fetch && git reset --hard origin/main` (commit local `a178b0a` = același conținut, alt hash)
✅ Etapa 3 scrisă și testată local (MariaDB + Previo simulat) — patch `0003-etapa-3`
🔲 Repo-ul `smartstay-one` e **PUBLIC** pe GitHub → Settings → General → Danger Zone → *Change visibility* → Private (fără secrete în el, dar are logica internă)
🔲 `git am` patch-ul Etapei 3 → push → `./deploy-one.sh production` → doctor arată **1.2.0-stage3**
🔲 Cron orar în cPanel pentru `bin/reports-cron.php` (§6.5)
🔲 `php bin/previo-fields.php` → confirmă câmpul de status (anulări) și de preț (venituri, Etapa 4)
🔲 Verificat raportul de plată ONE vs. raportul vechi pe săptămâna trecută (aceleași totaluri)
✅ Clean-up 1.2.1 scris — patch `0004-clean-up` (vezi §8.1)
🔲 `git am` patch 0004 → push → deploy → doctor arată **1.2.1-cleanup**
🔲 Setări (desktop) → Yale Nuki → **Verifică în Nuki** — motivul exact pentru Ap. 424
🔲 App-ul vechi Rezervări: adaugă `'400' => '18045779828'` în `reservations/config/nuki-smartlocks.php` (cronul de auto-Nuki e acolo)
➡️ Următorul pas: **Etapa 4** — Setări apartamente (tarife, praguri stoc, checklist, listă apartamente în UI), venituri din Previo, migrare conturi reale

---

## 1. Ce e ONE

Un singur login pentru aplicațiile interne (Rezervări, Housekeeping, Inventar) + Rapoarte.
ONE **nu mută date**: citește și scrie direct în bazele existente ale fiecărei aplicații,
plus o bază nouă `smartconcept_one` pentru conturi, sesiuni, audit, marcaje WhatsApp și cache de rapoarte.
Aplicațiile vechi rămân online tot timpul tranziției și lucrează pe aceleași rânduri.

| Etapă | Conținut | Stare |
|---|---|---|
| **1 · Fundația** | login, 4 roluri, middleware, sesiuni 90 zile, PWA + instalare, utilizatori, stare sistem | ✅ live |
| **2 · Rezervări + Housekeeping** | portare 1:1, Previo + Nuki în `src/Integrations/` | ✅ live |
| **3 · Inventar + Rapoarte** | stoc + Necesar, ocupare, canale, plata menajerelor, cron `report_cache`, indicatori pe Acasă | ✅ gata de deploy |
| 4 · Setări apartamente, venituri, migrare conturi reale | regulile din `Properties.php` / `Checklist.php` / `Rates.php` / `Stock.php` → UI | următoarea |

---

## 2. Structura

```
smartstay-one/
├── public/                         ← DOCUMENT ROOT
│   ├── index.php                   front controller: headere securitate, TOATE rutele
│   └── assets/css/ app.css · modules.css (toate modulele)
│       assets/js/  app.js · reservations.js · housekeeping.js · checklist.js · inventory.js · reports.js · home.js
├── src/
│   ├── Auth/ Http/ Db/ Users/ System/ Audit.php     (Etapa 1)
│   ├── Properties.php              parcări (nu sunt apartamente), telefon RO / WhatsApp
│   ├── Stays.php                   șederi din Previo (−90 / +30 zile), cache 5 min în storage/cache — Inventar, Acasă, Rapoarte
│   ├── Integrations/
│   │   ├── Previo.php              searchReservations (XML), fallback contactPerson → guest
│   │   ├── Nuki.php                PUT /smartlock/{id}/auth, 409 = succes, log storage/logs/nuki.log
│   │   ├── NukiCode.php            codul din telefon (identic cu Guest App)
│   │   └── GuestAppSync.php        toggle Check-in → admin_mark_checkin.php (deblochează codul)
│   ├── Reservations/  ReservationFeed · StatusRepository · OutreachRepository
│   ├── Housekeeping/  HousekeepingFeed · CleaningRepository · Checklist · ChecklistMailer · Rates (tarife plată)
│   ├── Inventory/     InventoryRepository · Stock (praguri lenjerii)
│   ├── Reports/       MaidPayments · OperationsReport · ReportCache
│   └── Controllers/   Auth, Page, Users, Reservations, Housekeeping, Inventory, Reports
├── views/pages/ reservations/ · housekeeping/ · inventory/index.php · reports/{overview,payments}.php
├── sql/ 001_one_schema.sql · 002_stage2.sql (whatsapp_outreach)
├── bin/ migrate.php  create-user.php  doctor.php  reports-cron.php  previo-fields.php
├── config/                         *.example.php în git; *.php reale DOAR pe server
├── storage/logs/ · storage/cache/  app.log, nuki.log, cron.log · stays-AZI.json (nume oaspeți — nu iese din storage)
└── deploy/deploy-one.sh
```

⚠️ NU pune `.htaccess` cu „Require all denied" în rădăcina repo-ului (blochează și `public/` → 403, incident 01.10.2026).

---

## 3. Baze de date

| Bază | Config | Ce face ONE acolo |
|---|---|---|
| `smartconcept_one` | `database-one.php` | users, permissions, sessions, login_attempts, audit_log, report_cache, **whatsapp_outreach** |
| `smartconcept_reservations` | `database-reservations.php` | citește/scrie `reservation_status` + `reservation_status_log` (aceleași rânduri ca app-ul vechi) |
| `smartconcept_cleaning` | `database-cleaning.php` | `maid_assignments`, `cleaning_records`, `checklist_submissions` (aceleași ca app-ul vechi) |
| `smartconcept_inventoryStay` | `database-inventory.php` | `inventar_apartamente`: doar cele 5 contoare + `necesar`. Coloanele Previo vechi (`check_in_date`, `guest_name`…) nu sunt atinse |

`maid_name` rămâne numele afișat („Ioana") — exact cum scrie app-ul vechi, ca rapoartele de plată să meargă în paralel.

---

## 4. Autentificare și roluri

**Matricea** (`src/Auth/Access.php`):

| Rol | Rezervări | Housekeeping | Inventar | Rapoarte | Utilizatori/Setări |
|---|---|---|---|---|---|
| Admin | edit | edit | edit | edit | edit |
| Manager | edit | edit | edit | edit | — |
| Menajeră | view | check-out-urile de azi, se alocă doar pe ea | edit | doar curățeniile ei | — |
| Utilizator | după `permissions` | idem | idem | idem | — |

Ce înseamnă în Etapa 2 (verificat pe server, nu doar în UI):
- **view**: vede listele; toggle-uri, Nuki, „Trimis" WhatsApp, alocări și checklist sunt blocate (API → 403).
- **edit**: tot ce făcea app-ul vechi.
- **Menajera** (din 1.2.1): vede check-out-urile de azi **fără numele oaspeților**, își preia apartamentele libere
  („Preiau") și renunță doar la ale ei, încă nefăcute. Serverul refuză alocarea pe altă menajeră, un apartament deja luat
  sau unul fără check-out azi. Checklist doar pentru apartamentele ei. `/housekeeping/intermediate` → 403.
- **Menajera** (din 1.3.3): are bara de navigare (Rezervări · Curățenie · Inventar · Rapoarte, fără Acasă — aterizează tot pe
  Curățenie). Rezervări = **view** (vede lista, inclusiv numele oaspeților; fără toggle-uri / Nuki / „Trimis").
  Inventar = **edit** (+/−, Necesar, verso). Rapoarte: `/reports` → redirect la `/reports/payments` = „Curățeniile mele",
  filtrat în SQL după `maid_name` din `maid_ref`; fără „Checklist x2", fără „tarif implicit", fără tab-uri, WhatsApp sau
  adăugare/ștergere. `/api/reports/today` → 403.
  Admin / Manager / Utilizator cu edit: alocă oricărei menajere, ca înainte.
- Fiecare acțiune de modul ajunge în `audit_log` (`reservation.status`, `reservation.nuki`, `housekeeping.*`).
  Pagina Utilizatori arată doar activitatea pe conturi.

---

## 5. PWA

Ca în Etapa 1. Service worker-ul cache-uiește doar `/assets/` (URL cu `?v=filemtime`);
HTML și `/api/` merg mereu la rețea. Paginile de modul încarcă CSS/JS prin `$styles` / `$scripts` din controller.

---

## 6. Server

> Producție directă: `one.smartstay.ro` → `~/one.smartstay.ro`, docroot `/public`. Fără staging.

| Element | Valoare |
|---|---|
| Cod pe server | `~/one.smartstay.ro/` · sursă git `~/source/smartstay-one/` · script `~/deploy-one.sh` |
| Config-uri (`chmod 600`, protejate de deploy) | `app.php`, `database-{one,cleaning,inventory,reservations}.php`, `previo.php`, `nuki.php`, `checkin-sync.php` |
| Cheie GitHub server | `~/.ssh/id_ed25519_one` · alias `github-one` · deploy key read-only |

### 6.1–6.2 Prima instalare, primul admin
Neschimbate față de Etapa 1 (vezi istoricul acestui fișier pe GitHub).

### 6.3 Mac-uri (Mac Mini + Mac Studio) — același path
Fiecare Mac are cheia lui, adăugată ca **deploy key cu write access** pe `zlkstudio/smartstay-one`
(cheile repo-urilor vechi sunt per repo → „Repository not found" / „Could not resolve hostname github-one").

| Mac | Cheie | Deploy key pe GitHub |
|---|---|---|
| Mac Mini | `~/.ssh/id_ed25519_one` | `macmini-one` |
| Mac Studio | `~/.ssh/id_ed25519_one` | `Mac Studio` |

Mac nou:
```bash
ssh-keygen -t ed25519 -f ~/.ssh/id_ed25519_one -N "" -C "<mac>-one"
cat >> ~/.ssh/config <<'EOF2'

Host github-one
  HostName github.com
  User git
  IdentityFile ~/.ssh/id_ed25519_one
  IdentitiesOnly yes
EOF2
pbcopy < ~/.ssh/id_ed25519_one.pub    # GitHub → smartstay-one → Settings → Deploy keys → ✅ Allow write access
ssh -T git@github-one                 # „Hi zlkstudio/smartstay-one!"
mkdir -p /Users/romeo/Projects/SmartStay && cd /Users/romeo/Projects/SmartStay
git clone git@github-one:zlkstudio/smartstay-one.git
cd smartstay-one && git config user.name "Romeo" && git config user.email "contact@radoiromeo.ro"
```
Config-urile cu parole nu sunt în git și nu se copiază pe Mac.

**Regula cu două Mac-uri**: înainte să lucrezi, `git pull` (sau `git fetch && git reset --hard origin/main`
dacă ai commit-uri locale deja trimise ca patch de pe celălalt Mac). Lucrul nepush-uit rămâne doar pe Mac-ul acela.

### 6.4 Etapa 2 — o singură dată pe server
```bash
ssh smartstay
cd ~/source/smartstay-one && git fetch && git reset --hard origin/main
cp deploy/deploy-one.sh ~/ && chmod +x ~/deploy-one.sh     # scriptul nou protejează checkin-sync.php

T=~/one.smartstay.ro/config; S=~/source/smartstay-one/config
for f in previo nuki checkin-sync; do [ -f $T/$f.php ] || cp $S/$f.example.php $T/$f.php; done
chmod 600 $T/*.php
nano $T/previo.php        # username + password din ~/smartstay.ro/guest-app/config/previo.php
nano $T/nuki.php          # api_token din reservations/config/nuki.php + smartlocks din nuki-smartlocks.php
nano $T/checkin-sync.php  # identic cu ~/smartstay.ro/reservations/config/checkin-sync.php
# database-reservations.php: același user ca Housekeeping, doar alt nume de bază
cp $T/database-cleaning.php $T/database-reservations.php
sed -i "s/smartconcept_cleaning/smartconcept_reservations/" $T/database-reservations.php
#   „Access denied" în doctor → user/parolă din ~/smartstay.ro/reservations/config/database.php (DB_USER/DB_PASS)
nano $T/app.php           # adaugă cheile noi din config/app.example.php: guest_app_url, housekeeping

cd ~ && ./deploy-one.sh production     # aplică sql/002_stage2.sql
cd ~/one.smartstay.ro && php bin/doctor.php
```
`app.php` nou (opțional — au valori implicite în cod):
```php
'guest_app_url' => 'https://smartstay.ro/guest-app',
'housekeeping'  => ['report_to' => 'cleaning@smartconceptliving.ro', 'from' => 'no-reply@smartconceptliving.ro', 'from_name' => 'SmartStay Cleaning System'],
```

---

### 6.5 Etapa 3 — o singură dată pe server
```bash
# Mac: aplică patch-ul și urcă-l
cd /Users/romeo/Projects/SmartStay/smartstay-one && git pull
git am ~/Downloads/0003-etapa-3-inventar-rapoarte.patch && git push origin main
git log origin/main --oneline -1               # „Etapa 3: Inventar + Rapoarte…"

# Server
ssh smartstay
./deploy-one.sh production                      # nicio migrare nouă; doctor → 1.3.0
cd ~/one.smartstay.ro
php bin/reports-cron.php                        # primul calcul (✔ operations: …)
php bin/previo-fields.php                       # ce câmpuri dă Previo (doar căi, fără valori)
```
Nu e nevoie de config nou. Opțional în `config/app.php`: `'apartments' => ['5','33','40',…]` — lista exactă pentru ocupare
(fără ea, ONE numără apartamentele cu rezervări în ultimele ~90 de zile).

**Cron** — cPanel → Cron Jobs → *Once Per Hour*, minutul 7:
```
7 * * * * /usr/local/bin/php /home/smartconcept/one.smartstay.ro/bin/reports-cron.php >> /home/smartconcept/one.smartstay.ro/storage/logs/cron.log 2>&1
```
Verificare: `tail -3 ~/one.smartstay.ro/storage/logs/cron.log` și Setări → Rapoarte („Cronul orar rulează normal").
Fără cron rapoartele merg oricum (se calculează la deschidere, max. o dată pe oră), doar mai lent.

---

## 7. Deploy — rutina

```bash
# Mac (Mini sau Studio)
cd /Users/romeo/Projects/SmartStay/smartstay-one
git pull                                   # întâi ia ce s-a urcat de pe celălalt Mac
git push origin main && git log origin/main --oneline -1
# Server
ssh smartstay && ./deploy-one.sh production    # rollback: ./deploy-one.sh rollback production
```
Patch-uri primite din chat: `git am ~/Downloads/000X-….patch && git push origin main`, apoi deploy.
Un patch aplicat pe un Mac creează alt hash decât același patch pe celălalt → pe al doilea Mac: `git fetch && git reset --hard origin/main`.
⚠️ Scriptul face `git reset --hard origin/main` — commit-urile nepush-uite nu ajung pe server.

---

## 8. Ce s-a portat

### Rezervări (`/reservations`, `/reservations/tomorrow`, `/reservations/whatsapp`, `/reservations/link`)
- **Astăzi / Mâine**: carduri cu nume, telefon (copiere), apartament, check-in/out, parcare atașată
  (telefon → nume, niciodată card separat), avertizare > 2 oaspeți, nota de housekeeping curățată,
  toggle **Taxă oraș** + **Check-in form** (optimist, cu revenire la eroare), filtre + căutare.
- Toggle Check-in → `GuestAppSync` (deblochează/reblochează codul); taxa se resincronizează doar dacă check-in-ul e complet.
- **Nuki**: serverul recalculează codul din Previo (browserul nu alege ce ajunge pe yală). 409 = deja pus.
- **WhatsApp**: mesaj Welcome + formular check-in (RO/EN după prefix); pagina de review: check-out azi / -7 / -14 zile,
  mesaje pe platformă (Booking, Airbnb, Expedia, TravelMinit, Google), „Trimis" salvat în `whatsapp_outreach` (vizibil pe toate telefoanele, cu cine/la ce oră).
- **Link**: ultimele 4 zile, format Dinamic / Legacy (identic cu cel vechi), Copiază / Deschide / WhatsApp.

### Housekeeping (`/housekeeping`, `/housekeeping/intermediate`, `/housekeeping/checklist/{apt}`)
- **Check-out** (staff): apartamentele cu check-out azi (fără parcări), status per apartament
  (Nealocat / menajera / Checklist trimis / Finalizat), selectare multiplă → buton menajeră, „Anulează" alocarea.
  Un apartament aparține unei singure menajere pe zi (realocarea mută rândul *pending*).
- **Intermediară**: oaspeții cazați acum, 30 RON fix, `cleaning_type = 'intermediate'`.
- **Menajera**: lista ei de azi → checklist (secțiuni + sarcini per apartament, studiourile fără Living),
  3 poze obligatorii din zone alese aleator (comprimate în telefon, 1920px / JPEG 0.8), max **2** trimiteri
  per apartament + zi, **doar prima se plătește**, e-mail cu pozele la `cleaning@smartconceptliving.ro`.
- Staff-ul poate completa checklist-ul în numele menajerei alocate.

### Diferențe intenționate față de app-urile vechi
- Auto-Nuki din browser (localStorage) **nu** s-a portat: cronul `reservations/cron/auto-send-nuki.php` îl face pe server, la ora de check-in. Rămâne activ în app-ul vechi.
- Data de azi vine de pe server (Europe/Bucharest), nu din `toISOString()` (UTC) ca în `housekeeping/index.php`.
- Linkul Guest App de pe card alege limba după telefon (vechiul punea mereu `lang=ro`).
- Parcările: o singură listă (`Properties::PARKING_UNITS` = 58, 88, 143, 165, 166, 167, 174, 192).
- Pozele se validează după conținut (nu după extensie — iPhone trimite nume `.HEIC`).
- Dacă e-mailul checklist-ului nu pleacă, trimiterea rămâne salvată și menajera vede mesajul (vechiul arunca eroare și consuma a doua trecere).
- Marcajele WhatsApp vechi (`reservations/data/whatsapp_contacted.json`) nu se importă.

### Inventar (`/inventory`) — Etapa 3
- Carduri din `inventar_apartamente` (instant), apoi statusul de azi din Previo (Check-out / Check-in cu oră + nume, „Oaspete cazat").
- Sortare: libere / cu check-in sau check-out azi primele, după lenjerii crescător (critice sus) · cazați după · Boxa ultima.
- Filtre Toate / Pe roșu / Check-in azi / Check-out azi + căutare după apartament sau oaspete. `/inventory?filter=critical` deschide direct roșii.
- +/− **atomic** (un singur `UPDATE`, nu citește-apoi-scrie) — două telefoane care apasă simultan nu mai pierd apăsări. Afișare optimistă, cereri în coadă per articol.
- Praguri lenjerii (`src/Inventory/Stock.php`): studio ≤1 roșu · 2 galben · ≥3 verde; 187, 594 ≤3 / 4 / ≥5; Boxa <5 / 5–12 / >12.
- „Necesar" se salvează singur la 0,8 s după ultima tastă și la ieșirea din câmp.
- Fiecare modificare intră în `audit_log` (`inventory.adjust`, `inventory.note`, `inventory.batch`, `inventory.tech`); cardul arată „Modificat de X · ora".
- **Verso** (butonul ⟳ din colț, flip 2D — fără 3D, care îngheață scroll-ul pe iOS):
  „Scade un set" (−1 lenjerie, −2 fețe pernă, −2 prosoape mari, −1 mic, −1 picioare) și „Adaugă o cutie" (+4, +8, +8, +4, +4),
  într-o singură tranzacție, cu **Anulează** 8 s (pune înapoi exact ce s-a scăzut, chiar dacă un articol era la 0).
  Rubrica **Tehnic**: bifa TV App (salvare imediată) + notă cu autosave. Aceleași coloane ca aplicația veche (`tv_app`, `tehnic`).
  Pe față apare insigna „Tehnic" când nota tehnică nu e goală.

### Rapoarte (`/reports`, `/reports/payments`) — Etapa 3
- **Prezentare**: azi (libere la noapte / ocupate / check-in / check-out + lista libere), ocupare pe nopți (30 în urmă, 14 rezervate înainte), canale pe 30 de zile (donut + rezervări + nopți). Cache `report_cache` (cheia `operations`), recalculat de cron sau de butonul ↻ (doar edit).
- **Plata menajerelor** (1.3.2): buton „Trimite pe WhatsApp" per menajeră — toate curățeniile (zi, apartament, tarif) + total, format WhatsApp; numărul din contul ei ONE (fallback `maid_phones` în config). Fără „Checklist x2" / „tarif implicit" în mesaj. 33 = studio (50 RON).
- **Plata menajerelor**: implicit săptămâna trecută (L–D), plus săptămâna/luna curentă/trecută și interval liber (max. 93 zile). Tarif recalculat la fiecare afișare din `src/Housekeeping/Rates.php`; „✓✓ Checklist x2" doar aici; intermediare cu chip violet. Edit: adăugare manuală (menajeră, apartament, dată, tip) și ștergere — ambele în `audit_log`. „Copiază rezumatul" per menajeră (pentru WhatsApp).
- **Acasă**: cardul „Azi" (din același cache, max. 15 min) + „N apartamente cu lenjerii pe roșu" pentru cine are Inventar.

### Rapoarte · Prezentare extinsă (1.3.x)
- Selector de perioadă sticky: Azi · 7 zile · 30 zile · Luna asta (toată luna + până azi) · Luna trecută · Anul ăsta · Interval (`?p=` sau `?from=&to=`). Un singur parametru: `src/Reports/Period.php`.
- Toate cifrele vin din `src/Reports/Analytics.php` (singurul modul de agregare).
- Secțiuni: Operațional azi · Indicatori (ocupare, ADR, RevPAR, venit + comparație) · Ocupare · Venit · Canale (rezervări / nopți / venit) · ADR pe canal · Ocupare pe zile ale săptămânii · Durata șederilor · Apartamente (sortate după venit, tap → detalii) · Trend lunar (venit + ocupare, ADR + RevPAR, tabel) · Oaspeți.
- Doar date care vin din Previo: fără TRevPAR / TRevPP, anulări sau rezervări create (API-ul nu le trimite), fără texte explicative pe pagină.
- 40 și Daily nu se numără niciodată. Fiecare apartament intră în calcul de la prima noapte rezervată (ex. 33 din august); apartamentele fără ocupare în perioadă nu apar la „Apartamente”.
- Opțiunile (statusId 1) = rezervări plătite cash la check-out → intră peste tot.
- Venit = `reservation/price` împărțit pe nopți (day-use: pe ziua de check-in). TVA: `reports.vat_rate` în `config/app.php`. Verificare: `php bin/report-check.php`.
- Istoric: `Stays::year()` — check-in-uri 2 nov anul anterior → 31 dec, în bucăți de 3 luni, cache `storage/cache/history-v1-{an}.json` (1 h / 24 h); cronul orar îl reîncălzește.

### Diferențe intenționate (Etapa 3)
- Apartamentele fără tarif apar marcate **„tarif implicit"** + avertizare sus (vechiul le plătea tăcut cu 60 RON). Totalul rămâne identic cu raportul vechi.
- ONE nu mai scrie coloanele Previo în `inventar_apartamente`; le citește live (cache 5 min). Butonul „Sync Previo" din aplicația veche rămâne pentru ea.
- Trimiterea raportului pe e-mail nu s-a portat (înlocuită de „Copiază rezumatul"). Se poate adăuga dacă e nevoie.
- **Statusuri Previo** (verificat 02.10.2026 pe 517 rezervări, `php bin/previo-fields.php --status 60`): `1` opțiune (are `optionExpiration`),
  `2` confirmată, `3` cazat, `9` plecat. Anulările nu sunt întoarse deloc de `searchReservations`. Opțiunile **intră** în ocupare, canale și statusul din Inventar
  (ca în Previo Overview/Dashboard; schimbat 02.10.2026 după ap. 187 afișat liber cu opțiune în casă). `Stays` le marchează `option=true`,
  iar Rapoarte listează separat apartamentele ocupate doar cu opțiune. Canalul vine din `partner/name` (Booking.com XML, AirBnB, Szallas GROUP, Expedia; RESERVATION+ și fără partener → „Direct / altele").
- Veniturile (`reservation/price`, RON) intră în Etapa 4.

---

### 8.1 Clean-up 1.2.1
- **Logo** PNG (`assets/img/logo.png` + `logo-dark.png` pentru tema închisă), helper `logo()`. **Iconițe PWA** noi (any + maskable + apple-touch), manifest cu `?v=3`, SW `one-shell-v3`.
  Pe iPhone iconița de pe ecran se schimbă doar după ștergere + reinstalare din Safari.
- **Acasă**: fără „Stare sistem". Donut „libere la noapte" + ocupate / check-in / check-out + lista apartamentelor libere.
  **Setări** (stare sistem, Yale Nuki) doar pe desktop (≥ 900px): rotița din bara de sus și linkul din Contul meu.
- **Rapoarte / Acasă**: Ap. **40** și **Daily** scoase din ocupare, canale și libere (`Properties::REPORT_EXCLUDED`). Cache-ul are cheie nouă `operations_v2`.
- **Rezervări**: fără titlu / dată / „N rezervări"; navigare segmentată cu cursor glisant (zi + dată, număr pe tabul activ).
  Butoane: WhatsApp · Guest App · Nuki. Iconițe noi WhatsApp și Nuki (smart door).
- **Nuki**: Ap. 400 → yala `18045779828` (implicit în `Nuki::DEFAULT_LOCKS`; `config/nuki.php` are prioritate).
  Erorile spun motivul (401 token, 403 yală în alt cont / fără drept, 404 ID greșit, 400/422 cod refuzat sau tastatură plină).
  Setări → Yale Nuki → „Verifică în Nuki": pentru fiecare yală — în cont, online, tastatură asociată, câte coduri are.

## 9. Troubleshooting

| Simptom | Cauză / verificare |
|---|---|
| `deploy-one.sh`: `git@github.com: Permission denied (publickey)` | Deploy key-ul serverului lipsește de pe GitHub → `cat ~/.ssh/id_ed25519_one.pub` → Deploy keys (read-only) |
| `doctor` arată încă `1.0.0-stage1` | Deploy-ul n-a rulat / a eșuat → `./deploy-one.sh production` |
| `nano config/….php` deschide „New File" | Fișierul nu există — ieși (Ctrl+X, N) și copiază-l întâi din `*.example.php` |
| `Could not resolve hostname github-one` | Lipsește blocul `Host github-one` din `~/.ssh/config` pe Mac-ul ăsta → §6.3 |
| `Repository not found` la clone/push | Cheia Mac-ului nu e deploy key (cu write) pe `smartstay-one` → §6.3 |
| „Lipsește config/previo.php" pe Rezervări/Curățenie | §6.4 |
| „Previo a răspuns cu eroare (401)" | username/parolă din `previo.php` — copiază din Guest App |
| Nuki: „nu are yală Nuki configurată" | apartamentul lipsește din `smartlocks` în `config/nuki.php` |
| Nuki eșuează | Mesajul din toast spune motivul; Setări → Yale Nuki → Verifică; `tail -20 ~/one.smartstay.ro/storage/logs/nuki.log` |
| Toast „Guest App nesincronizat" | `config/checkin-sync.php` lipsă/greșit; detalii în `storage/logs/app.log` (`GuestAppSync`) |
| Menajera vede „Nicio curățenie alocată" | nu i s-a alocat nimic azi SAU `maid_ref` din cont ≠ cheia din `maids` (Setări → Menajere) |
| Checklist: „Fotografia X lipsește" | `upload_max_filesize` / `post_max_size` prea mici în cPanel (min. 16M) |
| E-mail checklist nu ajunge | `grep "checklist mail" storage/logs/app.log`; verifică `from` în `app.php` |
| 403 pe tot site-ul | `~/one.smartstay.ro/.htaccess` cu „Require all denied" → șterge-l |
| Inventar: „Statusul rezervărilor nu s-a putut încărca" | Previo nu răspunde; contoarele merg. Detalii în `app.log` |
| Inventar: lipsește un apartament (ex. 33) | Nu e în `inventar_apartamente` → `INSERT INTO inventar_apartamente (apartament) VALUES ('33')` |
| Doctor: „lipsesc coloanele inventar_apartamente.…" | Baza Inventar e pre-v3 → rulează `database_migration.sql` din aplicația veche |
| Rapoarte: avertizare „Fără tarif definit: …" | Adaugă apartamentul în `STUDIOS` / `APARTMENTS` din `src/Housekeeping/Rates.php` |
| Rapoarte: ocupare ciudată (prea mare/mică) | Setează lista exactă `apartments` în `config/app.php` |
| Setări: „Cronul orar … nu rulează" | Cron-ul lipsește din cPanel sau dă eroare → `tail storage/logs/cron.log` |
| Eroare 500 | `tail -50 ~/one.smartstay.ro/storage/logs/app.log` |

---

## 10. Securitate

- Niciodată credentiale în chat, issue sau commit. `config/*.php` e în `.gitignore`.
- Înainte de commit: `git diff --cached | grep -iE "password|secret|bearer|token" && echo "❌ STOP" || echo "✅ Safe"`.
- Fiecare endpoint de modul: `Guard::requireAccess()`; fiecare POST: `Guard::requireCsrf()` (header `X-CSRF-Token`, și la upload-ul multipart).
- Tokenul Nuki și parola Previo nu ajung niciodată în browser sau în loguri.

---

## 11. Referințe rapide

**Repo**: `zlkstudio/smartstay-one` · **Server**: `smartconcept@s11993` · **Țintă**: `~/one.smartstay.ro` (docroot `/public`)
**Rute**: `/`, `/reservations[/tomorrow|/whatsapp|/link]`, `/housekeeping[/intermediate|/checklist/{apt}]`,
`/inventory`, `/reports[/payments]`, `/users`, `/settings`, `/account`
**API**: `GET /api/reservations/{list,recent,whatsapp}` · `POST /api/reservations/{status,nuki,whatsapp}` ·
`GET /api/housekeeping/{checkouts,active-guests}` · `POST /api/housekeeping/{assign,unassign,intermediate,checklist}` ·
`GET /api/inventory/occupancy` · `POST /api/inventory/{adjust,note,batch,tech}` · `GET /api/reports/today` · `POST /api/reports/{refresh,cleaning,cleaning/delete}`
**Design**: Jost, `#2563eb` / `#1a6fce` (Rezervări), violet `#7c3aed` (Housekeeping), indigo `#4f46e5` (Inventar), teal `#0d9488` (Rapoarte), radius 16 / 12, dark mode.

*Document de continuitate pentru chat-uri viitoare. Update la fiecare etapă.*
