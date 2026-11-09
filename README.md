# Worksnow

A responsive field service ticketing application for service businesses. PHP 8.2+, PDO, and SQLite or MySQL. No build step, Composer packages, or frontend dependencies.

## What it does

- Each business gets an isolated workspace, service catalog, public request page, and currency.
- Customers submit a service request and receive an unguessable private tracking link.
- Owners manage customers, create tickets, assign technicians, set priorities and appointments, and post customer-visible updates.
- Technicians sign in to see their assigned work and update ticket progress. Customer lists, quotes, and billing are restricted to owners.
- Quotes include labor and material line items, quantities, unit prices, tax, expiry, and terms. Drafts remain private until issued.
- Customers accept or decline issued quotes on their tracking page. Accepted quotes can be converted into an invoice once.
- Owners issue invoices and record partial or full payments. Balances and payment history stay visible to customers.
- Quotes and invoices have printable layouts; use the browser's Print / Save as PDF action.
- Dashboard, searchable ticket list, schedule, customer list, service catalog, team management, and password settings.

## Run locally

1. Install PHP 8.2 or newer with `PDO` and `pdo_sqlite` enabled.
2. In the project directory, run:

   ```sh
   php -S 127.0.0.1:8080 router.php
   ```

3. Open [Worksnow locally](http://127.0.0.1:8080). Choose **Create a workspace**. There are no preconfigured accounts or sample business records.
4. Add a service and optionally a technician. Open **Your request page** to submit a customer request, or add a customer and create a ticket from the workspace.

The SQLite database and tables are created automatically in `storage/worksnow.sqlite`. Give the web-server user write access to `storage` only. `storage`, `app`, and `tests` are denied direct web access by the development router and the included Apache `.htaccess` files. Do not serve the entire directory with a generic static file server.

### XAMPP

Put the project in `C:\xampp\htdocs\worksnow` and open `http://localhost/worksnow/` with Apache running. Enable PDO SQLite in the PHP configuration used by Apache, then restart Apache. Apache must allow `.htaccess` overrides including authorization rules. If using the development server, always include `router.php` as shown above.

On this workstation, PHP 8.2's configuration points to the older PHP extension directory. This command avoids modifying that shared configuration:

```powershell
& 'C:\xampp\php 8.2\php.exe' -n -d 'extension_dir=C:\xampp\php 8.2\ext' -d extension=pdo_sqlite -S 127.0.0.1:8080 router.php
```

## MySQL hosting

Create an empty MySQL 8+ database. Copy `config.example.php` to `config.local.php` and set:

```php
<?php
return [
    'dsn' => 'mysql:host=127.0.0.1;dbname=worksnow;charset=utf8mb4',
    'db_user' => 'your_database_user',
    'db_password' => 'your_database_password',
    'timezone' => 'Asia/Bangkok',
    'secure_cookies' => true,
];
```

Enable `pdo_mysql`. The application creates the tables. Use HTTPS and a database account limited to this application's database. The MySQL path uses portable SQL and InnoDB, but automated integration tests currently exercise SQLite only. Existing SQLite data is not automatically migrated to MySQL.

## Deploy to Vercel

Worksnow includes a `Dockerfile` and `Caddyfile` for Vercel's Container preset, following [Vercel's PHP container guide](https://vercel.com/kb/guide/deploy-php-on-vercel-with-docker). The image serves only `public/index.php` and public assets. Private configuration, Git metadata, tests, and local data are excluded from the image and deployment upload.

1. Sign in to Vercel and import `khunkanit-star/worksnow` from GitHub.
2. Select **Container** as the framework preset if it is not detected automatically. Keep the repository root as the project root.
3. Create a hosted MySQL 8-compatible database. Local SQLite is intentionally rejected inside the hosted container: container files are temporary and are not shared between instances.
4. In **Settings → Environment Variables**, add the following values to Production. Use a separate database for Preview deployments if enabling previews.

   | Variable | Value |
   | --- | --- |
   | `WORKSNOW_DB_DSN` | `mysql:host=YOUR_HOST;port=3306;dbname=worksnow;charset=utf8mb4` |
   | `WORKSNOW_DB_USER` | Database username |
   | `WORKSNOW_DB_PASSWORD` | Database password; mark sensitive |
   | `WORKSNOW_DB_SSL_CA` | Optional CA certificate path; the container defaults to the system CA bundle |
   | `WORKSNOW_TIMEZONE` | Optional, defaults to `Asia/Bangkok` |

5. Deploy and visit the application URL. The schema is created automatically; register your owner account and add your first service.

The container enables HTTPS-only cookies, a required external database, and database-backed PHP sessions. MySQL sessions use advisory locks to serialize concurrent requests for the same session. Each request opens one application connection and one session connection; configure database connection limits for the expected traffic. The database account needs table-creation permissions for automatic setup and `GET_LOCK` / `RELEASE_LOCK` support. MySQL connections verify TLS certificates. If a provider uses its own private CA, supply that CA through a securely managed file and set `WORKSNOW_DB_SSL_CA`; do not disable verification.

`/health` reports whether PHP is responding, not whether the database is configured. A usable deployment must also load the registration page, persist a new workspace, and keep the login working across requests. A setup-error page means deployment is incomplete.

For a local container check with Docker installed:

```sh
docker build -t worksnow .
docker run --rm -p 8080:80 --env-file /path/to/private-database.env worksnow
```

Do not put credentials in build arguments, `Dockerfile`, Git, or public chat messages. Choose a Vercel plan suitable for your use and review any database subscription before accepting it. No paid service is required or provisioned by the repository itself.

## Typical workflow

1. Customer opens the business request link and submits a job.
2. Owner assigns the ticket and schedules a visit.
3. Owner builds a draft quote, reviews it, and clicks **Issue quote**.
4. Share the ticket's customer tracking link manually. The customer opens the quote and accepts or declines.
5. Owner creates an invoice from an accepted quote and issues it.
6. Technician moves the ticket through **In progress**, **Waiting** as needed, and **Completed**.
7. Owner records payments already received. Each payment is added to a ledger, and the invoice balance updates.

Quotes and payments use integer minor units. Quantities are whole numbers. Tax supports two decimal places and rounds once per document. Currency is selected when registering a business and stays fixed for its documents. Appointment times use the application timezone shown in the UI.

## Security and operational notes

- Passwords are hashed with PHP `password_hash`; session IDs rotate on sign-in, registration, password change and sign-out. Cookies are HttpOnly and SameSite Lax.
- All mutations require CSRF tokens. Database queries use bound parameters, record access is scoped to a business, and technician access is limited to assigned tickets.
- Tracking links are bearer credentials with 256 bits of randomness. Anyone with a link can view that customer's ticket, issued documents, and payment references, and approve an available quote. Share links only with the intended customer. There is no link revocation UI yet.
- Public requests are rate limited to 10 per hour per business and client IP. Sign-in allows 8 failed attempts per 15 minutes per email/client IP. Configure trusted proxy handling deliberately before placing the app behind a proxy.
- No real credentials, database files, customer records, or runtime logs belong in Git. Back up the database separately.
- This first version records external payments; it does not collect cards, charge customers, send email/SMS, or generate jurisdiction-specific tax invoices. Share links manually. Password reset, notification delivery, online payment integration, attachments, recurring jobs, and data export are not included.
- Before an internet launch, configure HTTPS, backups, access logging, retention policies, and the business's payment instructions and invoice requirements. The built-in PHP server is for local development only.

## Validation

Run the dependency-free integration test:

```sh
python tests/smoke.py --php php
```

For the workstation-specific PHP extension path:

```powershell
python tests/smoke.py --php 'C:\xampp\php 8.2\php.exe' --extension-dir 'C:\xampp\php 8.2\ext'
```

Tests create a temporary copy, launch their own PHP server on a free local port, and remove their disposable database afterward. They cover the customer-to-payment workflow, exact totals, draft visibility, duplicate and overpayment rejection, role permissions, cross-business access, CSRF, escaped output, protected paths, password changes, and workspace routes.
