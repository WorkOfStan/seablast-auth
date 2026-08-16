# Seablast Auth

A no-password authentication and authorization extension for [Seablast for PHP](https://github.com/WorkOfStan/seablast) apps.
This extension facilitates secure user verification and efficient access control.

Optionally, Seablast Auth has a lightweight integration with Google and Facebook to support social authentication, allowing seamless sign-in through various social media platforms.
Integrable via Composer, it activates only when required, equipping your app with essential security features effortlessly.
If your Seablast-based application necessitates user authentication or resource authorization, incorporating Seablast Auth will equip it with these capabilities instantly.
(For applications that do not require these features, Seablast Auth can simply be omitted to maintain a lighter application footprint.)

Note: Ensure that your PHP and MySQL timezones are properly set, as the code uses CURRENT_TIMESTAMP for time-related operations. (It would be possible to use purely SQL statements with `INTERVAL` but at a cost of not caching the SQL responses.)

## User management

- RBAC (Role-Based Access Control) supported
- user MUST have one role (admin, editor, ordinary user)
- user MAY belong to various groups (based on subscription tariff, a promotion, etc.)

## Usage

When just getting the identity of a logged-in user is needed:

```php
    // Instantiate the IdentityManager class with `\mysqli`
    $identity = new IdentityManager($this->configuration->mysqli());
    // If prefix is used, inject it
    $identity->setTablePrefix($this->configuration->dbmsTablePrefix());
    // To make Remember Me cookies predictable and thus avoid conflicts, inject a cookie path
    $identity->setCookiePath($this->configuration->getString(SeablastConstant::SB_SESSION_SET_COOKIE_PARAMS_PATH));
```

To create the expected database table structure, just add the seablast/auth migration path to your phinx.php configuration, e.g.

```php
    'paths' => [
        'migrations' => [
            '%%PHINX_CONFIG_DIR%%/db/migrations',
            '%%PHINX_CONFIG_DIR%%/../vendor/seablast/auth/conf/db/migrations',
        ],
        'seeds' => '%%PHINX_CONFIG_DIR%%/db/seeds'
    ],
```

Following tables will be created (prefixed as set in your app), so avoid conflict with the naming of tables by your app:

- email_token (user)
- group (user_groups)
- group_activation_tokens (user_groups)
- roles (user)
- session_user (user)
- users (user)
- user_group (user_groups)

### Cookies

IdentityManager expects cookie scope being set already by:

```php
session_set_cookie_params(
    int $lifetime_or_options,
    ?string $path = null,
    ?string $domain = null,
    ?bool $secure = null,
    ?bool $httponly = null
): bool
```

Note: `sbRememberMe` cookie is created/read only if the web is accessed over HTTPS and if allowed by `AuthApp:FLAG_REMEMBER_ME_COOKIE` (allowed by default). The cookie is set with `HttpOnly`, `Secure`, and `SameSite=Lax`.
Bundled models inject the flag into `IdentityManager`; direct `IdentityManager` users can call `setRememberMeCookieEnabled(false)`.

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

The bundled `UserModel` applies a 120-second cooldown to repeated login-email requests for the same address.
Suppressed requests return the same generic confirmation as accepted requests so that the response does not reveal
internal request state. This is a per-address safeguard only; consuming applications should add per-IP and global
rate limiting at the application, reverse-proxy, or edge layer.

Successful login either reloads the current page or goes to a social login success page:

```php
        ->setString(AuthConstant::SOCIAL_LOGIN_SUCCESS_URL, '') // empty OR not set => just reload; otherwise go to the fully qualified URL of a social login success page
```

Note 1: Seablast::v0.2.5 and newer use the default settings in the [conf/app.conf.php](conf/app.conf.php), so Seablast Auth configuration is loaded automatically.

`send-auth-token.js` (since Seablast::v0.2.10) expects the route `/api/social-login` as configured in [app.conf.php](conf/app.conf.php) and provider either `facebook` or `google`.

These arguments `window.sendAuthToken(token, apiRoute, errorLogger);` are processed since Seablast::v0.2.13.

Note 2: `const API_BASE = ''; const flags = [];` MUST be defined in JavaScript as the default `/user` expects these two variables.

### View

`\Seablast\Auth\UserModel` returns arguments ($configuration, $csrfToken, $message, $showLogin, $showLogout) for the user.latte template:

```latte
{include '../vendor/seablast/auth/views/user-control.latte'}
```

Note 1: user.latte uses inherite.latte for all the latte parts, so either you may use it or include user-control.latte or create app version of any of the latte parts.

#### Optional logout-link formatting

The bundled logout form has the `seablast-auth-logout-link` class. It remains a CSRF-protected POST form, but its submit button can optionally be formatted as a link:

```css
form.seablast-auth-logout-link {
    display: inline;
}

form.seablast-auth-logout-link input[type="submit"] {
    padding: 0;
    border: 0;
    background: none;
    color: inherit;
    font: inherit;
    text-decoration: underline;
    cursor: pointer;
}
```

Note 2: vendor/seablast is accessible for Seablast apps, so the web browser assets (such as `send-auth-token.js`) used by plugins MUST be put into assets folder of the Seablast library.

### Social login

The presence of configuration strings `FACEBOOK_APP_ID` with `FACEBOOK_APP_SECRET`, or `GOOGLE_CLIENT_ID`, enables login by these platforms respectively. Facebook access tokens are validated through `debug_token` for the configured app before the email is trusted.

Note 1: social login can be deactivated in an app by `->deactivate(AuthConstant::FLAG_USE_SOCIAL_LOGIN)` in the configuration.

Note 2: send-auth-token.js is expected in seablast directory, which needs at least Seablast v0.2.10. (These arguments `window.sendAuthToken(token, apiRoute, errorLogger);` are processed since Seablast::v0.2.13.)

Note 3: The new Google Identity Services no longer opens a traditional pop-up account chooser; instead, it displays the One Tap UI.

### MailOut::send() method is a generic mail sender built on top of Symfony Mailer

In order to send emails, the `SeablastConstant::USER_MAIL_ENABLED` flag MUST be activated.

```php
  // Usage:
  use Seablast\Auth\MailOut;

  /** @var \Seablast\Seablast\SeablastConfiguration $seablastConfiguration */
  $sendMail = new MailOut($seablastConfiguration);
  $sendMail->send(
    'user@example.com', // to
    'Login link', // subject
    "Open this URL: https://app.example.com/?token=XYZ", // textBody
    [
      'cc'   => ['cc1@example.com', 'cc2@example.com'], // optional
      'bcc'  => 'audit@example.com',                    // optional, can be string or array
      'html' => '<p>Open this URL: <a href="https://app.example.com/?token=XYZ">Login</a></p>', // optional
      // 'replyTo' => 'support@example.com',           // optional
      // 'from'    => 'custom-from@example.com',       // optional override of defaultFrom
      // 'priority'=> Email::PRIORITY_HIGH,            // optional (1..5), default normal
    ]
  );
```

## Testing

Run `.\vendor\bin\phpunit` on Windows for essential PHPUnit tests. From Git Bash, [./test.sh](./test.sh) also prepares the testing database migration before running PHPUnit.

- create token and use it,
- check its disappearance as it's valid only once,
- an invalid email format is not accepted,
- SQL injection attempts is not accepted.

## TODO

- 251227, success email token login/logout page
- 251227, define also (social login) logout page
- 260707, before this update, social login didn't set users.last_login , so these accounts were protected before deletion by "remove never-logged-in users older than 15 minute" because the session_user is not pruned, yet. Sometimes after this update, start to carefully prune also the session_user table.
