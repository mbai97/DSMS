# Beckyn Certificate Verification System

A lightweight PHP/MySQL certificate issuing and QR verification system designed for Beckyn Driving & Mechanical School and shared hosting such as Truehost/cPanel.

## What it does

- Admin login
- Role-based staff access: Admin, Manager, Director, and Branch
- Issue certificates
- Generate a unique certificate number
- Generate a cryptographically random verification token
- Generate a QR code containing the verification URL
- Generate a printable PDF certificate
- Public QR verification page
- Manual certificate-number verification
- VALID / EXPIRED / REVOKED / NOT FOUND status
- Verification logging
- Revoke certificates
- Enroll students into Driving School, Computer Packages, Defensive Driving, or Mechanics programs
- Branch-specific admission numbers for Machakos, Tala, Kimutwa, and Kitengela
- Configurable course durations and fees, student balances, and payment ledger
- Vehicle fleet tracking and student assignments by branch
- Director dashboard for branch enrollment, fee, fleet, and certificate summaries
- Director-signed certificates awarded after the configured course duration
- Printable student admission letters and payment receipts
- Public school landing page with program, branch, and certificate verification links
- Bootstrap 5 responsive interface
- GitHub Actions deployment to shared hosting over FTP/FTPS
- No Node.js, Docker, VPS, FastAPI, or Next.js required

## Important architecture

Administrators assign roles and (for Branch users) a branch under User Management. Admins have full access. Managers and Directors have read-only access across branches, except they can issue certificates. Branch users can manage student records only for their assigned branch, can view/print certificates for that branch, and have read-only access to fleet data.

The QR code contains only a URL such as:

`https://verify.beckynds.splentainsights.com/c/ABC123...`

The certificate data is stored in MySQL. This means Beckyn can revoke or expire a certificate without reprinting it.

## Requirements

Local development:

- PHP 8.1+
- MySQL/MariaDB
- Composer 2
- PHP extensions: PDO/MySQL, mbstring, DOM, GD
- Apache with mod_rewrite (XAMPP, WAMP, Laragon are suitable)

Production:

- PHP 8.1+ on the shared host
- MySQL/MariaDB
- Apache/.htaccess support
- SSL/HTTPS
- FTP or FTPS access for GitHub Actions

Dompdf requires PHP plus DOM and mbstring; GD is used for image handling. See the official Dompdf requirements. Endroid QR Code v5 is used here because it supports the PHP 8.1-era shared-hosting environment; verify the PHP version available in the hosting control panel before deployment.

## 1. Local installation

### Windows option: XAMPP

1. Install XAMPP with PHP 8.1+.
2. Start Apache and MySQL.
3. Install Composer.
4. Put this project in:

`C:\xampp\htdocs\certificate-verification`

5. Open a terminal:

```bash
cd C:\xampp\htdocs\certificate-verification
composer install
```

6. Open `http://localhost/certificate-verification/install.php`.
7. Enter the application URL, MySQL connection details, and the first administrator's name, email, and password. The installer creates the database, tables, configuration, and first admin.
8. Sign in at `http://localhost/certificate-verification/admin/login.php`.
9. Delete `install.php` from the web server after setup.

If `config/config.php` already exists, the installer uses its database settings and displays only the first-administrator form. It creates the tables if needed, and it will not run once an administrator exists.

### Alternative: PHP built-in server

This works for most pages but clean URLs and PDF/asset behavior are best tested through Apache.

```bash
php -S localhost:8000 -t . public/router.php
```

If you use the built-in server, set:

```php
'app_url' => 'http://localhost:8000',
```

The project includes a simple router file for the PHP development server.

## 2. First login

After installation:

`http://localhost/certificate-verification/admin/login.php`

Use the administrator credentials you created during installation.

Do not use a shared/default password in production.

## 3. Create your first certificate

From the dashboard:

1. Click `Issue Certificate`.
2. Enter student name.
3. Select certificate type.
4. Enter course/training.
5. Enter issue date.
6. Enter expiry date, or leave it blank if the certificate does not expire.
7. Generate.
8. Download the PDF.

The generated certificate is a single A4 landscape page using a restrained navy, burgundy, and gold palette, clear issuer/recipient/course hierarchy, and labeled certificate metadata. It includes a high-contrast QR code sized for print scanning and a blank signature line for the School Director (plus an optional Head of Department line). The QR opens the public verification page, which reports whether the certificate is valid, expired, revoked, or not found. No accreditation or regulatory approval is implied by the visual design.

Use the browser's actual application URL in `config/config.php`. Keep the localhost URL for local development; on the production server set `app_url` to the final public HTTPS URL (for example, `https://verify.beckynds.splentainsights.com`) only after the app is deployed there and its production database contains the certificate records. The QR code embeds this configured URL when each PDF is generated. Scanning a locally generated QR from another device will not work unless the local server is publicly reachable.

The issue form also supports optional learner ID, course topics (one per line), training duration, completion date, Director, and Head of Department names. These details are printed on the certificate and shown on its admin and public verification records when provided. Existing databases are upgraded automatically the next time the application is opened; existing certificate records are preserved.

## Student programs, branches, fees, and fleet

On first sign-in, open **Courses** and set a duration (in days) for each program; add a default fee if desired. Enrollment then requires a program and branch and generates an admission number in the form `BKDS-{BRANCH}-{PROGRAM}-{YEAR}-{SEQUENCE}`. A student may use the course's default fee or an enrollment-specific amount. Existing imported student rows are preserved, but remain unassigned to a branch until they are re-enrolled with branch details.

Use **Fees** to adjust a student's total fee and record cash, M-Pesa, Airtel Money, bank, or other payments with a reference. Payment balances are derived from the payment ledger. Automated M-Pesa/Airtel STK push is intentionally unavailable until provider credentials and callback URLs are configured; recording a mobile-money payment here does not send a prompt to a phone.

When a student has a Kenyan mobile number on file, **Fees** can open a prefilled WhatsApp payment reminder. An admin must review and send the message. Each recorded payment can be printed as a receipt, and each student has a printable admission letter from **Students**.

Use **Fleet** to register vehicles at a branch, assign available vehicles to active students in the same branch, and return or update vehicles. The Director Dashboard summarizes student counts, fees, certificates, and fleet availability by branch.

Mark a student complete only after their configured course duration has elapsed. Before awarding student certificates, set the Director's name and upload the Director's signature under **Branding**. Certificates generated for completed students retain the admission number, course, duration, and completion date and are linked back to the student.

## 4. Test verification locally

Scan the QR with a phone only if your computer is reachable from the phone on the same network. Otherwise copy the verification URL from the certificate and open it on the local browser.

For a real phone test, use your computer's LAN IP and ensure the Apache firewall allows access.

## 5. Truehost shared hosting: step-by-step installation

These instructions are for Truehost shared hosting with cPanel. Menu names can vary slightly by account. For VPS hosting, use the VPS-specific server setup process instead.

The application should be served from its project root because the root `.htaccess` routes clean URLs and protects private folders. Do not point the subdomain document root at the project's `public/` subfolder unless you separately configure and test the web-server routes.

### Before you start

You need:

- A Truehost shared-hosting account with cPanel access
- A domain or subdomain pointed to that account
- PHP 8.1 or later, MySQL/MariaDB, and the `pdo_mysql`, `mbstring`, `dom`, and `gd` PHP extensions
- The project files and a local Composer installation

Choose the final public URL before issuing certificates. For this setup, the recommended URL is:

`https://verify.beckynds.splentainsights.com`

If you instead deploy under the existing site, use its actual subfolder URL, such as `https://beckynds.splentainsights.com/certificates`. Do not use `localhost` in the production URL.

### Step 1: Point the domain or subdomain to the hosting account

1. Sign in to Truehost and open the hosting service's cPanel.
2. If using a subdomain, open **Domains** or **Subdomains** and create `verify.beckynds.splentainsights.com` (follow the labels available in your cPanel).
3. Set its document root to a dedicated project directory, for example `public_html/certificates`. Use the project root directory itself, not its `public/` child directory.
4. If DNS is managed elsewhere, create the DNS record Truehost specifies for this hosting account. Do not guess the record target; copy it from the Truehost hosting details or ask Truehost support.
5. Wait until the domain resolves to the hosting account.

If you use a subfolder instead of a subdomain, use the existing site's `public_html` directory and create a `certificates` folder there. The matching application URL must then include `/certificates`.

### Step 2: Select and verify the PHP version

1. In cPanel, open **MultiPHP Manager**, **Select PHP Version**, or the PHP settings tool available in your account.
2. Select PHP 8.1 or later for the domain/document root.
3. Enable or ask Truehost support to enable `pdo_mysql`, `mbstring`, `dom`, and `gd`.
4. Save the settings.

The hosting control panel may label `dom` as `DOM` or list it as part of the PHP extensions. If an extension is unavailable, contact Truehost support before continuing.

### Step 3: Install Composer dependencies before uploading

On your development computer, open a terminal in the project directory and run:

```bash
composer install --no-dev --optimize-autoloader
```

Confirm that `vendor/autoload.php` now exists. The `vendor/` directory is required by the application; uploading the source without it will cause a missing-autoloader error. If Composer reports missing PHP extensions, run Composer using PHP with the required extensions enabled, or ask Truehost whether Composer and those extensions are available in its cPanel Terminal.

### Step 4: Upload the project to Truehost

Using cPanel File Manager:

1. Open **File Manager** and navigate to the document root selected in Step 1.
2. Upload a ZIP of the project, including the generated `vendor/` directory. Do not include your local `config/config.php`, local logs, or local generated certificate files.
3. Select the uploaded ZIP and choose **Extract**.
4. Confirm the files are directly in the document root. The directory should contain `install.php`, `.htaccess`, `admin/`, `assets/`, `config/`, `database/`, `includes/`, `public/`, and `vendor/`. Avoid an accidental extra nested project folder.
5. If the File Manager does not show hidden files, enable **Show Hidden Files (dotfiles)** and confirm `.htaccess` was uploaded.

Alternatively, upload the same complete project over FTP/FTPS with a client such as FileZilla. Prefer FTPS when supported by your hosting account.

### Step 5: Create a MySQL database and database user

1. In cPanel, open **MySQL Databases** or **Manage My Databases**.
2. Create a database, for example `beckyn_certificates`.
3. Create a database user and set a strong, unique password.
4. Use **Add User to Database** to assign the user to the new database.
5. Grant **ALL PRIVILEGES** for this database user on this database, then save the change.
6. Record the exact database name and username shown in cPanel. Shared-hosting accounts commonly add an account prefix, such as `account_beckyn_certificates`; use the complete prefixed names.
7. Use `localhost` as the database host only if cPanel or Truehost specifies it. Otherwise use the exact database host shown in the hosting account.

Never put these database credentials in a public message or commit them to Git.

### Step 6: Create the production configuration

In File Manager, make a copy of `config/config.example.php` named `config/config.php`. Edit the new file and set the live URL and database credentials. For a subdomain installation, it should resemble:

```php
<?php

return [
    'app_url' => 'https://verify.beckynds.splentainsights.com',
    'app_name' => 'Beckyn Certificate Verification',
    'issuer_name' => 'Beckyn Driving & Mechanical School',
    'certificate_prefix' => 'BKDS',
    'timezone' => 'Africa/Nairobi',
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'CPANEL_ACCOUNT_beckyn_certificates',
        'user' => 'CPANEL_ACCOUNT_databaseuser',
        'pass' => 'YOUR_DATABASE_PASSWORD',
    ],
];
```

Replace the example URL, database name, username, and password with the real values from this hosting account. For a subfolder installation, the `app_url` must include the folder, for example `https://beckynds.splentainsights.com/certificates`. Keep the quotes and commas in the PHP array. Do not upload or commit the real production config to Git.

### Step 7: Enable HTTPS

1. In cPanel, open **SSL/TLS Status**, **Let's Encrypt SSL**, or the SSL tool provided by Truehost.
2. Issue or enable a certificate for the chosen domain/subdomain.
3. Wait until the HTTPS URL loads without a certificate warning before using it in the installer or issuing certificates.

If the SSL tool is not available or issuance fails, contact Truehost support. Do not issue production certificates with an `http://` or localhost QR URL.

### Step 8: Run the installer and create the first administrator

1. Open the production installation URL in a browser, for example `https://verify.beckynds.splentainsights.com/install.php`.
2. The installer should read `config/config.php` and show the first-administrator form.
3. Enter the administrator's name, email, and a unique password of at least 10 characters.
4. Submit the form. The installer creates the application tables and the first administrator in the selected database.
5. If it reports a database connection error, recheck the complete prefixed database/user names, password, database host, and database privileges.
6. If it reports that the first administrator is already set up, use the existing login rather than rerunning setup.

### Step 9: Remove the installer and sign in

1. In File Manager, delete `install.php` from the live project root immediately after successful setup.
2. Open `https://your-final-host/admin/login.php` (include the subfolder in the URL if applicable) and sign in.
3. Confirm the admin dashboard loads and change the password if it was temporary.
4. Verify the public landing page and a test certificate URL load over HTTPS.

Do not leave `install.php` on the production server after installation.

### Step 10: Set writable storage and test a certificate

1. Confirm `storage/`, `storage/certificates/`, `storage/qrcodes/`, and `storage/logs/` exist.
2. Use File Manager permissions to make storage writable by PHP while keeping ordinary application files non-writable. Start with directory permission `755`; use `775` only if the hosting account requires it. Avoid `777`. Ask Truehost for the correct ownership/permission setting if PHP cannot write files.
3. Sign in and issue a test certificate with a test name.
4. Download the PDF and confirm it is one A4 landscape page with the QR code and School Director signature line.
5. Scan the QR code using a phone on mobile data (or another network) and verify the public page reports the certificate correctly.
6. Revoke the test certificate and scan again to confirm the public page reports it as revoked. Remove the test record if appropriate.

The QR encodes the production `app_url` and looks up the token in the database configured on this server. Therefore, a QR generated locally or from a different database may not verify against production.

### Step 11: Back up and secure the live installation

- Keep `config/config.php` private and out of Git.
- Confirm `install.php` has been deleted.
- Keep HTTPS enabled and use a unique administrator password.
- Confirm the root `.htaccess` is present and sensitive folders are not publicly accessible.
- Back up the production database and `storage/certificates/` and `storage/qrcodes/`.
- Recheck the admin login and public QR verification after deployment.

Truehost's general PHP shared-hosting guidance is available at [How to host a PHP website](https://truehost.co.ke/how-to-host-a-php-website/). Exact cPanel menu names, PHP extensions, DNS targets, and database host values depend on the hosting plan; use the values shown in your account or contact Truehost support.

## 6. GitHub Actions deployment

The included workflow is:

`.github/workflows/deploy.yml`

It:

1. Checks out the repository.
3. Checks that `FTP_SERVER_DIR` exists for the configured FTP account and creates the remote `admin` folder if it is missing.
4. Uploads the application through FTP/FTPS.
5. Preserves `config/config.php`.
6. Does not upload `.git`, local config, local logs, or local generated files.

The GitHub FTP Deploy Action supports FTP/FTPS and accepts server, username, password, port, protocol, local-dir and server-dir settings.

### GitHub repository secrets

In GitHub:

`Settings -> Secrets and variables -> Actions`

Create:

```text
FTP_SERVER
FTP_USERNAME
FTP_PASSWORD
FTP_PORT
FTP_PROTOCOL
FTP_SERVER_DIR
```

Typical values might be:

```text
FTP_SERVER=ftp.your-hostname.com
FTP_USERNAME=your_cpanel_ftp_user
FTP_PASSWORD=your_ftp_password
FTP_PORT=21
FTP_PROTOCOL=ftp
FTP_SERVER_DIR=/public_html/certificates/
```

Use the exact FTP hostname and path provided by your hosting account. Do not guess them.
The deployment workflow checks this path before upload. If it reports that `FTP_SERVER_DIR` is unreachable, correct the secret to the existing project directory visible to that FTP account. If it reports that `admin` cannot be created, create the `admin` folder in the hosting file manager or grant the FTP account permission to create directories there.

If your host supports FTPS, prefer:

```text
FTP_PROTOCOL=ftps
```

instead of plain FTP.

### First deployment

For the first deployment, you may want to disable automatic deletion of remote files. The workflow does not use dangerous-clean-slate.

Before opening `install.php`, create the production database and user in cPanel, upload `config/config.php` with the production URL and credentials, and enable HTTPS. Then follow Steps 8–9 above to create the first administrator and remove `install.php`.

## 7. Git workflow

```bash
git init
git add .
git commit -m "Initial Beckyn certificate verification system"
git branch -M main
git remote add origin https://github.com/YOUR-ACCOUNT/beckyn-certificate-verification.git
git push -u origin main
```

Every push to `main` triggers deployment.

## 8. Deployment layout

If installed as a subfolder:

```text
public_html/
└── certificates/
    ├── admin/
    ├── assets/
    ├── config/
    ├── database/
    ├── includes/
    ├── public/
    ├── storage/
    ├── vendor/
    ├── .htaccess
    └── install.php
```

For a subdomain, point the document root to the project root (the directory containing `install.php` and `.htaccess`), for example:

```text
verify.beckynds.splentainsights.com
        |
        +--> public_html/certificates/
              ├── install.php
              ├── .htaccess
              ├── admin/
              ├── config/
              ├── includes/
              ├── public/
              └── vendor/
```

The root `.htaccess` routes requests into `public/` where needed and blocks direct web access to sensitive application folders.

## 9. Security checklist

Before going live:

- Enable HTTPS.
- Delete/rename `install.php`.
- Change the admin password.
- Never commit `config/config.php`.
- Never commit production database credentials.
- Keep `vendor/` dependencies locked with `composer.lock`.
- Make `storage/` writable.
- Confirm sensitive folders cannot be browsed.
- Use FTPS rather than plain FTP when your host supports it.
- Back up the MySQL database.
- Test VALID, EXPIRED, REVOKED and NOT FOUND states.

## 10. Certificate number format

Default format:

`BKDS-YYYY-000001`

Example:

`BKDS-2026-000001`

The database also stores a separate random verification token. The token is what the QR uses.

## 11. Certificate status

Status is calculated as follows:

- `valid`: record is active and not past expiry
- `expired`: expiry date has passed
- `revoked`: administrator revoked it
- `not_found`: no matching token/certificate exists

## 12. Production QR URL

If the final domain is:

`https://verify.beckynds.splentainsights.com`

the QR becomes:

`https://verify.beckynds.splentainsights.com/c/RANDOM-TOKEN`

If you later move the verifier to:

`https://verify.beckynds.co.ke`

only newly generated certificates need the new base URL. Existing certificates will continue to work only if the old verification domain remains active or redirects to the new one. Plan the final verification domain before mass printing certificates.

## 13. Troubleshooting

### Composer error

Check PHP:

```bash
php -v
```

Check extensions:

```bash
php -m
```

You should see at least:

- dom
- mbstring
- pdo_mysql
- gd

### Database error

Confirm the cPanel database name often includes a hosting account prefix, e.g.:

`account_beckyn_certificates`

The same applies to the database user.

### QR missing

Check:

- `vendor/autoload.php` exists
- GD is enabled
- `storage/qrcodes/` is writable
- Generate/download the certificate PDF again after fixing any issue; PDFs already downloaded are not updated automatically
- Confirm the downloaded PDF is the newly generated one and inspect it at normal zoom; the QR is at the lower right

### PDF fails

Check:

- Dompdf installed
- DOM and mbstring enabled
- `storage/certificates/` writable
- the QR file exists
- PHP GD enabled and `storage/qrcodes/` writable (the generator writes the QR PNG before rendering the PDF)
- The Truehost/cPanel PHP error log. The current generator logs a stage such as `QR image setup`, `QR image rendering`, `PDF rendering`, `PDF file save`, or `database path update`, with the exception message

To find the log in cPanel, open **Metrics → Errors** or **File Manager** and inspect the account's PHP/Apache error log (the exact location varies by plan). Reproduce the failure while signed in as an administrator, then check the newest error entry. Do not enable `display_errors` on the public production site; share only the stage and error message, not config files, credentials, or session cookies.

If `generate.php?id=...` sends you to the admin login, the route is reachable but PDF generation has not run: sign in first, then open the Generate/Download PDF action from the admin certificate page. An external request without the admin session cannot reproduce the authenticated PDF request.

### Clean URL fails

Make sure Apache `mod_rewrite` and `.htaccess` are enabled.

## 14. Backup

At minimum back up:

- MySQL database
- `storage/certificates/`
- `storage/qrcodes/`
- Beckyn logo/assets
- `config/config.php`

## 15. License

This is a custom internal system for Beckyn Driving & Mechanical School. Third-party dependencies retain their own licenses.
