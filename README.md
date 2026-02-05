# Apiary Logbook (Apache + PHP + MySQL)

Lightweight SPA + PHP JSON API for managing apiary visits, hives, and queens.

## Files
- `index.html` – UI
- `styles.css` – styling
- `app.js` – SPA logic (hash routing)
- `api.php` – JSON API (PDO, MySQL 8 window functions)
- `Apiary scheme.sql` – database schema (tables + constraints)

## Requirements
- PHP 7.4+
- MySQL 8.0+
- Apache (or any PHP-capable web server)

## Setup
1. Copy the folder to your web root, e.g. `/var/www/html/apiary/`.
2. Ensure PHP + PDO MySQL are enabled.
3. Configure the database connection (see below).
4. Create the Apiary tables using the provided SQL script (see below).
5. Ensure your Apiary schema tables/views exist (`Hives`, `Queens`, `Visits`, and `Visits_active`).
6. Create your first admin user via the login screen bootstrap.

## Database connection
`api.php` reads these environment variables (recommended):
- `APIARY_DB_HOST` (default: `localhost`)
- `APIARY_DB_NAME` (default: `Apiary`)
- `APIARY_DB_USER` (default: `username`)
- `APIARY_DB_PASS` (default: `password  `)

Security note: the MySQL user configured in `.env` should be restricted to connect from `localhost` only.

Alternative: edit the defaults directly in `api.php`.

## .env file
`api.php` loads a `.env` file from the same directory (`/var/www/html/apiary/.env`) if present. Example:

```
APIARY_DB_HOST=localhost
APIARY_DB_NAME=Apiary
APIARY_DB_USER=apiary_user
APIARY_DB_PASS=your_password
```

Security notes:
- Do not expose `.env` via the web server. Block access to dotfiles or move `.env` outside the web root.
- Use restrictive file permissions (e.g., readable only by the web server user).

### Apache env example
```
SetEnv APIARY_DB_HOST localhost
SetEnv APIARY_DB_NAME Apiary
SetEnv APIARY_DB_USER apiary_user
SetEnv APIARY_DB_PASS your_password
```

## Database schema (required)
Load the schema file to create the tables and constraints:

```
mysql -u your_user -p Apiary < "Apiary scheme.sql"
```

After loading the schema, open the login screen. If no admin exists, you’ll see a button to create a default admin account (`admin` / `admin`) after confirmation. Sign in and change the password immediately.

## Auth and roles
- All data endpoints require login.
- Roles:
  - `admin` – read/write + user administration
  - `contributor` – read/write
  - `readonly` – read-only
- UI disables edit/create controls for read-only users.
- `logout` is POST-only.

## User administration (admin only)
- Button: **User Administration** (top-right)
- Route: `#/admin/users`
- Actions:
  - List all users
  - Change role (cannot change your own role)
  - Reset password to `12345678` (disabled for current user)
  - Delete a user (cannot delete the current user)
  - Add a user (username, role, password)

## Change password
- Button: **Change password** (top-right)
- Route: `#/account`

## CSRF protection
- API issues a CSRF token on `me` and `login`.
- All POST requests (except `login`) must include `X-CSRF-Token`.
- The SPA handles this automatically.

## What the UI does
- Landing page: Locations (derived from latest visit per active hive, plus a count of hives with to-do items)
- Location page: Hives at that location (Hive no., last visit, queen info from latest visit)
- Hive page: Visits chronological + “Add visit”
- Visit page: View/update visit form
- New visit: Prefilled from latest visit of that hive
- Queens list: View/edit/create

## Notes
- "Current location" is taken from the latest visit of a hive (Datum desc, ID desc).
- Hives must be `inactive = 0` to show on location lists.
