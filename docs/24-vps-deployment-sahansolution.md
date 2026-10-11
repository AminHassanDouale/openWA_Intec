# 24 — VPS Deployment: wa.sahansolution.com

Deployment runbook for hosting OpenWA on the Ubuntu 24.04 VPS (`187.124.32.203`) behind
the existing host web server, served at **https://wa.sahansolution.com**.

**Chosen configuration**
- Hostname: `wa.sahansolution.com` (dedicated subdomain)
- Runtime: **Docker Compose** (self-contained image with pinned Chrome)
- Database: **PostgreSQL** (built-in `postgres` compose profile; migrations auto-run on boot)
- TLS: Let's Encrypt via the host web server (nginx or apache)
- The container binds to `127.0.0.1:2785`; the host web server reverse-proxies the domain to it.

> **AS-DEPLOYED (2026-10-11): shared gateway.** On this VPS, port `2785` was already
> served by an existing OpenWA container (`openwa-api`, from the `taxiconnectonline`
> compose project) that also hosts the `dtache`/`taxiconnect` WhatsApp sessions. We chose
> to **share** that instance rather than run a second one, so `wa.sahansolution.com` is an
> HTTPS front door to the existing gateway. Consequences:
> - The `.env` created under `/var/www/wa.sahansolution.com` is **not** used by the running
>   container (its `up` would conflict on port 2785). That folder is kept only as the repo
>   checkout / reference. Do **not** `docker compose up` there.
> - The active API key is the instance key printed in the container's startup log
>   (`owa_k1_...`), retrievable via `docker logs openwa-api | grep 'API Key' -A1`.
> - **Restarting `openwa-api` restarts dtache/taxiconnect sessions too** — the watchdog
>   reconnects all of them. Treat restarts as affecting all tenants.
> - sahansolution uses its own named session (e.g. `sahan-1`) alongside the others.
> To isolate later, run a second instance on a different port (e.g. 2786) with its own
> `.env`/data and repoint the nginx `proxy_pass`.

The OpenWA container serves everything on one port `2785`:
`/` = dashboard UI, `/api/*` = REST API + Swagger, `/socket.io` = realtime (QR/status).

---

## 0. Prerequisites (DNS + tooling)

1. **DNS** — add an A record (and AAAA if you use IPv6) for the subdomain, then wait for it to resolve:
   ```
   wa   A     187.124.32.203
   wa   AAAA  2a02:4780:7:4a78::1      # optional
   ```
   Verify from the VPS: `dig +short wa.sahansolution.com` → must return `187.124.32.203`.

2. **Docker + compose plugin** (skip if already installed — check `docker --version`):
   ```bash
   curl -fsSL https://get.docker.com | sh
   docker --version && docker compose version
   ```

3. **Detect your existing web server** (you were unsure which fronts /var/www):
   ```bash
   systemctl is-active nginx apache2 2>/dev/null
   sudo ss -ltnp '( sport = :80 or sport = :443 )'
   ```
   The active one (nginx or apache2) is what you'll add the vhost to in step 4.

---

## 1. Get the code

```bash
cd /var/www
git clone https://github.com/AminHassanDouale/openWA_Intec.git wa.sahansolution.com
cd wa.sahansolution.com
```
> If the repo is private, clone with a GitHub token:
> `git clone https://<TOKEN>@github.com/AminHassanDouale/openWA_Intec.git wa.sahansolution.com`

---

## 2. Configure environment (`.env`)

Generate secrets and write `.env`. **Change the two passwords.**

```bash
cd /var/www/wa.sahansolution.com

MASTER_KEY=$(openssl rand -hex 32)
DB_PASS=$(openssl rand -hex 16)

cat > .env <<EOF
# ---- Core ----
NODE_ENV=production
API_PORT=2785
DOMAIN=wa.sahansolution.com
BASE_URL=https://wa.sahansolution.com
DASHBOARD_URL=https://wa.sahansolution.com
LOG_LEVEL=info

# ---- Database (PostgreSQL, built-in container) ----
DATABASE_TYPE=postgres
DATABASE_HOST=postgres
DATABASE_PORT=5432
DATABASE_NAME=openwa
DATABASE_USERNAME=openwa
DATABASE_PASSWORD=${DB_PASS}
DATABASE_SYNCHRONIZE=false      # migrations run automatically on boot

# ---- Engine (WhatsApp Web) ----
ENGINE_TYPE=whatsapp-web.js
PUPPETEER_HEADLESS=true

# ---- Storage / cache ----
STORAGE_TYPE=local
REDIS_ENABLED=false

# ---- Security ----
API_MASTER_KEY=${MASTER_KEY}
EOF

echo "SAVE THIS — API master key: ${MASTER_KEY}"
chmod 600 .env
```

---

## 3. Build & start (API + PostgreSQL only)

The `postgres` profile starts Postgres; `openwa-api` has no profile so it always starts.
Traefik and the standalone dashboard container are intentionally **not** started (the API
serves the dashboard, and the host web server does TLS/proxying).

```bash
cd /var/www/wa.sahansolution.com
docker compose --profile postgres up -d --build

# Watch it come up (first boot runs DB migrations, then launches Chrome):
docker compose logs -f openwa-api
```

Health check from the VPS (should return JSON / 200):
```bash
curl -i http://127.0.0.1:2785/api/health
```

---

## 4. Reverse proxy + TLS

### Option A — nginx

```bash
sudo tee /etc/nginx/sites-available/wa.sahansolution.com >/dev/null <<'EOF'
server {
    listen 80;
    listen [::]:80;
    server_name wa.sahansolution.com;

    client_max_body_size 64m;          # allow media uploads

    location / {
        proxy_pass http://127.0.0.1:2785;
        proxy_http_version 1.1;

        # WebSocket (socket.io: QR + live status)
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";

        proxy_set_header Host              $host;
        proxy_set_header X-Real-IP         $remote_addr;
        proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;

        proxy_read_timeout 300s;
        proxy_send_timeout 300s;
    }
}
EOF

sudo ln -s /etc/nginx/sites-available/wa.sahansolution.com /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx

# TLS cert (installs + configures HTTPS redirect automatically)
sudo apt-get install -y certbot python3-certbot-nginx
sudo certbot --nginx -d wa.sahansolution.com
```

### Option B — apache

```bash
sudo a2enmod proxy proxy_http proxy_wstunnel headers
sudo tee /etc/apache2/sites-available/wa.sahansolution.com.conf >/dev/null <<'EOF'
<VirtualHost *:80>
    ServerName wa.sahansolution.com
    LimitRequestBody 67108864

    ProxyPreserveHost On
    # WebSocket upgrade for socket.io
    RewriteEngine On
    RewriteCond %{HTTP:Upgrade} =websocket [NC]
    RewriteRule /(.*) ws://127.0.0.1:2785/$1 [P,L]
    RewriteCond %{HTTP:Upgrade} !=websocket [NC]
    RewriteRule /(.*) http://127.0.0.1:2785/$1 [P,L]

    ProxyPass        / http://127.0.0.1:2785/
    ProxyPassReverse / http://127.0.0.1:2785/
    RequestHeader set X-Forwarded-Proto "http"
</VirtualHost>
EOF

sudo a2ensite wa.sahansolution.com
sudo apache2ctl configtest && sudo systemctl reload apache2

sudo apt-get install -y certbot python3-certbot-apache
sudo certbot --apache -d wa.sahansolution.com
```

---

## 5. Verify

1. Visit **https://wa.sahansolution.com** → dashboard UI loads.
2. **https://wa.sahansolution.com/api/docs** → Swagger. Authorize with the `X-API-Key`
   = your `API_MASTER_KEY` from step 2.
3. Create a session in the dashboard → scan the QR with WhatsApp on your phone.
4. Send a test message via the API (replace key + number):
   ```bash
   curl -X POST https://wa.sahansolution.com/api/sessions/default/send \
     -H "X-API-Key: <API_MASTER_KEY>" -H "Content-Type: application/json" \
     -d '{"to":"2537XXXXXXXX","type":"text","text":"Hello from OpenWA on sahansolution.com"}'
   ```
   (Confirm exact endpoint/body against `/api/docs`.)

---

## 6. Operations

| Action | Command (run in `/var/www/wa.sahansolution.com`) |
| --- | --- |
| Logs | `docker compose logs -f openwa-api` |
| Restart | `docker compose restart openwa-api` |
| Stop | `docker compose --profile postgres down` |
| Update to latest code | `git pull && docker compose --profile postgres up -d --build` |
| Backup DB | `docker compose exec postgres pg_dump -U openwa openwa > backup-$(date +%F).sql` |
| Data location | Docker volumes `openwa-data` (sessions/media) + `postgres-data` |

**Security notes**
- Port `2785` stays bound to `127.0.0.1` — never expose it publicly; all access is via HTTPS through the host web server.
- Keep `.env` at `chmod 600`. The `API_MASTER_KEY` is an ADMIN credential.
- Certbot auto-renews via its systemd timer; confirm with `systemctl list-timers | grep certbot`.
