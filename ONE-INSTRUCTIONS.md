# SmartStay ONE — Instrucțiuni

> **Ultima actualizare**: 28 septembrie 2026
> **Versiune**: 1.0.0-stage1 (Fundația: login, roluri, PWA, utilizatori, stare sistem)
> **Țintă**: `one.smartstay.ro` (producție) · `one-staging.smartstay.ro` (staging)
> **Stack**: PHP 8.2+ · PDO · MariaDB 11.4 · vanilla JS · cPanel shared hosting

---

## 1. Ce e ONE

Un singur login pentru aplicațiile interne (Rezervări, Housekeeping, Inventar) + Rapoarte.
ONE **nu mută date**: citește și scrie direct în bazele existente ale fiecărei aplicații,
plus o bază nouă `smartconcept_one` pentru conturi, sesiuni, audit și cache de rapoarte.
Aplicațiile vechi rămân online tot timpul tranziției.

### Etape

| Etapă | Conținut | Stare |
|---|---|---|
| **1 · Fundația** | login, 4 roluri, middleware, sesiuni 90 zile, PWA + pagină instalare, utilizatori, stare sistem | ✅ acest pachet |
| 2 · Rezervări + Housekeeping | portare 1:1, Previo + Nuki copiate în `src/Integrations/` | următoarea |
| 3 · Inventar + Rapoarte | + cron `report_cache` | |
| 4 · Setări apartamente, migrare conturi reale, DNS final | | |

În Etapa 1, paginile de modul există și sunt **deja protejate de roluri** exact ca în Etapa 2;
afișează „În curând în ONE" + buton spre aplicația actuală (ascuns pentru menajere).

---

## 2. Structura

```
smartstay-one/                      repo zlkstudio/smartstay-one
├── public/                         ← DOCUMENT ROOT al subdomeniului
│   ├── index.php                   front controller: headere securitate, rute
│   ├── .htaccess                   HTTPS, rewrite, cache assets
│   ├── manifest.webmanifest        PWA
│   ├── sw.js                       service worker (shell cache, fără HTML/API)
│   ├── offline.html
│   └── assets/ css/ js/ fonts/ img/   Jost self-hosted (latin + latin-ext pt. ș ț ă)
├── src/
│   ├── bootstrap.php  helpers.php
│   ├── Db/Database.php             o conexiune PDO per bază, lazy
│   ├── Auth/Auth.php               sesiuni, login, CSRF, rotire token
│   ├── Auth/Access.php             MATRICEA de roluri (singurul loc)
│   ├── Auth/LoginThrottle.php      anti brute-force
│   ├── Http/Router.php  Http/Guard.php   rute + middleware
│   ├── Controllers/                Auth, Page, Users
│   ├── Users/UserRepository.php
│   ├── System/HealthCheck.php      verifică baze, tabele, menajere, server
│   └── Audit.php
├── views/                          layout, partials, pages/
├── sql/001_one_schema.sql          aplicat de bin/migrate.php
├── bin/  migrate.php  create-user.php  doctor.php
├── config/                         *.example.php în git; *.php reale DOAR pe server
├── storage/logs/                   app.log (în afara docroot, persistent)
├── deploy/deploy-one.sh
└── .htaccess                       plasă de siguranță: „Require all denied"
```

`config/`, `src/`, `storage/` nu sunt niciodată servite: docroot-ul e `public/`.
Dacă subdomeniul e setat greșit pe rădăcina repo-ului, `.htaccess` din rădăcină blochează tot.

---

## 3. Baze de date

| Bază | Config | Rol |
|---|---|---|
| `smartconcept_one` (nouă) | `config/database-one.php` | users, permissions, sessions, login_attempts, audit_log, report_cache, schema_migrations |
| `smartconcept_cleaning` | `config/database-cleaning.php` | Housekeeping (Etapa 2) |
| `smartconcept_inventoryStay` | `config/database-inventory.php` | Inventar (Etapa 3) |
| **de confirmat** | `config/database-reservations.php` | Rezervări — `php bin/doctor.php --find-reservations` |

Patru fișiere separate, `chmod 600`, niciodată combinate.
`smartconcept_one` rulează în **UTC**; afișarea convertește în Europe/Bucharest.

Diferențe față de promptul de construcție (intenționate):
- `users.must_change_password` — flag explicit pentru parola temporară.
- `users.phone` UNIQUE — menajerele intră cu telefonul, nu au nevoie de email.
- `sessions.id` = **sha256** al tokenului din cookie; tokenul brut nu ajunge în DB.
- `login_attempts` — tabel nou pentru limitarea încercărilor.

---

## 4. Autentificare și roluri

**Login**: email **sau** telefon (`0784 429 677`, `+40…`, `0040…` — normalizat la `40784429677`).

**Sesiune „Ține-mă minte"** (bifat implicit): 90 de zile, glisantă. Tokenul se rotește cel mult
o dată la 24h; vechiul token mai merge 2 minute (cererile paralele ale PWA-ului nu deloghează).
Nebifat: 12 ore. Logout, reset parolă sau dezactivare → sesiunile dispar imediat din DB.

**Matricea** (`src/Auth/Access.php`):

| Rol | Rezervări | Housekeeping | Inventar | Rapoarte | Utilizatori/Setări |
|---|---|---|---|---|---|
| Admin | edit | edit | edit | edit | edit |
| Manager | edit | edit | edit | edit | — |
| Menajeră | — | edit (doar ale ei) | — | — | — |
| Utilizator | după `permissions` | idem | idem | idem | — |

Reguli implementate:
- Fiecare pagină și endpoint începe cu `Guard::requireAccess($modul, 'view'|'edit')`. UI-ul doar oglindește.
- Modul nepermis = ascuns din navigație; accesat direct → 403 „Nu ai acces la această secțiune".
- Menajera: aterizează pe `/housekeeping`, fără bară de navigare, fără link spre aplicația veche.
- Cont nou / reset → parolă temporară (ex. `Ab3d-Ef6h-Jk8m`), schimbare **obligatorie** la prima intrare
  (orice altă pagină sau API redirecționează la `/account/password`).
- Nu îți poți schimba propriul rol și nu te poți dezactiva; ultimul admin activ e protejat.
- `audit_log`: creare cont, modificare, permisiuni (before/after), reset parolă, (de)activare, login/logout.
- CSRF pe orice POST (câmp `_csrf` sau header `X-CSRF-Token`) + verificare `Origin`. **Fără CORS deschis.**
- Brute-force: 5 eșecuri / 15 min per utilizator, 20 per IP.

---

## 5. PWA și pagina de instalare

- Prima vizită într-un tab de browser → `/install` (o singură dată; flag `one-install-done` în localStorage).
- Android/Chrome → buton „Instalează aplicația" (prompt nativ). Fără prompt în 2,5s → pași din meniul ⋮.
- iPhone Safari → 3 pași cu iconițele reale (Distribuie → Adaugă pe ecranul principal).
- Deschis din WhatsApp/Facebook/Instagram → explică să deschidă în Safari/Chrome + „Copiază linkul".
- În aplicația instalată, pagina nu apare niciodată. Din „Contul meu" se poate redeschide manual.
- Service worker: cache doar pentru CSS/JS/fonturi/iconițe. **HTML-ul autentificat și `/api/` nu se cache-uiesc
  niciodată.** Fără rețea → `offline.html`.

---

## 6. Punere în funcțiune pe server (o singură dată)

### 6.1 GitHub + SSH
```bash
# Local (Mac): repo nou privat zlkstudio/smartstay-one, apoi în folderul acestui pachet:
git remote add origin git@github.com:zlkstudio/smartstay-one.git
git push -u origin main

# Server:
ssh smartstay
ssh-keygen -t ed25519 -f ~/.ssh/id_ed25519_one -N "" -C "deploy-one@s11993"
cat ~/.ssh/id_ed25519_one.pub     # → GitHub → smartstay-one → Settings → Deploy keys (read-only)
cat >> ~/.ssh/config <<'EOF'

Host github-one
  HostName github.com
  User git
  IdentityFile ~/.ssh/id_ed25519_one
  IdentitiesOnly yes
EOF
ssh -T git@github-one            # "You've successfully authenticated"
```

### 6.2 cPanel
1. **Domains → Create a New Domain**: `one-staging.smartstay.ro`, document root
   **`one-staging.smartstay.ro/public`** (cu `/public` la final!). Apoi la fel `one.smartstay.ro` → `one.smartstay.ro/public`.
2. **SSL/TLS Status → Run AutoSSL** pentru ambele.
3. **MySQL Databases**: creează `smartconcept_one`, adaugă userul `smartconcept_romeo` cu ALL PRIVILEGES.

Staging și producția folosesc **aceleași baze de date** (ca la Guest App). Testele pe staging
modifică date reale — folosește conturi de test.

### 6.3 Primul deploy pe staging
```bash
git clone git@github-one:zlkstudio/smartstay-one.git ~/source/smartstay-one
cp ~/source/smartstay-one/deploy/deploy-one.sh ~/ && chmod +x ~/deploy-one.sh

T=~/one-staging.smartstay.ro
mkdir -p $T/config
cp ~/source/smartstay-one/config/app.example.php          $T/config/app.php
cp ~/source/smartstay-one/config/database-one.example.php $T/config/database-one.php
nano $T/config/app.php            # base_url => 'https://one-staging.smartstay.ro'
nano $T/config/database-one.php   # user + parolă (aceleași ca la housekeeping)
chmod 600 $T/config/*.php

./deploy-one.sh staging           # rsync + migrări + ping + raport
```

### 6.4 Primul admin + baza Rezervări
```bash
cd ~/one-staging.smartstay.ro
php bin/create-user.php --name="Romeo" --email=contact@radoiromeo.ro
#   → afișează parola temporară; o schimbi la prima intrare pe telefon

php bin/doctor.php --find-reservations
#   → citește DB_NAME/DB_USER din ~/smartstay.ro/reservations/config/database.php (fără parolă)
cp ~/source/smartstay-one/config/database-reservations.example.php config/database-reservations.php
nano config/database-reservations.php     # numele găsit + credentialele
# la fel pentru database-cleaning.php și database-inventory.php
chmod 600 config/*.php
php bin/doctor.php                         # țintă: ✅ Totul în regulă
```
Același raport e și în aplicație: **Contul meu → Setări și stare sistem**.
Verifică acolo secțiunea **Menajere**: numele din `cleaning_records` trebuie să apară în `maids` din
`config/app.php` — altfel Etapa 2 nu le poate lega de conturi.

### 6.5 Producție
Doar după ce staging a fost folosit câteva zile:
```bash
T=~/one.smartstay.ro; mkdir -p $T/config
cp ~/one-staging.smartstay.ro/config/*.php $T/config/
nano $T/config/app.php            # base_url => 'https://one.smartstay.ro'  (scriptul verifică!)
chmod 600 $T/config/*.php
./deploy-one.sh production
```

---

## 7. Deploy — rutina

```bash
# Local
git add -A && git commit -m "…" && git push origin main
git log origin/main --oneline -1          # verifică că a ajuns pe GitHub ÎNAINTE de deploy

# Server
./deploy-one.sh staging                   # sau: staging feature/rezervari
./deploy-one.sh production                # refuză dacă origin/main ≠ ce e pe staging
./deploy-one.sh rollback production       # codul anterior; config + storage neatinse
./deploy-one.sh status
```

Ce protejează scriptul:
- `PROTECTED_CONFIGS` + `storage/` sunt excluse din `rsync --delete` → nu se șterg niciodată.
- **Fără `mv` de foldere** (lecția `data/checkin` din Guest App): codul se sincronizează pe loc.
- Refuză deploy-ul dacă lipsește `config/app.php` / `database-one.php` sau dacă `base_url`
  nu corespunde țintei (staging nu poate pointa spre producție și invers).
- Backup cod înainte de fiecare deploy în `~/backups/one/` (ultimele 10).
- Rulează `bin/migrate.php`, face ping pe `/api/ping`, afișează `bin/doctor.php`.

⚠️ Scriptul face `git reset --hard origin/<branch>` — commit-urile nepush-uite nu ajung pe server.

---

## 8. Etapa 2 — cum se adaugă un modul

1. Controller în `src/Controllers/ReservationsController.php`; prima linie din fiecare metodă:
   `$user = Guard::requireAccess('reservations', 'view');` (sau `'edit'` pentru scriere).
2. Endpoint-uri JSON în `public/index.php` sub `/api/reservations/...`; POST-urile apelează și
   `Guard::requireCsrf()`. Din JS: `ONE.api('/api/reservations/status', {method: 'POST', body: {...}})`
   (CSRF, timeout 15s, `cache: no-store`, redirect la login pe 401 — deja incluse).
3. Baza: `Database::get('reservations')` — conexiunea se deschide doar când e folosită.
4. Previo/Nuki: **copiază** clasele în `src/Integrations/` și config-urile în `config/previo.php`,
   `config/nuki.php` (deja în `PROTECTED_CONFIGS`). Fără include cross-app, fără symlink.
5. Menajera: filtrează **server-side** după `$user['maid_ref']` → numele din `config('maids')`.

---

## 9. Troubleshooting

| Simptom | Cauză / verificare |
|---|---|
| „Configurare incompletă: lipsește config/app.php" | Config-urile nu sunt create în ținta de deploy (§6.3) |
| 404 Apache pe orice pagină | Docroot-ul nu e `…/public` sau `mod_rewrite` inactiv |
| 403 pe tot site-ul | Docroot setat pe rădăcina repo-ului → plasa `.htaccess` blochează. Corectează la `/public` |
| Pagina de instalare apare din nou | localStorage șters/privat; „Continuă în browser" o închide |
| Deloghează după fiecare deschidere pe iPhone | `cookie_secure` true pe HTTP, sau „Ține-mă minte" debifat |
| „Pagina a expirat" (419) | Token CSRF vechi (formular deschis înainte de re-login) — reîncarcă |
| „Prea multe încercări" | 5 parole greșite în 15 min. Așteaptă sau: `DELETE FROM login_attempts WHERE identifier='…'` |
| Admin blocat afară | `php bin/create-user.php --name=… --email=alt@… --role=admin` din SSH |
| Eroare 500 | `tail -50 ~/one.smartstay.ro/storage/logs/app.log` |

---

## 10. Securitate

- Niciodată credentiale în chat, issue sau commit. `config/*.php` e în `.gitignore` (doar `*.example.php` în git).
- Înainte de commit: `git diff --cached | grep -iE "password|secret|bearer|token" && echo "❌ STOP" || echo "✅ Safe"`.
- Parola admin `@Cleaning` din Housekeeping **nu** se migrează — fiecare persoană primește cont propriu.
- CSP strict cu nonce, `X-Frame-Options: DENY`, HSTS, `Cache-Control: no-store` pe tot HTML-ul.
- Fișiere de diagnostic (`_diagnose.php`, `db_check*.php`, `test-*.php`) sunt ignorate de git; nu le urca în `public/`.

---

## 11. Referințe rapide

**Repo**: `zlkstudio/smartstay-one` · **SSH alias**: `github-one` · **Server**: `smartconcept@s11993`
**Ținte**: `~/one.smartstay.ro`, `~/one-staging.smartstay.ro` (docroot `/public`)
**Backups**: `~/backups/one/` · **Log**: `storage/logs/app.log`
**Rute**: `/install`, `/login`, `/`, `/account`, `/account/password`, `/reservations`, `/housekeeping`,
`/inventory`, `/reports`, `/users`, `/users/new`, `/users/{id}/edit`, `/settings`, `/api/me`, `/api/ping`
**Design**: Jost 400–700, `#2563eb` / `#1a6fce`, violet `#7c3aed` (Housekeeping), indigo `#4f46e5` + teal
`#0d9488` (Inventar), radius 16 / 12, dark mode în topbar (localStorage `one-theme`).

*Document de continuitate pentru chat-uri viitoare. Update la fiecare etapă.*
