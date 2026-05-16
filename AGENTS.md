# AGENTS.md

## Cursor Cloud specific instructions

### Overview

Orange Shop Pro is a plain PHP 8.2 e-commerce application with MariaDB/MySQL. No package manager (no Composer, no npm). No build step. Static CSS/JS served directly.

### Services

| Service | How to start |
|---------|-------------|
| MariaDB | `sudo mysqld_safe &` (wait ~3s, then `sudo chmod 755 /run/mysqld` if socket permission denied) |
| PHP Dev Server | `php -S 0.0.0.0:8080 -t /workspace` |

### Dev credentials (local only)

- DB user: `orange_dev` / password: `orange_pass`
- DB name: `orange_db`
- Admin login: `admin` / `admin123`
- `.env.php` is gitignored — create from `.env.example.php`

### Key gotchas

1. **MariaDB socket permissions**: After starting `mysqld_safe`, the `/run/mysqld/` directory may have `700` permissions. Run `sudo chmod 755 /run/mysqld` to allow PHP's PDO to connect.
2. **`STOREFRONT_FORCE_LONG_URLS`**: Set to `true` in `.env.php` for local dev (no URL rewrite module available with PHP built-in server).
3. **PHP lint**: The CI workflow runs `find . -name "*.php" -print0 | xargs -0 -n1 php -l`. One pre-existing BOM issue exists in `admin/api/purchases/create.php`.
4. **Schema bootstrap**: The full schema DDL is at `scripts/mysql-create-orange-database-full.sql`. Import it once, then `php scripts/run_migrations.php` handles incremental changes. The app also auto-migrates at runtime via `includes/catalog_schema.php`.
5. **No `.env.php` in repo**: Per owner policy, `.env.php` exists only on server / local dev. Never commit it.

### Running lint

```bash
find . -name "*.php" -not -path "./vendor/*" -exec php -l {} \; 2>&1 | grep -v "^No syntax errors"
```

### Running the app

```bash
php -S 0.0.0.0:8080 -t /workspace
```

Then visit:
- Storefront: http://localhost:8080/pages/home.php
- Admin: http://localhost:8080/admin/login.php
- Health check: http://localhost:8080/health.php?key=dev_health_check_key_2026
