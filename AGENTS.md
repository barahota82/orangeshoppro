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
- Admin login: `admin` / `admin123` (seed manually on fresh DB — see bootstrap below)
- `.env.php` is gitignored — create from `.env.example.php`

### First-time VM bootstrap (once per fresh environment)

Ubuntu packages: `php8.3-cli`, `php8.3-mysql`, `php8.3-mbstring`, `php8.3-curl`, `mariadb-server` (PHP 8.2+ required; 8.3 on Ubuntu 24.04 is fine).

```bash
# MariaDB (systemd may be blocked in cloud VMs — use mysqld_safe)
sudo mysqld_safe &
sleep 3
sudo chmod 755 /run/mysqld

# DB + user (as root)
sudo mysql -e "CREATE DATABASE IF NOT EXISTS orange_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE USER IF NOT EXISTS 'orange_dev'@'localhost' IDENTIFIED BY 'orange_pass';
  GRANT ALL PRIVILEGES ON orange_db.* TO 'orange_dev'@'localhost';
  FLUSH PRIVILEGES;"

# Schema (once)
mysql -u orange_dev -porange_pass orange_db < scripts/mysql-create-orange-database-full.sql

# .env.php — minimum keys for local dev (do not commit)
# DB_USER=orange_dev, DB_PASS=orange_pass,
# STOREFRONT_FORCE_LONG_URLS=true, HEALTH_CHECK_KEY=dev_health_check_key_2026,
# ORANGE_STOREFRONT_GEO_OVERRIDE=kw

# Seed dev admin (fresh DB has no admins)
HASH=$(php -r "echo password_hash('admin123', PASSWORD_DEFAULT);")
mysql -u orange_dev -porange_pass orange_db -e "INSERT INTO admins (username, password_hash, display_name, is_active, is_superuser) VALUES ('admin', '$HASH', 'Dev Admin', 1, 1);"
```

Validate with `curl "http://localhost:8080/health.php?key=dev_health_check_key_2026"` — expect `PHP OK`, `DB OK`, `SESSION OK`.

### Key gotchas

1. **MariaDB socket permissions**: After starting `mysqld_safe`, the `/run/mysqld/` directory may have `700` permissions. Run `sudo chmod 755 /run/mysqld` to allow PHP's PDO to connect.
2. **`STOREFRONT_FORCE_LONG_URLS`**: Set to `true` in `.env.php` for local dev (no URL rewrite module available with PHP built-in server).
3. **PHP lint**: The CI workflow runs `find . -name "*.php" -print0 | xargs -0 -n1 php -l`. One pre-existing BOM issue exists in `admin/api/purchases/create.php`.
4. **Schema bootstrap**: The full schema DDL is at `scripts/mysql-create-orange-database-full.sql`. Import it once. Runtime auto-migration in `includes/catalog_schema.php` applies on first HTTP request. `php scripts/run_migrations.php` is optional; after a full SQL import it may log duplicate-column errors — use `health.php` as the smoke test instead.
5. **No admin on fresh import**: The full SQL dump does not seed an admin user; run the bootstrap `INSERT` above before testing admin login.
6. **No `.env.php` in repo**: Per owner policy, `.env.php` exists only on server / local dev. Never commit it.

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
