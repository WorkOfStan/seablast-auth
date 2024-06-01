# `Seablast\Auth`
A no-password authentication and authorization extension for [Seablast for PHP](https://github.com/WorkOfStan/seablast) apps.
This extension facilitates secure user verification and efficient access control.

Optionally, `Seablast\Auth` integrates with the HybridAuth library to support social authentication, allowing seamless sign-in through various social media platforms.
Integrable via Composer, it activates only when required, equipping your app with essential security features effortlessly.
If your Seablast-based application necessitates user authentication or resource authorization, incorporating `Seablast\Auth` will equip it with these capabilities instantly.
(For applications that do not require these features, `Seablast\Auth` can simple be not included to maintain a lighter application footprint.)

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

### Routing
`/user` is expected, so configure it within your `conf/app.conf.php` like this:
```php
        ->setArrayArrayString(
            SeablastConstant::APP_MAPPING,
            '/user',
            [
                'template' => 'user', // your latte template including login-form.latter
                'model' => '\Seablast\Auth\UserModel',
            ]
        )
```

### View
`\Seablast\Auth\UserModel` returns arguments ($configuration, $csrfToken, $message, $showLogin, $showLogout) for the user.latte template:
```latte
{include '../vendor/seablast/auth/views/login-form.latte'}
```

## Testing
Run [./test.sh](./test.sh) for essential PHPUnit tests:
- create token and use it,
- check its disapperance as it's valid only once,
- invalid emails is not accepted,
- SQL injection attempts is not accepted.

## User management
- user MUST have one role (admin, content manager, ordinary user)
- user MAY belong to various groups (based on subscription tariff, a promotion, etc.)
