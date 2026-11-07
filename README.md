# SANDUK PHP + MySQL Edition

This is a standalone PHP 8.0+ and MySQL 8 application. **Supabase is not required.** PHP handles the pages, authentication, authorisation and database operations. The application intentionally does not depend on JavaScript for transaction or approval decisions.

## Included modules

- Public responsive landing page
- Single signup and login for all members and administrators (email + password, without email verification in this edition)
- Group creation (creator is Chairperson and Treasurer), invitation code, member join requests
- Chairperson-controlled membership approval and all financial approvals, including self-approvals
- Contributions, flat-interest loans, two-stage loan approval/disbursement, loan repayments, withdrawals and expenses
- Locked financial ledger, group cash balance, member savings statements, CSV reports and audit events
- Super Admin directory, ability to pause/resume groups, support request resolution
- CSRF protection, parameterized PDO SQL, output escaping, server-side role checks, login throttling, secure cookie options, 15-minute inactivity / eight-hour maximum browser session

## Hosting requirements

**GitHub Pages cannot execute PHP.** Deploy to hosting that provides PHP 8.2+, MySQL 8, HTTPS and a domain or valid hosting subdomain. This can be a local XAMPP/WAMP/MAMP installation during development or a PHP-capable web host for production. Do not upload the project wholesale to a public web root. Only `public/` must be accessible to visitors. The `app/`, `scripts/`, and `database/` directories must remain outside the document root.

## Local setup

1. Install PHP 8.0+ with `pdo_mysql` and `mbstring`, plus MySQL 8 (for example through XAMPP).
2. Create a MySQL database and an application-only database user with permissions on that one database. Run `database/schema.sql` using phpMyAdmin or MySQL CLI. The SQL script creates a database named `sanduk` (change if needed).
3. On WampServer, `app/config.php` is already included with local-only defaults. On production hosting, create a separate secure `app/config.php` using `app/config.example.php`. Set the MySQL username, password and a unique random `app_key`. **Do not commit config.php to GitHub.** Generate an app key using `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`.
4. In the project root run `php -S localhost:8000 -t public` for local testing only. Browse `http://localhost:8000`.
5. Register the account you want to use for Super Admin. On the server, run `php scripts/promote_admin.php your-account@example.com` to grant the administrator role to that existing account.
6. Create a savings group. Members register separate accounts and request to join using the group invitation code. The Chairperson approves them.
7. Use separate test accounts to test membership, contributions, loans, withdrawals, exports, and support before deploying publicly.

## Production configuration

- Serve over HTTPS, configure `'secure_cookies' => true` in `app/config.php`, and set `base_url` to your actual HTTPS origin.
- Point the web server document root to `/path/to/sanduk-php/public`; deny direct access to `app/`, `scripts/`, and `database/` and turn off directory listing.
- Set a strict MySQL user password, use least privilege, enable automated backups, and restrict MySQL to private/local connections.
- Configure PHP to disable `display_errors` and enable protected error logs. Verify PHP `session.save_path` is secure.
- Deploy behind a rate-limiting firewall / reverse proxy, add a registration abuse defense (CAPTCHA or email verification), and set up monitoring and independent security testing.
- Periodically purge old `login_attempts`; implement privacy/retention policies, admin MFA, and secure password reset with a verified outgoing mail service before real financial use.
- Email confirmation is **not** required by this PHP edition, but avoiding it means email ownership is unverified. Password reset by email and automatic notifications are not implemented. Password changes **while signed in** are supported.

## Known constraints / important distinctions

- PHP does **not** automatically solve email delivery. Email confirmation, lost-password recovery and outbound notifications still require a properly configured mail service. This implementation intentionally skips email confirmation to allow local testing; do not assume this is safe for unrestricted public signup.
- The application manages **records only**. It does not hold funds, verify external transfers, send SMS, or reconcile a bank or mobile money account.
- The Chairperson may approve their own financial activity as requested. Every such approval is recorded with `self_approved=1`, but it removes independent oversight and raises fraud risk. Members should be allowed to review reports, and high-value transactions ideally need a second reviewer.
- Super Admin can pause/resume groups and resolve support requests, but does not have authority to approve a group's financial transactions.
- The ledger is append-only using SQL triggers. This prevents ordinary changes to recorded entries, but a database owner/server administrator can still alter data. Use least-privileged DB users, off-site backups, and independent reconciliations.
- Lists show the most recent 150 entries. CSV exports can contain all group entries. For very large datasets, implement streaming pagination and background report jobs.
- This code has been syntax-checked but has **not** been end-to-end tested against a running MySQL server in this environment. Test on a staging database first. Do not migrate actual money records before reconciling totals.

## Moving from Supabase to MySQL

**Existing Supabase accounts and data do not automatically migrate.** Export the PostgreSQL users, groups, memberships, contributions, loans, repayments, withdrawals and ledger records for an audited migration. The source and destination data models differ, so a direct SQL dump import will not work. Preserve legacy IDs in a separate mapping spreadsheet/table, verify every running balance, and import into MySQL only after careful reconciliation. Supabase authentication password hashes are not available through normal CSV exports. Unless you implement a verified identity migration, users will need to create new PHP accounts and have their memberships reassigned safely. Do **not** ask users to share passwords or copy secret Supabase keys into this project.

## Project structure

- `public/index.php` front controller, responsive pages, and form navigation
- `public/export.php` authenticated CSV export
- `public/assets/` local CSS and JavaScript
- `app/bootstrap.php` secure session/config/DB helpers
- `app/actions.php` authenticated group and payment workflows
- `database/schema.sql` MySQL 8 schema and financial ledger guards
- `scripts/promote_admin.php` **CLI-only** initial Super Admin promotion

## Minimum QA before deployment

1. Confirm anonymous visitors cannot access reports or any group data.
2. Confirm Member A cannot access Group B by changing `?group=` or POST `group_id`.
3. Confirm members cannot submit POST approvals or change roles.
4. Confirm Chairperson can approve their own contributions and the audit flags these.
5. Confirm approving the same request twice cannot create a second ledger entry.
6. Confirm insufficient group funds prevent expense, withdrawal, and loan disbursement.
7. Confirm repayment over the due amount is rejected, and member withdrawals cannot exceed their own verified savings.
8. Confirm login throttling, CSRF, session expiration, cookie flags and HTTPS on the deployed server.
9. Confirm admin access cannot be obtained by changing browser state or posting admin actions.
10. Restore a test backup and reconcile member balances before inviting actual groups.
