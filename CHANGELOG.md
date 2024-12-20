# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### `Added` for new features

### `Changed` for changes in existing functionality

### `Deprecated` for soon-to-be removed features

### `Removed` for now removed features

### `Fixed` for any bugfixes

### `Security` in case of vulnerabilities

## [0.1.2] - 2024-12-20

### Added

- Prettier-fix.
- UserException to identify user runtime exceptions.
- Immediate IdentityManager::loginWithTrustedEmail() for social login plugins.
- AuthConstant::USER_ROUTE , i.e. the route to the user log-in/log-out page is '/user' by default.

## [0.1.1] - 2024-06-03

### Fixed

- Transport DSN

## [0.1] - 2024-06-01

IdentityManager and GroupManager

### Added

- class IdentityManager manages user authentication and session handling. Uses MySQLi for database access. The connection is `SeablastSetup::getConfiguration()->dbms();` from a Seablast expected [Phinx configuration](https://book.cakephp.org/phinx/0/en/configuration.html).
- class GroupManager to manipulate groups, to which a user may belong to.
- class UserModel to take care of the login/logout sequence.
- view login-form.latte as UI.

### Security

- PHPUnit tests for invalid emails and SQL injections attempts. Also tested automatically on GitHub.

[Unreleased]: https://github.com/WorkOfStan/seablast-auth/compare/v0.1.2...HEAD
[0.1.2]: https://github.com/WorkOfStan/seablast-auth/compare/v0.1.1...v0.1.2
[0.1.1]: https://github.com/WorkOfStan/seablast-auth/compare/v0.1...v0.1.1
[0.1]: https://github.com/WorkOfStan/seablast-auth/releases/tag/v0.1
