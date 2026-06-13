# Deploying AI Virtual Church to DigitalOcean (single droplet)

This guide deploys the **entire stack onto one Ubuntu 24.04 droplet** managed by
`systemd`, fronted by nginx + Let's Encrypt, using **local MySQL + Redis** and
**local disk for media** (no DigitalOcean Spaces required). It mirrors your local
`.systemd/*.sh` process model, ported to system-level units.

Placeholders to replace throughout:

| Placeholder        | Meaning                          | Example                 |
|--------------------|----------------------------------|-------------------------|
| `example.com`      | your apex domain (frontend)      | `aichurch.org`          |
| `api.example.com`  | backend API subdomain            | `api.aichurch.org`      |
| `YOUR_DROPLET_IP`  | the droplet's public IPv4        | `203.0.113.10`          |

Architecture on the box:

```
              nginx (:80/:443, TLS)
              ├── example.com        → /opt/ai-church/frontend/dist  (static Vue SPA)
              └── api.example.com     → php-fpm (Laravel public/)      + /storage media
                         │
      ┌──────────────────┼─────────────────────────────────────────┐
      │ systemd units (system-level)                                │
      │  aivc-queue.service     php artisan queue:work               │
      │  aivc-scheduler.service php artisan schedule:work            │
      │  aivc-workers.service   celery -Q ai:sermon,ai:music,...     │
      │  aivc-bridge.service    python bridge.py (Redis→Celery)      │
      │  aivc-tedim-api.service python api.py / uvicorn (:8001)      │
      │  aivc-burmese-api.service python api.py / uvicorn (:8002)      │
      └──────────────────┼─────────────────────────────────────────┘
                  MySQL 8 (local)   Redis (local, broker + queue)
```

---

## 0. Sizing & cost

- **Droplet:** Basic Regular **4 GB RAM / 2 vCPU** (`s-2vcpu-4gb`, ~$24/mo). The
  2 GB option works for a demo but Celery `-c 4` + MySQL + Redis + php-fpm is tight.
- Everything else is on-box, so total ≈ **$24/mo** plus your API usage
  (OpenRouter/Suno/YouTube/TTS/Stripe).

---

## 1. Create the droplet & DNS

1. DigitalOcean → **Create → Droplets**: Ubuntu **24.04 LTS**, region near your users,
   `s-2vcpu-4gb`. Add your SSH key. Create.
2. In your DNS provider (or DO **Networking → Domains**), add **A records**:
   - `example.com`      → `YOUR_DROPLET_IP`
   - `api.example.com`  → `YOUR_DROPLET_IP`
   - (optional) `www.example.com` → `YOUR_DROPLET_IP`
3. Wait for DNS to resolve: `dig +short api.example.com` should return your IP.

---

## 2. First login & the `simon` user

SSH in as root, then create an unprivileged user that owns the app and runs the units:

```bash
ssh root@YOUR_DROPLET_IP

adduser --disabled-password --gecos "" simon
usermod -aG sudo simon
rsync --archive --chown=simon:simon ~/.ssh /home/simon   # copy your SSH key

# Firewall: SSH + HTTP/HTTPS only. MySQL/Redis stay loopback-only.
ufw allow OpenSSH
ufw allow 80
ufw allow 443
ufw --force enable
```

From here on, work as `simon` unless told otherwise:

```bash
ssh simon@YOUR_DROPLET_IP
```

---

## 3. Install system packages

```bash
sudo apt update && sudo apt upgrade -y

# PHP 8.3 + extensions Laravel needs (predis = no php-redis ext required)
sudo apt install -y php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring \
  php8.3-xml php8.3-curl php8.3-bcmath php8.3-zip php8.3-gd php8.3-intl

# Composer
sudo apt install -y composer

# Node 18 (for building the Vue frontend)
curl -fsSL https://deb.nodesource.com/setup_18.x | sudo -E bash -
sudo apt install -y nodejs

# Python 3.12 (Ubuntu 24.04 ships it) + venv
sudo apt install -y python3 python3-venv python3-pip

# Datastores + web server + TLS
sudo apt install -y mysql-server redis-server nginx certbot python3-certbot-nginx

# Media tooling: ffmpeg required for audio; fluidsynth only for instrumental hymn renders (optional)
sudo apt install -y ffmpeg fluidsynth

# Git
sudo apt install -y git
```

Verify: `php -v` (8.3.x), `composer --version`, `node -v` (v18), `python3 --version` (3.12).

---

## 4. MySQL

```bash
sudo mysql_secure_installation        # set a root password, answer Y to the prompts

sudo mysql
```
```sql
CREATE DATABASE ai_church CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'ai_church'@'localhost' IDENTIFIED BY 'CHOOSE_A_STRONG_DB_PASSWORD';
GRANT ALL PRIVILEGES ON ai_church.* TO 'ai_church'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

MySQL binds to `127.0.0.1` by default on Ubuntu — keep it that way (firewall also blocks it).

---

## 5. Redis

Default config already binds to `127.0.0.1` only — good. Just enable it:

```bash
sudo systemctl enable --now redis-server
redis-cli ping     # → PONG
```

> **Critical:** the Laravel side must run with `REDIS_PREFIX=` (empty). Laravel
> otherwise prefixes keys, and the Python bridge `BLPOP`s the bare `ai:intake`
> key — a prefix silently breaks the whole pipeline. This is set in the backend
> `.env` below.

---

## 6. Get the code onto the droplet

```bash
sudo mkdir -p /opt/ai-church
sudo chown simon:simon /opt/ai-church
git clone YOUR_GIT_REMOTE /opt/ai-church
cd /opt/ai-church
```

If you don't have a git remote, `rsync` from your machine instead:
```bash
# run locally, NOT on the droplet:
rsync -az --exclude node_modules --exclude .venv --exclude vendor \
  --exclude 'backend/storage/*.key' \
  /home/simon/ai-church/ simon@YOUR_DROPLET_IP:/opt/ai-church/
```

---

## 7. Backend (Laravel) setup

```bash
cd /opt/ai-church/backend
composer install --no-dev --optimize-autoloader
cp .env.example .env
```

Edit `/opt/ai-church/backend/.env` to the production values below
(`nano .env`). These keys come from your actual `.env`:

```dotenv
APP_NAME="AI Church"
APP_ENV=production
APP_KEY=                      # filled by the next command
APP_DEBUG=false
APP_TIMEZONE=UTC
APP_URL=https://api.example.com

APP_LOCALE=en
APP_FALLBACK_LOCALE=en
LOG_CHANNEL=stack
LOG_LEVEL=warning

# Database
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=ai_church
DB_USERNAME=ai_church
DB_PASSWORD=CHOOSE_A_STRONG_DB_PASSWORD

# Sessions / cache (file = no extra tables)
SESSION_DRIVER=file
SESSION_LIFETIME=120
CACHE_STORE=file

# Queue (Redis via predis)
QUEUE_CONNECTION=redis
REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=null
REDIS_PREFIX=                 # MUST be empty — see §5

# Sanctum / SPA
SANCTUM_STATEFUL_DOMAINS=example.com,www.example.com
FRONTEND_URL=https://example.com

# Shared secret with the Python workers (generate: openssl rand -hex 32)
WORKER_WEBHOOK_SECRET=GENERATE_A_32_BYTE_HEX_SECRET

# Stripe (offerings) — use live keys when ready
STRIPE_KEY=pk_live_xxx
STRIPE_SECRET=sk_live_xxx
STRIPE_WEBHOOK_SECRET=whsec_xxx

# Mail (scheduled-service reminder emails). 'log' just writes to the log file.
# Switch to smtp + real credentials to actually deliver.
MAIL_MAILER=smtp
MAIL_HOST=smtp.yourprovider.com
MAIL_PORT=587
MAIL_USERNAME=your_smtp_user
MAIL_PASSWORD=your_smtp_pass
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS="worship@example.com"
MAIL_FROM_NAME="AI Church"
```

Finish backend init:

```bash
php artisan key:generate          # populates APP_KEY
php artisan migrate --force       # creates all tables
php artisan storage:link          # public/storage → storage/app/public (serves media)
php artisan config:cache
php artisan route:cache

# Let php-fpm (www-data) read code and write to storage/cache:
sudo chown -R simon:www-data /opt/ai-church/backend
sudo chmod -R 775 /opt/ai-church/backend/storage /opt/ai-church/backend/bootstrap/cache
```

---

## 8. Workers (Python / Celery) setup

```bash
cd /opt/ai-church/workers
python3 -m venv .venv
.venv/bin/pip install --upgrade pip
.venv/bin/pip install -r requirements.txt
```

Create `/opt/ai-church/workers/.env` (these are your actual worker keys). The
worker writes media into the Laravel public-storage dir so nginx can serve it —
no Spaces needed:

```dotenv
# Broker — same Redis as Laravel
REDIS_URL=redis://127.0.0.1:6379/0

# LLM (OpenRouter or any OpenAI-compatible endpoint)
OPENROUTER_API_KEY=sk-or-v1-xxx
LLM_MODEL=openai/gpt-oss-120b:free

# Callback into Laravel. This is the FULL asset-ready URL (used verbatim); the
# worker derives the music-track URL from it by replacing "asset-ready".
LARAVEL_WEBHOOK_URL=https://api.example.com/api/internal/asset-ready
WORKER_WEBHOOK_SECRET=GENERATE_A_32_BYTE_HEX_SECRET   # SAME value as backend/.env

# Music (optional — only needed for those sources)
SUNO_API_KEY=
YOUTUBE_API_KEY=

# Server-side narration / TTS (optional; browser speech is the fallback)
TTS_API_KEY=
TTS_BASE_URL=https://api.openai.com/v1
TTS_MODEL=gpt-4o-mini-tts
TTS_VOICE=onyx
KOKORO_API_KEY=
KOKORO_BASE_URL=https://openrouter.ai/api/v1
KOKORO_MODEL=hexgrad/kokoro-82m
KOKORO_VOICE=af_heart

# Local Myanmar/Tedim LLM + MMS-TTS services
TEDIM_LLM_URL=http://127.0.0.1:8001
BURMESE_LLM_URL=http://127.0.0.1:8002
OLLAMA_URL=http://127.0.0.1:11434/api/generate
OLLAMA_MODEL_TD=tedim-zolai
OLLAMA_MODEL_MY=burmese-myanmar
LOCAL_LLM_TIMEOUT=45
MMS_TTS_URL=http://127.0.0.1:8001
MMS_TTS_MODEL_MY=facebook/mms-tts-mya
MMS_TTS_MODEL_TD=facebook/mms-tts-ctd
MMS_TTS_SEED=42
MMS_TTS_TIMEOUT=180
MMS_TTS_STAGGER_SECONDS=60

# Local media storage (served by nginx via the storage symlink)
LOCAL_MEDIA_DIR=/opt/ai-church/backend/storage/app/public/media
LOCAL_MEDIA_URL=https://api.example.com/storage/media
```

```bash
mkdir -p /opt/ai-church/backend/storage/app/public/media
# Workers run as 'simon'; keep media group-writable for www-data to serve:
sudo chown -R simon:www-data /opt/ai-church/backend/storage/app/public

# Seed the public-domain hymn library (one-time; needed for hymn_sung music)
set -a; . ./.env; set +a
.venv/bin/python seed_hymns.py
```

---

## 9. Frontend (Vue) build

```bash
cd /opt/ai-church/frontend
npm ci         # or: npm install
```

`frontend/.env.production` is **version-controlled** in the repo — verify it has the
correct production values before building:

```dotenv
VITE_API_URL=https://api.example.com/api   # must match your api.example.com
VITE_STRIPE_KEY=pk_live_xxx               # live publishable key when ready
```

Edit it if needed (`nano frontend/.env.production`), then build:

```bash
npm run build         # outputs static files to dist/
```

`dist/` is what nginx serves for `example.com`.

---

## 10. nginx + TLS

Create `/etc/nginx/sites-available/ai-church`:

```nginx
# Frontend SPA — apex domain
server {
    listen 80;
    server_name example.com www.example.com;
    root /opt/ai-church/frontend/dist;
    index index.html;

    location / {
        try_files $uri $uri/ /index.html;   # SPA fallback
    }
}

# Backend API — api subdomain (php-fpm + media)
server {
    listen 80;
    server_name api.example.com;
    root /opt/ai-church/backend/public;
    index index.php;
    client_max_body_size 25M;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }

    location ~ /\.(?!well-known).* { deny all; }
}
```

Enable it and get certificates:

```bash
sudo ln -s /etc/nginx/sites-available/ai-church /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx

# Let's Encrypt for all three names (certbot rewrites the server blocks to 443 + redirect)
sudo certbot --nginx -d example.com -d www.example.com -d api.example.com
```

Certbot installs a renewal timer automatically; verify with `sudo certbot renew --dry-run`.

---

## 11. systemd units

The HTTP layer is now php-fpm + nginx, so we drop the local `backend.sh`
(artisan serve). Queue workers, Celery, the Redis bridge, and the local
Myanmar/Tedim FastAPI services run as **system-level** units owned by `simon`.

These units are version-controlled in the repo at
[`.systemd/prod/`](.systemd/prod/) — just copy them (skip retyping the blocks
below, which are shown for reference):

```bash
sudo cp /opt/ai-church/.systemd/prod/aivc-*.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now aivc-queue aivc-scheduler aivc-workers aivc-bridge aivc-tedim-api aivc-burmese-api
sudo systemctl status  aivc-queue aivc-scheduler aivc-workers aivc-bridge aivc-tedim-api aivc-burmese-api --no-pager
```

For reference, each file under `/etc/systemd/system/`:

`/etc/systemd/system/aivc-queue.service`:
```ini
[Unit]
Description=AI Church — Laravel queue worker
After=redis-server.service mysql.service

[Service]
User=simon
Group=www-data
WorkingDirectory=/opt/ai-church/backend
ExecStart=/usr/bin/php artisan queue:work --tries=3 --sleep=1
Restart=always
RestartSec=2

[Install]
WantedBy=multi-user.target
```

`/etc/systemd/system/aivc-scheduler.service`:
```ini
[Unit]
Description=AI Church — Laravel scheduler (releases due services + reminder mail)
After=mysql.service

[Service]
User=simon
Group=www-data
WorkingDirectory=/opt/ai-church/backend
ExecStart=/usr/bin/php artisan schedule:work
Restart=on-failure
RestartSec=2

[Install]
WantedBy=multi-user.target
```

`/etc/systemd/system/aivc-workers.service` (Celery — loads `.env` via the shell,
exactly like your `workers.sh`):
```ini
[Unit]
Description=AI Church — Celery workers (sermon/music/avatar/narration)
After=redis-server.service

[Service]
User=simon
Group=www-data
WorkingDirectory=/opt/ai-church/workers
ExecStart=/usr/bin/env bash -lc 'set -a; . ./.env; set +a; exec .venv/bin/celery -A tasks.celery_app worker -Q ai:sermon,ai:music,ai:avatar,ai:narration -c 4'
Restart=on-failure
RestartSec=2

[Install]
WantedBy=multi-user.target
```

`/etc/systemd/system/aivc-bridge.service` (its own unit so a crash is restarted —
the whole pipeline is dead without it):
```ini
[Unit]
Description=AI Church — bridge consumer (Redis ai:intake → Celery)
After=aivc-workers.service redis-server.service

[Service]
User=simon
Group=www-data
WorkingDirectory=/opt/ai-church/workers
ExecStart=/usr/bin/env bash -lc 'set -a; . ./.env; set +a; exec .venv/bin/python bridge.py'
Restart=always
RestartSec=2

[Install]
WantedBy=multi-user.target
```

---

## 12. Stripe webhook (offerings)

1. Stripe Dashboard → **Developers → Webhooks → Add endpoint**.
2. Endpoint URL: **`https://api.example.com/api/webhooks/stripe`**
   (handled by `OfferingController::webhook`).
3. Subscribe to exactly one event: **`payment_intent.succeeded`** — it's the only
   type the controller records; everything else is acknowledged and ignored.
4. Copy the **Signing secret** (`whsec_...`) into `STRIPE_WEBHOOK_SECRET` in
   `backend/.env`, then `php artisan config:cache`. (php-fpm picks up the cached
   config on the next request; no unit restart needed for webhook verification.)

---

## 13. Smoke test (verify it actually works)

```bash
# API is up (public route, no auth) — returns intake-options JSON
curl -i https://api.example.com/api/config    # → 200 + JSON

# Pipeline end-to-end: register → create/intake a service from the frontend
# at https://example.com, then watch the chain:
redis-cli LLEN ai:intake                   # should briefly be >0 then drain to 0
sudo journalctl -u aivc-bridge -f          # "picked up job ..." lines
sudo journalctl -u aivc-workers -f         # task execution per queue
ls -la /opt/ai-church/backend/storage/app/public/media   # narration mp3s appear
```

In the browser: open `https://example.com`, register an account, start a service,
and confirm segments (prayer/sermon/music) stream into the player. Check that
`https://api.example.com/storage/media/<file>.mp3` loads (media serving works).

---

## 14. Redeploying after code changes

```bash
cd /opt/ai-church && git pull

# backend
cd backend && composer install --no-dev --optimize-autoloader \
  && php artisan migrate --force \
  && php artisan config:cache && php artisan route:cache

# frontend
cd ../frontend && npm ci && npm run build

# restart the background units (php-fpm picks up code on next request automatically)
sudo systemctl restart aivc-queue aivc-scheduler aivc-workers aivc-bridge aivc-tedim-api aivc-burmese-api
```

If you changed Python deps: `cd workers && .venv/bin/pip install -r requirements.txt`
before the restart.

---

## 15. Troubleshooting cheat-sheet

| Symptom | Likely cause | Fix |
|---|---|---|
| Intake never produces segments | `ai:intake` not draining | Check `REDIS_PREFIX=` is empty in backend `.env`; check `aivc-bridge` is running |
| Bridge/Celery can't see API keys | `.env` not loaded | They don't auto-load — units use `set -a; . ./.env`; confirm `workers/.env` exists |
| Webhook callbacks 401/403 | secret mismatch | `WORKER_WEBHOOK_SECRET` must be identical in `backend/.env` and `workers/.env` |
| 500 on API, blank logs | storage/cache not writable | `sudo chown -R www-data:www-data backend/storage backend/bootstrap/cache && sudo find backend/storage backend/bootstrap/cache -type d -exec chmod 2775 {} \;` |
| Media mp3 404 | symlink/perms | `php artisan storage:link`; media dir owned `simon:www-data` |
| Config changes ignored | cached config | `php artisan config:cache` after every `.env` edit |
| `.env` edits to APP_* ignored | cached | also clear with `php artisan config:clear` then re-cache |
| 429 on login/register | rate limiter | Expected under brute-force; `throttle:auth` is intentional. If you trip it during setup, wait 60s or flush the rate-limit cache: `php artisan cache:clear` |
| CSP blocks inline scripts in the SPA | security headers | `SecurityHeaders` middleware sets a strict `default-src 'none'` on the API — the SPA origin is nginx-served (not php-fpm) so it has no CSP applied. If you add a Blade view, extend the CSP in `SecurityHeaders.php`. |

Logs:
- Laravel: `/opt/ai-church/backend/storage/logs/laravel.log`
- Units: `sudo journalctl -u aivc-workers -u aivc-bridge -u aivc-queue -u aivc-scheduler -u aivc-tedim-api -u aivc-burmese-api`
- nginx: `/var/log/nginx/error.log`
