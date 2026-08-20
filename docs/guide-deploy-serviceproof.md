# Guide de déploiement — ServiceProof AI sur DigitalOcean

## Assurance opérationnelle réseau · Next.js + Laravel + FastAPI + PostgreSQL

> **Domaines cibles** :
> - `serviceproof.vylantic.com` — console d'opérations Next.js
> - `api.serviceproof.vylantic.com` — API Laravel
> - `ai.serviceproof.vylantic.com` — runtime agent FastAPI
>
> Ce guide installe ServiceProof sur un droplet DigitalOcean **avec une infrastructure mutualisable** (reverse proxy Nginx partagé, réseaux Docker isolés). Si le droplet héberge déjà Kynara ou une autre application, ServiceProof s'ajoute sans conflit.

---

## À lire avant de commencer

Trois points où ce déploiement **diffère du modèle Kynara**. Les ignorer produit une installation qui démarre et qui est fausse.

**Les Dockerfiles du dépôt sont orientés développement.** `infra/docker/web.Dockerfile` lance `npm run dev` ; `infra/docker/laravel.Dockerfile` lance `php artisan serve`, installe Composer au démarrage et migre à chaque boot. Ce guide utilise trois images de production distinctes, livrées dans le dépôt : `laravel.prod.Dockerfile`, `agent.prod.Dockerfile`, `web.prod.Dockerfile`. Les originales restent en place pour le poste de travail.

**Le runtime agent n'est pas conçu pour être public.** `apps/agent/app/main.py` le déclare : *« Internal service: reachable only from the ServiceProof product core »*. Son seul endpoint d'action, `POST /agent/verify`, exige un jeton partagé `X-Internal-Token`. L'exposer sur `ai.serviceproof.vylantic.com` est légitime — le hackathon demande à voir les appels API — mais §8.3 restreint le vhost en conséquence. Ne copiez pas un vhost ouvert.

**ServiceProof n'utilise ni Elasticsearch, ni MinIO, ni Mailpit, ni queue worker, ni scheduler.** Il n'y a aucun job dans `apps/api/app/Jobs` et aucune tâche planifiée. Les déployer coûterait de la RAM pour rien.

---

## Architecture cible

| Service | Domaine | Technologie | Port interne |
|---|---|---|---|
| Console d'opérations | `serviceproof.vylantic.com` | Next.js 15 (App Router) | 3000 |
| API produit | `api.serviceproof.vylantic.com` | Laravel 11 + PHP 8.3 | 8000 |
| Runtime agent | `ai.serviceproof.vylantic.com` | FastAPI + Python 3.12 | 9000 |
| Base de données | (interne) | PostgreSQL 16 | 5432 |
| Cache | (interne) | Redis 7 | 6379 |

> Le port de l'agent est **9000**, pas 8001. C'est la valeur du dépôt, de `AGENT_PORT` et du `CMD` de son Dockerfile.

> **Aucun port n'est publié sur l'hôte** sauf 80/443 (Nginx). PostgreSQL et Redis ne sont joignables que depuis `serviceproof-network`.

---

# 1. Architecture globale

```text
Internet
   │
   ▼
Cloudflare (optionnel — DNS + CDN + WAF)
   │
   ▼
┌──────────────────────────────────────────────────────────────┐
│  Droplet Ubuntu 24.04 LTS  (2 vCPU / 4 Go RAM / 50 Go SSD)  │
│                                                              │
│  ┌────────────────────────────────────────────────────────┐  │
│  │  Réseau Docker partagé : proxy-network                 │  │
│  │  ┌──────────────────────────────────────────────────┐  │  │
│  │  │  proxy-nginx  (reverse proxy global mutualisé)   │  │  │
│  │  │  • ports 80 / 443                                │  │  │
│  │  │  • TLS via Let's Encrypt                         │  │  │
│  │  │  • route par domaine vers chaque app             │  │  │
│  │  └──────────────────────────────────────────────────┘  │  │
│  └────────────────────────────────────────────────────────┘  │
│                                                              │
│  ┌─────────────── ServiceProof ─────────────────────────┐    │
│  │  Réseau : serviceproof-network                       │    │
│  │   • serviceproof-web       :3000  (Next.js)          │    │
│  │   • serviceproof-api       :8000  (Laravel)          │    │
│  │   • serviceproof-agent     :9000  (FastAPI)          │    │
│  │   • serviceproof-postgres  :5432                     │    │
│  │   • serviceproof-redis     :6379                     │    │
│  └──────────────────────────────────────────────────────┘    │
│                                                              │
│  ┌─────────── Autre SaaS (ex. Kynara) ──────────────────┐    │
│  │  Réseau : kynara-network (isolé)                     │    │
│  └──────────────────────────────────────────────────────┘    │
└──────────────────────────────────────────────────────────────┘
                          │
                          ▼  (sortant, HTTPS)
              network-as-code.p-eu.apihub.nokia.io
              Location Verification · Device Status · Reachability
```

Deux flux méritent d'être notés parce qu'ils **ne passent pas** par Internet :

- La console appelle Laravel via `http://serviceproof-api:8000` sur le réseau privé. Le navigateur ne parle jamais à Laravel : la session est un cookie httpOnly et tout le rendu est serveur.
- Laravel appelle l'agent via `http://serviceproof-agent:9000`. Passer par le domaine public ferait sortir la requête du droplet, traverser TLS et revenir, sans aucun bénéfice.

---

# 2. Prérequis droplet

Si le droplet existe déjà (Kynara installée), passer directement à la section 5. Sinon :

- **Plan** : 2 vCPU / 4 Go RAM / 50 Go SSD NVMe suffit — ServiceProof est nettement plus léger que Kynara (pas d'Elasticsearch, pas de MinIO). Prendre 4 Go minimum : le build Next.js en consomme ~1,5 Go à lui seul.
- **OS** : Ubuntu 24.04 LTS
- **Région** : Frankfurt (`fra1`) ou Amsterdam (`ams3`) — proche du gateway Nokia `p-eu`
- **Backups** : activés

---

# 3. Hardening (si nouveau droplet)

```bash
apt update && apt upgrade -y
apt install -y curl wget vim git ufw fail2ban unzip ca-certificates gnupg lsb-release htop btop ncdu jq tree net-tools dnsutils

# Utilisateur non-root
adduser deploy && usermod -aG sudo deploy
mkdir -p /home/deploy/.ssh
cp /root/.ssh/authorized_keys /home/deploy/.ssh/
chown -R deploy:deploy /home/deploy/.ssh && chmod 700 /home/deploy/.ssh

# SSH
sed -i 's/^PermitRootLogin.*/PermitRootLogin prohibit-password/' /etc/ssh/sshd_config
sed -i 's/^#PasswordAuthentication.*/PasswordAuthentication no/' /etc/ssh/sshd_config
systemctl restart ssh

# Timezone
timedatectl set-timezone Africa/Casablanca

# fail2ban
systemctl enable --now fail2ban
```

> **L'horloge du serveur compte plus qu'ailleurs ici.** ServiceProof compare des horodatages réseau à l'heure locale pour décider si une preuve est fraîche. Une dérive de plusieurs minutes ferait passer des preuves valides en `STALE`. Vérifier : `timedatectl status` doit afficher `NTP service: active`.

---

# 4. Firewall + Docker (si nouveau droplet)

```bash
# UFW
ufw default deny incoming && ufw default allow outgoing
ufw allow OpenSSH && ufw allow 80/tcp && ufw allow 443/tcp && ufw enable

# Docker
install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg | gpg --dearmor -o /etc/apt/keyrings/docker.gpg
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo $VERSION_CODENAME) stable" > /etc/apt/sources.list.d/docker.list
apt update && apt install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
usermod -aG docker deploy
```

---

# 5. DNS

Créer les enregistrements A sur Cloudflare :

```
Type  Nom                                Valeur          Proxy
A     serviceproof.vylantic.com          XX.XX.XX.XX     ☁️
A     api.serviceproof.vylantic.com      XX.XX.XX.XX     ☁️
A     ai.serviceproof.vylantic.com       XX.XX.XX.XX     ☁️
```

Vérifier :

```bash
dig +short serviceproof.vylantic.com
dig +short api.serviceproof.vylantic.com
dig +short ai.serviceproof.vylantic.com
```

---

# 6. Structure serveur

Le dépôt est un monorepo : il est cloné tel quel, et le compose de production vit à l'intérieur.

```
/var/www/
├── proxy/                              # Nginx mutualisé (existe déjà si autre app)
│   ├── docker-compose.yml
│   ├── certbot/{conf,www}
│   └── nginx/{nginx.conf,conf.d/,snippets/}
│
└── serviceproof/                       # Monorepo cloné
    ├── apps/
    │   ├── api/                        # Laravel 11
    │   ├── agent/                      # FastAPI
    │   ├── web/                        # Next.js 15
    │   └── mobile/                     # Flutter (non déployé ici)
    ├── infra/
    │   ├── docker/                     # Dockerfiles dev + prod
    │   └── production/
    │       ├── docker-compose.prod.yml
    │       ├── nginx-api.conf
    │       ├── supervisord.conf
    │       ├── api-entrypoint.sh
    │       ├── .env                    ← à créer, chmod 600
    │       └── data/                   ← volumes, créé au premier démarrage
    ├── docs/
    └── docker-compose.yml              # développement, non utilisé ici
```

```bash
mkdir -p /var/www/serviceproof
chown -R deploy:deploy /var/www/serviceproof
```

---

# 7. Réseaux Docker

```bash
# Réseau proxy (existe déjà si Kynara installée)
docker network create proxy-network 2>/dev/null || true

# Réseau dédié ServiceProof
docker network create serviceproof-network
```

---

# 8. Reverse proxy Nginx (vhosts ServiceProof)

> Si le proxy est déjà installé, il suffit d'ajouter les 3 vhosts ci-dessous dans `/var/www/proxy/nginx/conf.d/`. Sinon, installer le proxy complet tel que décrit dans le guide Kynara §9.

### 8.1 — `/var/www/proxy/nginx/conf.d/serviceproof-web.conf`

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name serviceproof.vylantic.com;

    location /.well-known/acme-challenge/ { root /var/www/certbot; }
    location / { return 301 https://$host$request_uri; }
}

server {
    listen 443 ssl;
    listen [::]:443 ssl;
    http2 on;
    server_name serviceproof.vylantic.com;

    ssl_certificate     /etc/letsencrypt/live/serviceproof.vylantic.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/serviceproof.vylantic.com/privkey.pem;

    include /etc/nginx/snippets/ssl.conf;
    include /etc/nginx/snippets/security.conf;

    access_log /var/log/nginx/serviceproof-web-access.log main;
    error_log  /var/log/nginx/serviceproof-web-error.log warn;

    location /_next/static/ {
        proxy_pass http://serviceproof-web:3000;
        include /etc/nginx/snippets/proxy.conf;
        add_header Cache-Control "public, max-age=31536000, immutable";
    }

    location / {
        proxy_pass http://serviceproof-web:3000;
        include /etc/nginx/snippets/proxy.conf;
    }
}
```

### 8.2 — `/var/www/proxy/nginx/conf.d/serviceproof-api.conf`

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name api.serviceproof.vylantic.com;

    location /.well-known/acme-challenge/ { root /var/www/certbot; }
    location / { return 301 https://$host$request_uri; }
}

server {
    listen 443 ssl;
    listen [::]:443 ssl;
    http2 on;
    server_name api.serviceproof.vylantic.com;

    ssl_certificate     /etc/letsencrypt/live/api.serviceproof.vylantic.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/api.serviceproof.vylantic.com/privkey.pem;

    include /etc/nginx/snippets/ssl.conf;
    include /etc/nginx/snippets/security.conf;

    access_log /var/log/nginx/serviceproof-api-access.log main;
    error_log  /var/log/nginx/serviceproof-api-error.log warn;

    client_max_body_size 32M;

    # Une vérification attend le réseau mobile : jusqu'à 3 appels CAMARA
    # séquentiels, chacun pouvant prendre plusieurs secondes. AGENT_TIMEOUT
    # vaut 45 s côté Laravel ; le proxy doit être plus patient que lui.
    proxy_read_timeout 90s;
    proxy_send_timeout 90s;

    # Le canal interne agent → Laravel n'a rien à faire sur Internet.
    location /api/v1/internal/ { deny all; }

    location / {
        proxy_pass http://serviceproof-api:8000;
        include /etc/nginx/snippets/proxy.conf;
    }
}
```

### 8.3 — `/var/www/proxy/nginx/conf.d/serviceproof-agent.conf`

> **Ce vhost est volontairement plus fermé que les deux autres.** Le runtime agent est un service interne : Laravel l'appelle sur le réseau privé, jamais par ce domaine. Il est exposé pour que les juges puissent constater qu'il tourne, pas pour recevoir du trafic.

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name ai.serviceproof.vylantic.com;

    location /.well-known/acme-challenge/ { root /var/www/certbot; }
    location / { return 301 https://$host$request_uri; }
}

server {
    listen 443 ssl;
    listen [::]:443 ssl;
    http2 on;
    server_name ai.serviceproof.vylantic.com;

    ssl_certificate     /etc/letsencrypt/live/ai.serviceproof.vylantic.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/ai.serviceproof.vylantic.com/privkey.pem;

    include /etc/nginx/snippets/ssl.conf;
    include /etc/nginx/snippets/security.conf;

    access_log /var/log/nginx/serviceproof-agent-access.log main;
    error_log  /var/log/nginx/serviceproof-agent-error.log warn;

    proxy_read_timeout 90s;
    proxy_send_timeout 90s;

    # Publiquement lisibles : l'état du runtime et le catalogue d'outils.
    # Les deux sont conçus pour être montrés — ils ne révèlent aucune donnée
    # client, seulement quel planner tourne et quelles capacités existent.
    location = /health { proxy_pass http://serviceproof-agent:9000; include /etc/nginx/snippets/proxy.conf; }
    location = /tools  { proxy_pass http://serviceproof-agent:9000; include /etc/nginx/snippets/proxy.conf; }

    # Tout le reste, dont POST /agent/verify, est refusé depuis Internet.
    # Le jeton X-Internal-Token protège déjà cet endpoint, mais un secret
    # partagé est la deuxième ligne de défense, pas la première.
    location / { return 403; }
}
```

> Si vous préférez montrer la documentation OpenAPI pendant la démo, ajoutez `location = /docs` et `location = /openapi.json`. Sachez que `SP_ENV=production` les **désactive dans le code** (`apps/agent/app/main.py`) : il faudrait passer `SP_ENV=staging`, ce qui est un choix conscient et temporaire.

---

# 9. Variables d'environnement

### `/var/www/serviceproof/infra/production/.env`

```bash
# ─── PostgreSQL ─────────────────────────────────────
DB_DATABASE=serviceproof
DB_USERNAME=serviceproof
DB_PASSWORD=                          # openssl rand -base64 32

# ─── Redis ──────────────────────────────────────────
REDIS_PASSWORD=                       # openssl rand -base64 32

# ─── Laravel ────────────────────────────────────────
APP_NAME="ServiceProof AI"
APP_ENV=production
APP_DEBUG=false
APP_KEY=                              # php artisan key:generate --show
APP_URL=https://api.serviceproof.vylantic.com
FRONTEND_URL=https://serviceproof.vylantic.com
LOG_CHANNEL=stderr
LOG_LEVEL=warning

# Durée de vie des jetons Sanctum, en minutes. Vide = jamais, ce qui est
# acceptable pour une démo et faux pour le reste.
SANCTUM_TOKEN_EXPIRATION=720

# Le compte root de plateforme créé par PlatformSeeder. À changer AVANT le
# premier seed : après, il faut le modifier en base.
SP_SUPER_ADMIN_PASSWORD=              # openssl rand -base64 24

# ─── Canal interne Laravel ⇄ agent ──────────────────
# Secret partagé. L'agent refuse toute requête sans ce jeton exact.
AGENT_INTERNAL_TOKEN=                 # openssl rand -hex 32
AGENT_TIMEOUT=45

# ─── Runtime agent ──────────────────────────────────
# heuristic | gemini | groq | ollama
# `heuristic` fait tourner la boucle complète sans aucun modèle externe :
# c'est le filet de sécurité pour une démo, et le défaut raisonnable.
LLM_PROVIDER=groq
GROQ_API_KEY=
GROQ_MODEL=llama-3.3-70b-versatile
GEMINI_API_KEY=
LLM_TIMEOUT=20

# ─── Nokia Network as Code ──────────────────────────
# auto  : live d'abord, repli simulé étiqueté si l'appel échoue
# live  : live uniquement, les échecs remontent honnêtement
# demo  : simulé uniquement
CAMARA_MODE=auto
NAC_RAPIDAPI_KEY=
NAC_RAPIDAPI_HOST=network-as-code.nokia.rapidapi.com
NAC_TIMEOUT=8
NAC_MAX_RETRIES=1

# Fenêtres d'interprétation des preuves.
DEFAULT_MAX_AGE_SECONDS=900
CONTEMPORANEITY_WINDOW_SECONDS=1800
```

```bash
chmod 600 /var/www/serviceproof/infra/production/.env
```

> **Les chemins `NAC_PATH_*` n'ont pas à être renseignés.** Les défauts confirmés vivent dans `apps/agent/app/config.py`. Ne les ajoutez au `.env` que pour surcharger un chemin qui aurait changé — et si vous le faites, notez que copier ce fichier ailleurs les figera.

> **Les coordonnées de démo `SP_DEMO_SITE_*` sont volontairement absentes.** Elles sont commentées dans `.env.example` pour la même raison : les copier fige la géographie et une correction du seeder devient sans effet.

---

# 10. Docker Compose production

Le fichier est **déjà dans le dépôt** : `infra/production/docker-compose.prod.yml`. Il n'y a rien à coller.

Ce qu'il fait, et pourquoi :

| Choix | Raison |
|---|---|
| Aucun `ports:` publié | Le proxy atteint les conteneurs par `proxy-network`. PostgreSQL et Redis ne sortent pas de `serviceproof-network`. |
| Aucun bind-mount du code | L'image est l'artefact. Un déploiement est une reconstruction, pas une copie de fichiers. |
| `AGENT_BASE_URL: http://serviceproof-agent:9000` | L'appel reste sur le réseau privé. |
| `API_BASE_URL: http://serviceproof-api:8000/api/v1` | Idem pour la console. Ce n'est pas une variable `NEXT_PUBLIC_*` : elle n'entre jamais dans le bundle navigateur. |
| Agent sans volume | Il est sans état par conception. Le redémarrer ne perd rien. |
| `TZ: UTC` sur PostgreSQL | Les preuves et l'audit sont horodatés en UTC, comme ce que renvoie l'opérateur. |

Les trois images de production sont dans `infra/docker/*.prod.Dockerfile`. Les originales (`laravel.Dockerfile`, `agent.Dockerfile`, `web.Dockerfile`) restent pour `docker-compose.yml` en développement.

### La version de PHP doit correspondre à votre `composer.lock`

L'image API est construite sur **PHP 8.4** par défaut, parce que c'est la version sur laquelle le `composer.lock` du dépôt a été résolu. Ce n'est pas un détail cosmétique : Composer écrit un `platform_check.php` à partir du lock, et il **échoue au build** si la plateforme diverge.

```
In platform_check.php line 22:
  Your Composer dependencies require a PHP version ">= 8.4.1". You are running 8.3.33.
```

Pour construire sur une autre version :

```bash
# ponctuel
docker compose -f infra/production/docker-compose.prod.yml build --build-arg PHP_VERSION=8.3 api

# ou durablement, dans infra/production/.env
PHP_VERSION=8.3
```

Vérifier quelle version votre lock exige :

```bash
grep -o '"php": "[^"]*"' apps/api/composer.lock | sort -u | head
```

> **Gardez `composer.lock` versionné.** Sans lui, chaque build résout les dépendances à nouveau et deux déploiements successifs peuvent embarquer des versions différentes. C'est aussi ce fichier qui rend l'erreur ci-dessus déterministe plutôt qu'aléatoire.

---

# 11. Configuration Next.js (standalone)

`apps/web/next.config.ts` contient déjà :

```typescript
const config: NextConfig = {
  reactStrictMode: true,
  output: "standalone",   // ← requis par web.prod.Dockerfile
  poweredByHeader: false,
  // …
};
```

Rien à modifier. Le mode standalone produit un serveur autonome (~79 Mo) au lieu d'embarquer `node_modules` entier.

---

# 12. Récupération du code

```bash
cd /var/www/serviceproof
git clone git@github.com:VOTRE-ORG/serviceproof.git .

# Le compose de production vit dans le dépôt
ls infra/production/docker-compose.prod.yml
```

Créer le `.env` de la section 9 à `infra/production/.env`.

> `infra/production/.env` et `infra/production/data/` sont exclus par le `.dockerignore` racine et par `.gitignore`. Ils restent sur le serveur et n'entrent dans aucune image.

---

# 13. Certificats Let's Encrypt

### 13.1 — Bootstrap HTTP (première fois)

```bash
cat > /var/www/proxy/nginx/conf.d/_serviceproof-bootstrap.conf << 'EOF'
server {
    listen 80;
    listen [::]:80;
    server_name serviceproof.vylantic.com api.serviceproof.vylantic.com ai.serviceproof.vylantic.com;

    location /.well-known/acme-challenge/ { root /var/www/certbot; }
    location / { return 200 "ServiceProof Bootstrap OK"; add_header Content-Type text/plain; }
}
EOF
```

Désactiver les vhosts HTTPS :

```bash
cd /var/www/proxy/nginx/conf.d
for f in serviceproof-web.conf serviceproof-api.conf serviceproof-agent.conf; do
  [ -f "$f" ] && mv "$f" "${f}.disabled"
done
docker exec proxy-nginx nginx -s reload
```

### 13.2 — Émettre les certificats

```bash
cd /var/www/proxy
docker compose run --rm --entrypoint certbot certbot certonly \
  --webroot -w /var/www/certbot \
  --email VOTRE-EMAIL --agree-tos --no-eff-email \
  -d serviceproof.vylantic.com \
  -d api.serviceproof.vylantic.com \
  -d ai.serviceproof.vylantic.com
```

### 13.3 — Réactiver les vhosts complets

```bash
cd /var/www/proxy/nginx/conf.d
for f in serviceproof-web.conf serviceproof-api.conf serviceproof-agent.conf; do
  [ -f "${f}.disabled" ] && mv "${f}.disabled" "$f"
done
rm -f _serviceproof-bootstrap.conf
docker exec proxy-nginx nginx -s reload
```

### 13.4 — Tester

```bash
curl -I https://serviceproof.vylantic.com
curl -I https://api.serviceproof.vylantic.com
curl -s https://ai.serviceproof.vylantic.com/health | jq
```

---

# 14. Premier déploiement

Toutes les commandes se lancent **depuis la racine du dépôt**, avec le compose de production.

```bash
cd /var/www/serviceproof
alias spc='docker compose -f infra/production/docker-compose.prod.yml --env-file infra/production/.env'

# Valider avant de construire
spc config > /dev/null && echo "compose OK"

# Générer APP_KEY (l'entrypoint refuse de démarrer sans)
docker run --rm -v "$PWD/apps/api:/app" -w /app php:8.3-cli \
  sh -c 'php -r "echo \"base64:\", base64_encode(random_bytes(32)), PHP_EOL;"'
# → coller dans infra/production/.env  (APP_KEY=base64:...)

# Construire et démarrer
spc up -d --build

# Suivre le démarrage
spc logs -f
```

### Préparation de la base

L'entrypoint de production **ne migre pas**, délibérément : un redémarrage pendant un incident ne doit pas appliquer une migration à moitié écrite. Les changements de schéma sont explicites.

```bash
spc exec api php artisan migrate --force

# Seed : crée le compte plateforme et le locataire de démonstration
spc exec api php artisan db:seed --force
```

> Le seed lit `SP_SUPER_ADMIN_PASSWORD`. Vérifiez qu'il est renseigné **avant** cette commande.

---

# 15. Vérifications

```bash
docker ps --filter name=serviceproof --format 'table {{.Names}}\t{{.Status}}'

# Console
curl -I https://serviceproof.vylantic.com

# API : la santé de l'agent vue par Laravel — c'est le test qui compte,
# parce qu'il traverse le canal interne et le jeton partagé.
curl -s https://api.serviceproof.vylantic.com/api/v1/verifications/health \
  -H "Authorization: Bearer <token>" | jq

# Agent, directement
curl -s https://ai.serviceproof.vylantic.com/health | jq
# → doit montrer planner, camara.mode et camara.live_credentials

# Le vhost agent refuse bien le reste
curl -s -o /dev/null -w '%{http_code}\n' -X POST https://ai.serviceproof.vylantic.com/agent/verify
# → 403 attendu
```

### Vérifier le chemin complet, une fois connecté

```bash
TOKEN=$(curl -s -X POST https://api.serviceproof.vylantic.com/api/v1/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"ops@acme-field.test","password":"password","device_name":"smoke"}' | jq -r .token)

CLAIM=$(curl -s "https://api.serviceproof.vylantic.com/api/v1/claims" \
  -H "Authorization: Bearer $TOKEN" | jq -r '.data[0].id')

curl -s -X POST "https://api.serviceproof.vylantic.com/api/v1/claims/$CLAIM/verify" \
  -H "Authorization: Bearer $TOKEN" | jq '.data.decision.state, .data.tool_calls_used'
```

> **Changez les mots de passe de démonstration** avant d'exposer l'installation. `password` est dans le dépôt public.

---

# 16. Backups automatiques

### `/usr/local/bin/backup-serviceproof.sh`

```bash
#!/bin/bash
set -euo pipefail

BACKUP_ROOT=/var/backups/serviceproof
TS=$(date +%Y%m%d-%H%M%S)
mkdir -p "$BACKUP_ROOT/$TS"

cd /var/www/serviceproof
COMPOSE="docker compose -f infra/production/docker-compose.prod.yml --env-file infra/production/.env"

# PostgreSQL — les preuves et l'audit sont append-only : ce dump est la
# seule chose qui ne peut pas être reconstruite.
$COMPOSE exec -T postgres \
  pg_dump -U "${DB_USERNAME:-serviceproof}" "${DB_DATABASE:-serviceproof}" \
  | gzip > "$BACKUP_ROOT/$TS/postgres.sql.gz"

# Fichiers écrits par Laravel
tar -czf "$BACKUP_ROOT/$TS/api-storage.tar.gz" infra/production/data/api-storage 2>/dev/null || true

# Rotation 14 jours
find "$BACKUP_ROOT" -mindepth 1 -maxdepth 1 -type d -mtime +14 -exec rm -rf {} \;

echo "✅ Backup ServiceProof : $BACKUP_ROOT/$TS"
```

```bash
chmod +x /usr/local/bin/backup-serviceproof.sh
crontab -e
# 0 3 * * * /usr/local/bin/backup-serviceproof.sh >> /var/log/backup-serviceproof.log 2>&1
```

> Ni Redis ni le runtime agent ne sont sauvegardés : le premier est un cache, le second est sans état. Perdre les deux coûte un redémarrage.

---

# 17. Déploiement continu

### `/usr/local/bin/deploy-serviceproof.sh`

```bash
#!/bin/bash
set -euo pipefail

cd /var/www/serviceproof
COMPOSE="docker compose -f infra/production/docker-compose.prod.yml --env-file infra/production/.env"

git pull origin main

$COMPOSE build
$COMPOSE up -d

# Les migrations sont explicites, jamais dans l'entrypoint.
$COMPOSE exec -T api php artisan migrate --force

# Les caches sont reconstruits par l'entrypoint à chaque démarrage ; cette
# ligne ne sert qu'à un rechargement sans redémarrage.
$COMPOSE exec -T api php artisan config:cache

echo "✅ ServiceProof déployé."
```

```bash
chmod +x /usr/local/bin/deploy-serviceproof.sh
```

---

# 18. Logs & monitoring

```bash
cd /var/www/serviceproof
alias spc='docker compose -f infra/production/docker-compose.prod.yml --env-file infra/production/.env'

spc logs -f                # tous
spc logs -f api            # Laravel (nginx + php-fpm sur stdout)
spc logs -f agent          # FastAPI — les appels CAMARA apparaissent ici
spc logs -f web            # Next.js

# Nginx (via proxy)
docker exec proxy-nginx tail -f /var/log/nginx/serviceproof-api-access.log

docker stats --no-stream | grep serviceproof
```

**Ce qu'il faut surveiller dans `spc logs -f agent`** : chaque vérification écrit une ligne `httpx` par appel CAMARA avec le code de retour. Une rafale de `500` ou `400` vers `network-as-code.p-eu.apihub.nokia.io` signifie que les décisions repassent en `UNVERIFIED` — le système dégrade correctement, mais il ne produit plus de preuve.

---

# 19. Optimisations production

### Swap

```bash
fallocate -l 2G /swapfile && chmod 600 /swapfile
mkswap /swapfile && swapon /swapfile
echo '/swapfile none swap sw 0 0' >> /etc/fstab
```

> Utile surtout pendant `docker compose build` : la compilation Next.js est le pic mémoire de tout le déploiement.

### PostgreSQL

Sur 4 Go, les défauts de l'image conviennent. Le volume de données de ServiceProof est modeste — quelques milliers de lignes de preuves par mois — et les requêtes sont scopées par locataire. N'ajustez `shared_buffers` que si `pg_stat_statements` montre un problème réel.

### Nombre de workers agent

`UVICORN_WORKERS` vaut 2 par défaut. Un worker passe l'essentiel de son temps à attendre le réseau mobile, donc monter à 4 sur 2 vCPU est raisonnable si plusieurs vérifications se chevauchent.

### Cloudflare

Proxy orange activé pour CDN, DDoS et masquage IP. Mode SSL : **Full (strict)**.

---

# 20. Checklist sécurité

- [ ] Firewall UFW activé (22/80/443 uniquement)
- [ ] SSH root par mot de passe désactivé
- [ ] fail2ban actif, NTP actif
- [ ] Mots de passe PostgreSQL et Redis générés (`openssl rand -base64 32`)
- [ ] `AGENT_INTERNAL_TOKEN` généré (`openssl rand -hex 32`), identique côté Laravel et agent
- [ ] `SP_SUPER_ADMIN_PASSWORD` changé **avant** le premier seed
- [ ] Mots de passe des comptes de démonstration changés ou comptes supprimés
- [ ] `infra/production/.env` en `chmod 600`
- [ ] `APP_DEBUG=false` et `APP_ENV=production`
- [ ] Vhost agent : seuls `/health` et `/tools` sont publics
- [ ] `/api/v1/internal/` refusé par le vhost API
- [ ] `NAC_RAPIDAPI_KEY` jamais commité ; régénérée si elle a circulé
- [ ] Cloudflare devant le droplet, SSL Full (strict)
- [ ] Backups quotidiens vérifiés (restaurer un dump une fois, pas seulement le produire)

---

# 21. Dépannage

### Le build de l'image API échoue sur `platform_check.php`

```
Your Composer dependencies require a PHP version ">= 8.4.1". You are running 8.3.x
```

La version de PHP de l'image ne correspond pas à celle qui a résolu `composer.lock`. Construire avec la bonne :

```bash
PHP_VERSION=8.4 docker compose -f infra/production/docker-compose.prod.yml build api
```

Les étapes `vendor` et `runtime` partagent la même image de base, donc corriger `PHP_VERSION` corrige les deux d'un coup — il n'y a pas de second endroit à changer.

### Le conteneur API refuse de démarrer

```bash
spc logs api
# "FATAL: APP_KEY is not set" → l'entrypoint refuse de servir sans clé.
# C'est voulu : sans APP_KEY, Laravel ne peut ni chiffrer ni signer.
```

### 502 Bad Gateway

```bash
docker exec proxy-nginx nginx -t
docker network inspect proxy-network | grep serviceproof
# serviceproof-web, -api et -agent doivent y figurer.
```

### La console affiche « Agent unreachable »

Le plus souvent, les deux jetons diffèrent.

```bash
# Le même secret des deux côtés ?
spc exec api printenv AGENT_INTERNAL_TOKEN
spc exec agent printenv AGENT_INTERNAL_TOKEN

# L'agent répond-il sur le réseau privé ?
spc exec api curl -s http://serviceproof-agent:9000/health
```

### Les vérifications reviennent toutes `UNVERIFIED`

```bash
spc logs agent | grep httpx | tail -20
```

- `401` / `403` vers Nokia → `NAC_RAPIDAPI_KEY` invalide ou expirée.
- `400` → le numéro interrogé n'est pas connu du réseau : problème de mapping d'appareil, pas de panne. La preuve le dit explicitement.
- `500` → panne côté opérateur ; en `CAMARA_MODE=auto` le repli simulé prend le relais et **chaque élément est étiqueté**.
- Aucune ligne `httpx` → `CAMARA_MODE=demo`, ou aucune clé configurée.

### Les preuves passent en `STALE` sans raison

```bash
timedatectl status   # NTP service: active ?
```

L'horloge du serveur est comparée aux horodatages de l'opérateur. Une dérive de plusieurs minutes suffit.

### Certbot refuse

```bash
curl http://serviceproof.vylantic.com/.well-known/acme-challenge/test
# Doit renvoyer 404 (pas 502) → Nginx joignable
dig +short serviceproof.vylantic.com
```

### Disque saturé

```bash
docker system prune -af
ncdu /var
journalctl --vacuum-time=7d
```

---

# 22. L'application mobile

L'app Flutter n'est pas déployée sur le droplet : elle est compilée et distribuée séparément. Elle a seulement besoin de connaître l'API.

```bash
cd apps/mobile
flutter build apk --release \
  --dart-define=API_BASE_URL=https://api.serviceproof.vylantic.com/api/v1
```

Le technicien se connecte avec un compte `FIELD_WORKER`. Aucune configuration serveur supplémentaire : le combiné parle à la même API que la console, avec les mêmes jetons Sanctum.

---

# 23. Architecture finale

```
                   Internet
                       │
                  Cloudflare DNS
                       │
                   Port 80/443
                       ▼
            ┌────────────────────┐
            │   proxy-nginx      │  (proxy-network)
            └────────────────────┘
                       │
       ┌───────────────┼────────────────┐
       ▼               ▼                ▼
 serviceproof-web  serviceproof-api  serviceproof-agent
   (Next.js)         (Laravel)          (FastAPI)
       │                 │                  │
       └────────►────────┤                  │
         API_BASE_URL    │  AGENT_BASE_URL  │
         (réseau privé)  ├────────►─────────┘
                         │                  │
                         ▼                  ▼
              ┌──────────────────┐   Nokia Network as Code
              │ serviceproof-    │   (sortant, HTTPS)
              │   postgres       │
              │   redis          │
              └──────────────────┘
              (serviceproof-network)
```

ServiceProof est en production sur `serviceproof.vylantic.com`.

---

*Fin du guide ServiceProof.*
