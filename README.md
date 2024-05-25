# seablast-auth
Seablast-Auth is a no-password authentication and authorization library for `Seablast for PHP` apps.
This extension facilitates secure user verification and efficient access control.
Optionally, Seablast-Auth integrates with the HybridAuth library to support social authentication, allowing seamless sign-in through various social media platforms.
Integrable via Composer, it activates only when required, equipping your app with essential security features effortlessly.
If your Seablast-based application necessitates user authentication or resource authorization, incorporating Seablast-Auth will equip it with these capabilities instantly.
For applications that do not require these features, Seablast-Auth can be excluded to maintain a lighter application footprint.

## Usage

### Routing
`/user` is expected

### View
```latte
{include '../vendor/seablast/auth/views/login-form.latte'}
```
