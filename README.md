# fantasy.recipes

A recipe site where every recipe is framed as an in-world fantasy ritual.

The production domain is **fantasyrecipes.co.uk** (registered 2026-08-31).

## Local development (SQLite, no Docker)

```
composer install
cp .env.testing.example .env.testing
vendor/bin/phinx migrate -e testing
composer test
```

## Docker (MySQL, mirrors production)

MySQL is expected to already exist on the host -- this stack does not run
its own MySQL container (see architecture.md -- Deployment & Networking).

```
cp .env.example .env   # fill in DB_USERNAME / DB_PASSWORD etc.
composer install       # dev deps -- the Phinx CLI is NOT in the runtime image
docker compose up -d --build
vendor/bin/phinx migrate -e production   # from the host, against the DB in .env
```

Migrations run from the host, not `docker compose exec` -- Phinx is a dev
dependency (`require-dev`), not shipped in the container, and won't run on
32-bit PHP at all (see architecture.md -- Migrations).

The `web` service binds to `127.0.0.1:8080` only -- it's designed to sit
behind the host's existing nginx reverse proxy, not to face the internet
directly.

## Static analysis

```
composer stan
```

## Deploying

The target host is a 32-bit ARM Pi. SSH in and update the code:

```
git pull
docker compose up -d --build
```

Migrations do **not** run on the Pi (Phinx needs 64-bit PHP -- see
architecture.md -- Migrations). Run them from a 64-bit machine against the
Pi's MariaDB over an SSH tunnel:

```
ssh -f -N -L 13306:127.0.0.1:3306 <host>
# local .env: DB_HOST=127.0.0.1, DB_PORT=13306, prod DB_DATABASE/USERNAME/PASSWORD
vendor/bin/phinx migrate -e production
```

The Pi also carries an untracked `docker-compose.override.yml` (prod tweaks:
no dev bind mount, joins the reverse proxy's network) -- see
architecture.md -- Deployment & Networking.
