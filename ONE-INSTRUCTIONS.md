# SmartStay ONE — Instrucțiuni

> **Ultima actualizare**: 2 octombrie 2026
> **Versiune**: 1.1.0-stage2 (Rezervări + Housekeeping portate în ONE)
> **Țintă**: `one.smartstay.ro` — producție directă, fără staging
> **Local**: `/Users/romeo/Projects/SmartStay/smartstay-one` (Mac Mini)
> **Stack**: PHP 8.2+ · PDO · MariaDB 11.4 · vanilla JS · cPanel shared hosting

---

## 0. Stare curentă

✅ Repo `zlkstudio/smartstay-one` pe GitHub (main) · `one.smartstay.ro` live, docroot `/public`, SSL
✅ Fix 403 (`.htaccess` din rădăcina repo-ului eliminat) — pe GitHub
✅ Baza Rezervări confirmată: `smartconcept_reservations`
✅ **Etapa 2 scrisă și testată local** (MariaDB + Previo/Nuki simulate, iPhone 390px, light/dark)
🔲 Push Etapa 2 + `./deploy-one.sh production` (rulează și `sql/002_stage2.sql`)
🔲 Config-uri noi pe server: `previo.php`, `nuki.php`, `checkin-sync.php` (§6.4)
🔲 Primul admin creat + `php bin/doctor.php` → ✅ (4/4 baze, menajere potrivite)
🔲 Conturi menajere (rol Menajeră + numele din listă) și test pe telefon
🔲 Rotit tokenul Nuki (a apărut în clar într-o sesiune de lucru pe 02.10.2026)
➡️ Următorul pas: **Etapa 3** — Inventar + Rapoarte (plata menajerelor, cron `report_cache`)

---

## 1. Ce e ONE

Un singur login pentru aplicațiile interne (Rezervări, Housekeeping, Inventar) + Rapoarte.
ONE **nu mută date**: citește și scrie direct în bazele existente ale fiecărei aplicații,
plus o bază nouă `smartconcept_one` pentru conturi, sesiuni, audit, marcaje WhatsApp și cache de rapoarte.
Aplicațiile vechi rămân online tot timpul tranziției și lucrează pe aceleași rânduri.

| Etapă | Conținut | Stare |
|---|---|---|
| **1 · Fundația** | login, 4 roluri, middleware, sesiuni 90 zile, PWA + instalare, utilizatori, stare sistem | ✅ live |
| **2 · Rezervări + Housekeeping** | portare 1:1, Previo + Nuki în `src/Integrations/` | ✅ gata de deploy |
| 3 · Inventar + Rapoarte | + raport plată menajere + cron `report_cache` | următoarea |
| 4 · Setări apartamente, migrare conturi reale | regulile din `Properties.php` / `Checklist.php` → UI | |

---

## 2. Structura

```
smartstay-one/
├── public/                         ← DOCUMENT ROOT
│   ├── index.php                   front controller: headere securitate, TOATE rutele
│   └── assets/css/ app.css · modules.css (Etapa 2)
│       assets/js/  app.js · reservations.js · housekeeping.js · checklist.js
├── src/
│   ├── Auth/ Http/ Db/ Users/ System/ Audit.php     (Etapa 1)
│   ├── Properties.php              parcări (nu sunt apartamente), telefon RO / WhatsApp
│   ├── Integrations/
│   │   ├── Previo.php              searchReservations (XML), fallback contactPerson → guest
│   │   ├── Nuki.php                PUT /smartlock/{id}/auth, 409 = succes, log storage/logs/nuki.log
│   │   ├── NukiCode.php            codul din telefon (identic cu Guest App)
│   │   └── GuestAppSync.php        toggle Check-in → admin_mark_checkin.php (deblochează codul)
│   ├── Reservations/  ReservationFeed · StatusRepository · OutreachRepository
│   ├── Housekeeping/  HousekeepingFeed · CleaningRepository · Checklist · ChecklistMailer
│   └── Controllers/   Auth, Page, Users, Reservations, Housekeeping
├── views/pages/ reservations/index.php · housekeeping/{index,maid,checklist}.php
├── sql/ 001_one_schema.sql · 002_stage2.sql (whatsapp_outreach)
├── bin/ migrate.php  create-user.php  doctor.php
├── config/                         *.example.php în git; *.php reale DOAR pe server
├── storage/logs/                   app.log, nuki.log
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
| `smartconcept_inventoryStay` | `database-inventory.php` | Etapa 3 |

`maid_name` rămâne numele afișat („Ioana") — exact cum scrie app-ul vechi, ca rapoartele de plată să meargă în paralel.

---

## 4. Autentificare și roluri

**Matricea** (`src/Auth/Access.php`) — neschimbată:

| Rol | Rezervări | Housekeeping | Inventar | Rapoarte | Utilizatori/Setări |
|---|---|---|---|---|---|
| Admin | edit | edit | edit | edit | edit |
| Manager | edit | edit | edit | edit | — |
| Menajeră | — | doar lista ei | — | — | — |
| Utilizator | după `permissions` | idem | idem | idem | — |

Ce înseamnă în Etapa 2 (verificat pe server, nu doar în UI):
- **view**: vede listele; toggle-uri, Nuki, „Trimis" WhatsApp, alocări și checklist sunt blocate (API → 403).
- **edit**: tot ce făcea app-ul vechi.
- **Menajera**: vede DOAR apartamentele alocate ei azi (`maid_assignments` după `config('maids')[maid_ref]`).
  Orice alt apartament, `/housekeeping/intermediate`, `/api/housekeeping/checkouts`, `/reservations` → 403.
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

### 6.1–6.3 Prima instalare, primul admin, Mac nou
Neschimbate față de Etapa 1 (vezi istoricul acestui fișier pe GitHub).

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
nano $T/database-reservations.php   # database => smartconcept_reservations + user/parolă
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

## 7. Deploy — rutina

```bash
# Mac
cd /Users/romeo/Projects/SmartStay/smartstay-one
git push origin main && git log origin/main --oneline -1
# Server
ssh smartstay && ./deploy-one.sh production    # rollback: ./deploy-one.sh rollback production
```
Patch-uri primite din chat: `git am ~/Downloads/000X-….patch && git push origin main`, apoi deploy.
⚠️ Scriptul face `git reset --hard origin/main` — commit-urile nepush-uite nu ajung pe server.

---

## 8. Etapa 2 — ce s-a portat

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

---

## 9. Troubleshooting

| Simptom | Cauză / verificare |
|---|---|
| „Lipsește config/previo.php" pe Rezervări/Curățenie | §6.4 |
| „Previo a răspuns cu eroare (401)" | username/parolă din `previo.php` — copiază din Guest App |
| Nuki: „nu are yală Nuki configurată" | apartamentul lipsește din `smartlocks` în `config/nuki.php` |
| Nuki eșuează | `tail -20 ~/one.smartstay.ro/storage/logs/nuki.log` |
| Toast „Guest App nesincronizat" | `config/checkin-sync.php` lipsă/greșit; detalii în `storage/logs/app.log` (`GuestAppSync`) |
| Menajera vede „Nicio curățenie alocată" | nu i s-a alocat nimic azi SAU `maid_ref` din cont ≠ cheia din `maids` (Setări → Menajere) |
| Checklist: „Fotografia X lipsește" | `upload_max_filesize` / `post_max_size` prea mici în cPanel (min. 16M) |
| E-mail checklist nu ajunge | `grep "checklist mail" storage/logs/app.log`; verifică `from` în `app.php` |
| 403 pe tot site-ul | `~/one.smartstay.ro/.htaccess` cu „Require all denied" → șterge-l |
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
`/inventory`, `/reports`, `/users`, `/settings`, `/account`
**API**: `GET /api/reservations/{list,recent,whatsapp}` · `POST /api/reservations/{status,nuki,whatsapp}` ·
`GET /api/housekeeping/{checkouts,active-guests}` · `POST /api/housekeeping/{assign,unassign,intermediate,checklist}`
**Design**: Jost, `#2563eb` / `#1a6fce` (Rezervări), violet `#7c3aed` (Housekeeping), radius 16 / 12, dark mode.

*Document de continuitate pentru chat-uri viitoare. Update la fiecare etapă.*
