# `Seablast\Auth`

A no-password authentication and authorization extension for [Seablast for PHP](https://github.com/WorkOfStan/seablast) apps.
This extension facilitates secure user verification and efficient access control.

Optionally, `Seablast\Auth` has a ligthweight integration with Google and Facebook to support social authentication, allowing seamless sign-in through various social media platforms.
Integrable via Composer, it activates only when required, equipping your app with essential security features effortlessly.
If your Seablast-based application necessitates user authentication or resource authorization, incorporating `Seablast\Auth` will equip it with these capabilities instantly.
(For applications that do not require these features, `Seablast\Auth` can simple be not included to maintain a lighter application footprint.)

## User management

- RBAC (Role-Based Access Control) supported
- user MUST have one role (admin, editor, ordinary user)
- user MAY belong to various groups (based on subscription tariff, a promotion, etc.)

## Usage

When just getting the identity of a logged-in user is needed:

```php
    // Instantiate the IdentityManager class with `\mysqli`
    $identity = new IdentityManager($this->configuration->dbms());
    // If prefix is used, inject it
    $identity->setTablePrefix($this->configuration->dbmsTablePrefix());
```

To create the expected database table structure, just add the seablast/auth migration path to the phinx.php configuration, e.g.

```php
    'paths' => [
        'migrations' => [
            '%%PHINX_CONFIG_DIR%%/db/migrations',
            '%%PHINX_CONFIG_DIR%%/../vendor/seablast/auth/conf/db/migrations',
        ],
        'seeds' => '%%PHINX_CONFIG_DIR%%/db/seeds'
    ],
```

### Cookies

IdentityManager expects cookie scope be set already by:

```php
session_set_cookie_params(
    int $lifetime_or_options,
    ?string $path = null,
    ?string $domain = null,
    ?bool $secure = null,
    ?bool $httponly = null
): bool
```

Note: sbRememberMe cookie created/read only if the web is accessed over HTTPS and if allowed by `AuthApp:FLAG_REMEMBER_ME_COOKIE` (allowed by default).

### Routing

`/user` is the default route (which can be changed by `AuthConstant::USER_ROUTE`) to the user log-in/log-out page,
but if you want to customize it, configure path to your own template within your app's `conf/app.conf.php` like this:

```php
        //->setString(AuthConstant::USER_ROUTE, '/user') // can be changed
        ->setArrayArrayString(
            SeablastConstant::APP_MAPPING,
            '/user',
            [
                'template' => 'user', // your latte template including login-form.latte
                'model' => '\Seablast\Auth\UserModel',
            ]
        )
```

Note: already Seablast::v0.2.5 is using the default settings in the [conf/app.conf.php](conf/app.conf.php), so Seablast/Auth configuration is used with v0.2.5 forward.

`send-auth-token.js` (since Seablast::v0.2.10) expects the route `/api/social-login` as configured in [app.conf.php](conf/app.conf.php) and provider either `facebook` or `google`.

### View

`\Seablast\Auth\UserModel` returns arguments ($configuration, $csrfToken, $message, $showLogin, $showLogout) for the user.latte template:

```latte
{include '../vendor/seablast/auth/views/user-control.latte'}
```

Note 1: user.latte uses inherite.latte for all the latte parts, so either you may use it or include user-control.latte or create app version of any of the latte parts.

Note 2: vendor/seablast is accessible for Seablast apps, so the web browser assets (such as `send-auth-token.js`) used by plugins MUST be put into assets folder of the Seablast library.

### Social login

Existence of configuration strings 'FACEBOOK_APP_ID' or 'GOOGLE_CLIENT_ID' imply option to login by these platforms respectively.

Note 1: social login can be deactivated in an app by `->deactivate(AuthConstant::FLAG_USE_SOCIAL_LOGIN)` in the configuration.

Note 2: send-auth-token.js is expected in seablast directory, which needs at least Seablast v0.2.10.

Note 3: The new Google Identity Services no longer opens a traditional pop‑up account chooser; instead, it displays the One Tap UI.

## Testing

Run [./test.sh](./test.sh) for essential PHPUnit tests:

- create token and use it,
- check its disapperance as it's valid only once,
- invalid emails is not accepted,
- SQL injection attempts is not accepted.
