# S4W — Deploy with Docker Compose

S4W is a single-container file store (PHP + nginx). Configuration is **entirely via
environment variables** — no config files to edit.

- **Web panel:** `http://localhost:<port>/web`
- **API base:** `http://localhost:<port>` (see `docs/re.json` Postman collection)

The container can run with a **bundled PostgreSQL** (zero setup) or connect to your
**own PostgreSQL** (set `DB_HOST`). Secrets (`WINTER_KEY`, `TOKEN`) are auto-generated
if not provided.

---

## 1. Quick start (bundled PostgreSQL, ephemeral)

Smallest working setup. The database lives inside the container and is **reset on
recreate** — fine for trying it out.

```yaml
# docker-compose.yml
services:
  s4w:
    image: flytachi/s4w:latest      # or `build: .` to build locally
    ports:
      - "8080:80"
    environment:
      ADMIN_LOGIN: admin
      ADMIN_PASSWORD: change-me      # REQUIRED — without it the panel is locked
```

```bash
docker compose up -d
# open http://localhost:8080/web  → log in with admin / change-me
```

> No `DB_HOST` ⇒ the container starts its bundled PostgreSQL and runs the schema
> migration automatically on first boot.

---

## 2. Self-host (bundled PostgreSQL + persistence) — recommended

Keep files, database and generated secrets across restarts.

```yaml
services:
  s4w:
    image: flytachi/s4w:latest
    ports:
      - "8080:80"
    environment:
      ADMIN_LOGIN: admin
      ADMIN_PASSWORD: change-me
      UPLOAD_MAX_FILESIZE: 200M
    volumes:
      - s4w_storage:/var/www/html/storage      # uploaded files + generated secrets
      - s4w_pgdata:/var/lib/postgresql/data     # database survives restarts
    restart: unless-stopped

volumes:
  s4w_storage:
  s4w_pgdata:
```

> Mounting `…/storage` keeps generated `WINTER_KEY`/`TOKEN` (so issued JWTs/tokens
> stay valid). Mounting `…/postgresql/data` keeps your data.

---

## 3. External PostgreSQL

Point S4W at an existing database. The bundled PostgreSQL is **not** started.

```yaml
services:
  s4w:
    image: flytachi/s4w:latest
    ports:
      - "8080:80"
    environment:
      DB_HOST: db.example.com
      DB_PORT: "5432"
      DB_NAME: s4w
      DB_USER: s4w
      DB_PASS: super-secret
      DB_SCHEMA: public
      ADMIN_LOGIN: admin
      ADMIN_PASSWORD: change-me
      WINTER_KEY: "<64 hex chars>"   # set fixed values so they survive recreate
      TOKEN: "<64 hex chars>"
    volumes:
      - s4w_storage:/var/www/html/storage
    restart: unless-stopped

volumes:
  s4w_storage:
```

**Create the schema once** on a fresh external database:

```bash
docker compose exec s4w sh -c "cd /var/www/html && ./call db migrate"
```

(For the bundled DB this runs automatically on first boot.)

---

## Environment variables

| Variable | Default | Notes |
|---|---|---|
| `ADMIN_LOGIN` | — | **Required.** If empty, panel login is denied (fail-closed). |
| `ADMIN_PASSWORD` | — | **Required.** As above. |
| `DB_HOST` | _(bundled PG)_ | If unset → built-in PostgreSQL. If set → external DB. |
| `DB_PORT` | `5432` | |
| `DB_NAME` | `s4w` | |
| `DB_USER` | `s4w` | |
| `DB_PASS` | `s4w` | Change for external DB. |
| `DB_SCHEMA` | `public` | |
| `WINTER_KEY` | _auto_ | JWT signing key. Auto-generated if unset (persisted to `storage`). |
| `TOKEN` | _auto_ | Static bearer for the login endpoint. Auto-generated if unset. |
| `UPLOAD_MAX_FILESIZE` | `100M` | Max upload size; drives php + nginx limits. |
| `PHP_MEMORY_LIMIT` | `256M` | Optional. |
| `PHP_MAX_EXECUTION_TIME` | `300` | Optional (seconds). |
| `PHP_MAX_INPUT_TIME` | `300` | Optional (seconds). |
| `TIME_ZONE` | `UTC` | |
| `DEBUG` | `false` | Keep `false` in production (debug responses leak internals). |
| `LOG_LEVEL` | `info` | `debug\|info\|warning\|error\|…`; empty disables logging. |
| `LOG_OUTPUT` | `auto` | `auto\|stdout\|stderr\|syslog\|file`. |

---

## Build the image yourself

```yaml
services:
  s4w:
    build:
      context: .
      args:
        DISABLE_OPCACHE: "false"   # "true" only for local dev
    ports:
      - "8080:80"
    environment:
      ADMIN_LOGIN: admin
      ADMIN_PASSWORD: change-me
```

```bash
docker compose up -d --build
```

---

## Common operations

```bash
# logs
docker compose logs -f s4w

# run schema migration manually (external DB)
docker compose exec s4w sh -c "cd /var/www/html && ./call db migrate"

# open a shell
docker compose exec s4w sh
```

## Notes & gotchas

- **Panel access requires `ADMIN_LOGIN` + `ADMIN_PASSWORD`** — without them login is
  refused by design (fail-closed). The static login `TOKEN` is generated/served
  automatically; you don't set it to log in via the web panel.
- **Bundled DB is ephemeral** unless you mount `/var/lib/postgresql/data`. Without the
  volume, a recreate re-initializes the DB (data lost) and re-runs the migration.
- **Generated secrets** persist only if `…/storage` is mounted; otherwise they are
  regenerated on each start (invalidating previously issued JWTs/tokens). For external
  DB setups, pin `WINTER_KEY`/`TOKEN` explicitly.
- **Login brute-force** is throttled (5 attempts / 15 min → HTTP 429).
- **Public file URLs** (`/o/{instanceId}/...`) require no auth — only files in `root`
  and in sections marked *public* are served there.
