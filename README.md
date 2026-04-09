# DB Backup Pro

Production-ready WordPress 6.x plugin for multi-database backups with Google Drive upload, retention rotation, and automated scheduling.

## Features
- Database engines:
  - MySQL / MariaDB via `mysqldump`
  - PostgreSQL / NeonDB via `pg_dump` (supports connection string + SSL toggle)
  - SQLite via file copy
  - MongoDB via `mongodump` (optional toggle)
  - Microsoft SQL Server via `sqlcmd` (optional toggle)
- Gzip-compressed backup files:
  - `*.sql.gz` for SQL-like dumps
  - `*.archive.gz` for MongoDB/MSSQL archive-style outputs
- File naming:
  - `{db_name}_{type}_{YYYY-MM-DD_HH-MM}.sql.gz`
- Temporary location:
  - `/wp-content/uploads/db-backup-pro/temp/` (auto-cleanup after upload)
- Google Drive OAuth2 (API v3):
  - Connect/disconnect account in admin UI
  - Access + refresh token handling with refresh-before-expiry
  - Configurable root Drive path (e.g. `/DB-Backups`)
  - Auto-creates `/Daily`, `/Monthly`, `/Yearly` subfolders
- Scheduling and retention:
  - Daily backups (default 02:00), keep last 8
  - Monthly backups (1st day), keep last 12
  - Yearly backups (Jan 1), keep last 5
  - Drive retention cleanup by Google Drive file ID
- Logging and retry:
  - `wp_dbbackup_log` table
  - Upload retries up to 3 attempts
  - Failure notifications by email + optional Slack webhook

## Installation
1. Copy `db-backup-pro` to `wp-content/plugins/`.
2. Activate **DB Backup Pro** in WordPress admin.
3. Open **DB Backup Pro > Settings**.
4. Add one or more database connections.
5. Configure Google credentials and connect your Google account.
6. Configure schedule/retention and notifications.

## Google OAuth Setup (Drive API v3)
1. Go to Google Cloud Console.
2. Create/select a project.
3. Enable **Google Drive API**.
4. Create OAuth 2.0 credentials (**Web application**).
5. Add Authorized redirect URI:
   - `https://your-site.example/wp-admin/admin.php?page=dbbp-settings&tab=google_drive`
6. Copy Client ID and Client Secret into plugin settings.
7. Click **Connect Google Account**.

## SQL Schema (Created on Activation)
The plugin auto-creates this table with `dbDelta` on activation:

```sql
CREATE TABLE wp_dbbackup_log (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  backup_type VARCHAR(20) NOT NULL,
  db_key VARCHAR(100) NOT NULL,
  db_name VARCHAR(191) NOT NULL,
  db_type VARCHAR(50) NOT NULL,
  filename VARCHAR(255) NOT NULL,
  file_size BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
  drive_file_id VARCHAR(255) DEFAULT NULL,
  drive_folder VARCHAR(255) DEFAULT NULL,
  status VARCHAR(20) NOT NULL,
  message TEXT DEFAULT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY backup_type (backup_type),
  KEY db_key (db_key),
  KEY status (status),
  KEY created_at (created_at)
);
```

Note: In runtime, table name is prefixed by your WordPress `$wpdb->prefix`.

## Server Cron / WP-CLI Mode
If "Use server cron" is enabled, call WP-CLI command instead of WP-Cron.

Example crontab entries:

```bash
0 2 * * * /usr/bin/wp dbbp run --type=daily --path=/var/www/html
10 2 1 * * /usr/bin/wp dbbp run --type=monthly --path=/var/www/html
20 2 1 1 * /usr/bin/wp dbbp run --type=yearly --path=/var/www/html
```

Windows Task Scheduler example:

```powershell
wp dbbp run --type=daily --path="C:\xampp\htdocs\your-site"
```

## Security Notes
- All admin actions require `manage_options`.
- All forms use WordPress nonce checks.
- Inputs are sanitized (`sanitize_text_field`, `sanitize_email`, `esc_url_raw`, `intval`).
- Log queries use prepared statements where dynamic values are passed.
- DB and Google tokens are stored encrypted with OpenSSL (`AES-256-CBC`).

## Operational Notes
- Ensure required binaries are available in system `PATH`.
- For NeonDB/cloud PostgreSQL, use connection string and SSL toggle.
- If upload fails 3 times, backup is marked failed and admin alert is sent.
- Local temp files are removed after upload and periodic cleanup deletes stale temp files.
