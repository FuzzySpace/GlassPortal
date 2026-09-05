# Runbook — Admin Bootstrap

**Purpose:** create and verify the first (or any additional) staff/admin account on a GlassPortal deployment without touching the database by hand. Applies to fresh installs and to recovery when no admin can log in.

## 1. The command

GlassPortal ships a first-class CLI bootstrap command (`app/Console/Commands/CreateAdminUser.php`):

```
php artisan glassportal:create-admin --name="Full Name" --email="owner@example.com" --role=owner
```

The `--role` option accepts `owner`, `admin`, `staff`, or `support` (default `admin`). Name and email are validated (email must be unique); the password is collected interactively with hidden input, must be at least 12 characters, and must be confirmed. The password is hashed with the application hasher — it is never stored or logged in plain text, and the command prints only the ID, name, email, and role label on success. For the first account on a new deployment use `--role=owner`.

In a containerized deployment, run it inside the app container, e.g. `docker exec -it glassportal-source-app-1 php artisan glassportal:create-admin --role=owner`.

## 2. Verification

After creating the account, run `php artisan glassportal:commercial-readiness` and confirm the **Admin bootstrap** section passes: the command must be registered (`admin.bootstrap_command_registered`), at least one owner/admin must exist (`admin.owner_user_exists`), the `role` middleware class must be present (`admin.role_middleware_registered`), and both the staff and customer route groups must carry their `auth` + `role:` middleware (`admin.staff_routes_protected`, `admin.customer_routes_protected`). Then log in at the canonical portal URL (`http://40.160.61.180:18188/login` — never :18180) and confirm the admin dashboard loads and `/admin/billing` is reachable.

## 3. Failure modes

| Symptom | Cause | Fix |
| :--- | :--- | :--- |
| `Invalid role` error | Typo in `--role` | Use one of owner/admin/staff/support |
| Email already taken | Account exists | Use password reset flow, or create with a different email |
| Password rejected | Under 12 characters or mismatch on confirm | Choose a longer password and retype carefully |
| Command not found | Autoload/registration issue | `composer dump-autoload`; verify with `php artisan list glassportal` |
| Login works but /admin 403s | Account created with role `customer` or `support` where owner/admin is needed | Create an owner account; roles are enforced by the `role` middleware |

## 4. Security notes

Never create admin accounts by inserting rows directly in the database; the command exists so hashing, validation, and role assignment stay correct. Do not share owner credentials; create individual admin accounts per operator. There is no self-service admin registration route — admin creation is CLI-only by design, which is verified by the boundary tests added in Phase 29D.
