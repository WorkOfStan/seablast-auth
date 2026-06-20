# Agent Notes

## Project

Seablast Auth is a PHP library for passwordless email-token login, user sessions, Remember Me cookies, RBAC roles, user groups, and optional Google/Facebook social login in Seablast applications.

Key runtime classes:

- `Seablast\Auth\IdentityManager`: email token login, session tokens, Remember Me cookies, authenticated user identity.
- `Seablast\Auth\GroupManager`: group membership and group activation token handling.
- `Seablast\Auth\UserModel`: bundled `/user` login/logout model.
- `Seablast\Auth\Models\ApiSocialLoginModel`: bundled `/api/social-login` model.
- `Seablast\Auth\MailOut`: Symfony Mailer wrapper using `SeablastConfiguration`.

## Change Rules

- Never remove comments unless the comment is a todo that the change fully solves; stale comments should be rewritten in accurate English.
- Keep `CHANGELOG.md` updated in English for user-visible or security-relevant changes.
- Update this `AGENTS.md` when agent-facing setup, commands, or project conventions change.
- Treat this as auth/security-sensitive code: do not log raw login tokens, OAuth tokens, email tokens, session tokens, or Remember Me tokens.
- Prefer small, compatible changes; the package supports PHP `>=7.2 <8.6`.

## Commands

On Windows, do not run `.sh` helper scripts directly from PowerShell. Use Git Bash explicitly when a shell helper is needed.

PHPUnit:

```powershell
.\vendor\bin\phpunit
```

PHP syntax checks:

```powershell
php -l src\IdentityManager.php
```

PHPStan setup/check:

```powershell
& "C:\Program Files\Git\bin\bash.exe" -lc "./blast.sh phpstan"
```

PHPStan cleanup:

```powershell
& "C:\Program Files\Git\bin\bash.exe" -lc "./blast.sh phpstan-remove"
```

Composer install:

```powershell
$env:COMPOSER_CACHE_DIR = "$PWD\.composer-cache"
php "C:\ProgramData\ComposerSetup\bin\composer.phar" install
```

Do not modify Composer itself and do not run `composer self-update`.

## Local Setup

- DB-backed tests expect a working local MySQL test database configured through `conf/phinx.local.php`.
- `conf/phinx.local.php` is local/private and should not be committed.
- Migrations for consumers live in `conf/db/migrations`.
- Do not inspect, lint, or recurse into `.tmp`, `vendor`, or build artifacts unless a task specifically requires it.

## Security Expectations

- Escape or parameterize user-controlled values before SQL use; prefer prepared statements for larger refactors.
- Use `random_bytes()`-based tokens for login/session/Remember Me tokens.
- Remember Me cookies must be HTTPS-only and HttpOnly.
- `AuthConstant::FLAG_REMEMBER_ME_COOKIE` controls Remember Me creation and reading in bundled models.
- Social-login validation must check provider audience/client ID, issuer, email, and expiration where available.
