# S4W — File Store

Single-container file store (PHP + nginx + optional bundled PostgreSQL).
Configuration is **entirely via environment variables** — no files to edit.

- **Web panel:** `http://localhost:<port>/web`
- **API:** `http://localhost:<port>` — Postman collection in [`docs/re.json`](docs/re.json)
- **Image:** `flytachi/s4w:latest`

It can run with a **bundled PostgreSQL** (zero setup) or your **own PostgreSQL**
(set `DB_HOST`). Secrets (`WINTER_KEY`, `TOKEN`) are auto-generated if not provided.

> ## ⚠️ Read this first — data & volumes
> Without volumes the container is **ephemeral**: recreating it (image update,
> `--force-recreate`, host reboot without restart policy) **wipes everything**.
> Mount **two** volumes for any real use:
>
> | Mount | Keeps |
> |---|---|
> | `/var/lib/postgresql/data` | the **bundled database** (instances, file records, tokens) |
> | `/var/www/html/storage` | the **uploaded files** + generated `WINTER_KEY`/`TOKEN` |
>
> Lose `…/storage` ⇒ files gone **and** all issued JWT/tokens become invalid
> (secrets regenerate). Lose `…/postgresql/data` ⇒ the DB is re-initialized empty.
> Using an **external DB**? Then only `…/storage` matters (your DB lives elsewhere).

---

## `docker run`

**Quick try (no persistence — for testing only):**
```bash
docker run -d --name s4w -p 9090:80 \
  -e ADMIN_LOGIN=admin -e ADMIN_PASSWORD=change-me \
  flytachi/s4w:latest
# http://localhost:9090/web  → admin / change-me
```

**Persistent (bundled DB + volumes — recommended):**
```bash
docker run -d --name s4w -p 9090:80 \
  -e ADMIN_LOGIN=admin -e ADMIN_PASSWORD=change-me \
  -e UPLOAD_MAX_FILESIZE=200M \
  -v s4w_storage:/var/www/html/storage \
  -v s4w_pgdata:/var/lib/postgresql/data \
  --restart unless-stopped \
  flytachi/s4w:latest
```

**External PostgreSQL:**
```bash
docker run -d --name s4w -p 9090:80 \
  -e DB_HOST=db.example.com -e DB_PORT=5432 \
  -e DB_NAME=s4w -e DB_USER=s4w -e DB_PASS=super-secret \
  -e ADMIN_LOGIN=admin -e ADMIN_PASSWORD=change-me \
  -e WINTER_KEY=<64-hex> -e TOKEN=<64-hex> \
  -v s4w_storage:/var/www/html/storage \
  --restart unless-stopped \
  flytachi/s4w:latest

# create the schema once on a fresh external DB:
docker exec s4w sh -c "cd /var/www/html && php call db migrate"
```

---

## `docker compose`

**Minimal (no persistence — for testing only):**
```yaml
services:
  s4w:
    image: flytachi/s4w:latest
    ports:
      - "9090:80"
    environment:
      ADMIN_LOGIN: admin
      ADMIN_PASSWORD: change-me
```
```bash
docker compose up -d
```

**Persistent (bundled DB + volumes — recommended):**
```yaml
services:
  s4w:
    image: flytachi/s4w:latest
    ports:
      - "9090:80"
    environment:
      ADMIN_LOGIN: admin
      ADMIN_PASSWORD: change-me
      UPLOAD_MAX_FILESIZE: 200M
    volumes:
      - s4w_storage:/var/www/html/storage      # files + generated secrets
      - s4w_pgdata:/var/lib/postgresql/data     # bundled database
    restart: unless-stopped

volumes:
  s4w_storage:
  s4w_pgdata:
```

**External PostgreSQL:**
```yaml
services:
  s4w:
    image: flytachi/s4w:latest
    ports:
      - "9090:80"
    environment:
      DB_HOST: db.example.com
      DB_PORT: "5432"
      DB_NAME: s4w
      DB_USER: s4w
      DB_PASS: super-secret
      ADMIN_LOGIN: admin
      ADMIN_PASSWORD: change-me
      WINTER_KEY: "<64-hex>"   # pin so they survive recreate
      TOKEN: "<64-hex>"
    volumes:
      - s4w_storage:/var/www/html/storage
    restart: unless-stopped

volumes:
  s4w_storage:
```
```bash
docker compose up -d
# fresh external DB only — create schema once:
docker compose exec s4w sh -c "cd /var/www/html && php call db migrate"
```

---

## Environment variables

| Variable | Default | Notes |
|---|---|---|
| `ADMIN_LOGIN` | — | **Required.** Empty ⇒ panel login denied (fail-closed). |
| `ADMIN_PASSWORD` | — | **Required.** As above. |
| `DB_HOST` | _(bundled PG)_ | Unset → built-in PostgreSQL. Set → external DB. |
| `DB_PORT` | `5432` | |
| `DB_NAME` | `s4w` | |
| `DB_USER` | `s4w` | |
| `DB_PASS` | `s4w` | Change for external DB. |
| `DB_SCHEMA` | `public` | |
| `WINTER_KEY` | _auto_ | JWT signing key. Auto-generated if unset (persisted to `storage`). |
| `TOKEN` | _auto_ | Static bearer for the login endpoint. Auto-generated if unset. |
| `UPLOAD_MAX_FILESIZE` | `100M` | Max upload size; drives php + nginx limits. |
| `UPLOAD_HOST` | _(request host)_ | Base URL used in returned `privateUrl`/`publicUrl` (e.g. `https://files.example.com`). Include the scheme; this host must route to the service. |
| `PHP_MEMORY_LIMIT` | `256M` | Optional. |
| `PHP_MAX_EXECUTION_TIME` | `300` | Optional (seconds). |
| `PHP_MAX_INPUT_TIME` | `300` | Optional (seconds). |
| `TIME_ZONE` | `UTC` | |
| `DEBUG` | `false` | Keep `false` in production (debug responses leak internals). |
| `LOG_LEVEL` | `warning` | `debug\|info\|warning\|error\|…`; empty disables logging. |
| `LOG_OUTPUT` | `syslog` | `auto\|stdout\|stderr\|syslog\|file`. `syslog` → container stdout. |

---

## Build the image yourself

```bash
docker build -t s4w:local .
docker run -d -p 9090:80 -e ADMIN_LOGIN=admin -e ADMIN_PASSWORD=change-me s4w:local
```
> `--build-arg DISABLE_OPCACHE=true` only for local dev (opcache off). Default is prod.

---

## Common operations

```bash
docker logs -f s4w                                              # logs
docker exec s4w sh -c "cd /var/www/html && php call db migrate" # migrate (external DB)
docker exec -it s4w sh                                          # shell
```

Maintenance GC runs daily inside the container via cron:
- `OrphanFolderGc` (01:00) — removes `storage/chest/<id>` of deleted instances;
- `OrphanBlobGc` (01:30) — removes blob files without a DB row.

---

## Notes & gotchas

- **Panel needs `ADMIN_LOGIN` + `ADMIN_PASSWORD`** — without them login is refused by
  design. The login `TOKEN` is generated/served automatically; you don't set it to log in.
- **Bundled DB is in-container** — persist it with the `…/postgresql/data` volume, or
  every recreate re-initializes an empty DB and re-runs the migration.
- **Generated secrets** survive only with the `…/storage` volume; otherwise they change
  on each start (invalidating issued JWT/tokens). For external DB, pin `WINTER_KEY`/`TOKEN`.
- **Login brute-force** is throttled (5 attempts / 15 min → HTTP 429).
- **Public URLs** `/o/{instanceId}/...` need no auth — only files in `root` and in
  sections marked *public* are served there.
- **`UPLOAD_HOST`** rewrites the host in the `privateUrl`/`publicUrl` fields returned by
  the API (e.g. serve files via a CDN / custom domain like `https://files.example.com`).
  It only changes the returned links — that host must proxy to this container, and the
  value must include the scheme (`https://…`). Leave unset to use the request host.
