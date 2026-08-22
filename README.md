# fantasy.recipes

A recipe site where every recipe is framed as an in-world fantasy ritual.

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
docker compose up -d --build
docker compose exec app vendor/bin/phinx migrate
```

The `web` service binds to `127.0.0.1:8080` only -- it's designed to sit
behind the host's existing nginx reverse proxy, not to face the internet
directly.

## Static analysis

```
composer stan
```

## Deploying

SSH into the host, then:

```
git pull
docker compose up -d --build
docker compose exec app vendor/bin/phinx migrate
```
