<p align="center">
    <img src="https://raw.githubusercontent.com/laravel/vet/0.x/art/logo.png?ref=1" alt="Vet example" height="300">
</p>

> [!NOTE]
> Laravel Vet is in beta. The behaviour can change before the first stable release.

Laravel Vet is a dependency audit for PHP. It shows you the code that `composer update` is about to write into `vendor/`, and records the packages you trust in a `vet.json` file.

- **Read before it lands.** Vet shows each change, one package at a time, before Composer writes it.
- **Trust once.** Vet remembers what you trusted, so the next update only asks about what changed.
- **Let your agent read.** Claude Code, Codex, Gemini or opencode reads the changes and answers `PASS` or `FAIL`.
- **Wait for new releases.** Vet can hold back every release younger than a number of days, so a compromised release has time to be found and pulled.

Vet runs as a Composer plugin, on every install and update, in any PHP project:

```
❯ composer update

  to review (1)

  carbonphp/carbon-doctrine-types 3.1.0 → 3.2.0 ............... 1 file changed
  │
  │   ~ src/Carbon/Doctrine/DateTimeType.php
  │     @@ -17,7 +17,7 @@
  │     -    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?DateTime
  │     +    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?Carbon
  │

  Packages: 1 to review, 124 trusted

   ERROR  [1] package is not trusted. Read every change with [./vendor/bin/vet -v]. Run [./vendor/bin/vet] in a terminal to pick the ones that you trust.
```

## Installation

> **Requires [PHP 8.4+](https://php.net/releases/)**.

You can install Laravel Vet via Composer:

```shell
composer require laravel/vet --dev
```

Next, record the packages you trust today. The `--minimum-release-age` option holds back every release younger than 7 days:

```shell
./vendor/bin/vet --init --minimum-release-age=7
```

```
   INFO  Trusted [125] packages, and wrote [vet.json].

   WARN  Vet now holds back each release younger than [7] days. Run [composer update] again to move each package to a release that is old enough.
```

Run `composer update` once more, and Composer moves each package to the newest release that is old enough. Commit `vet.json`.

## Auditing Your Dependencies

Run vet to see where you stand:

```shell
./vendor/bin/vet
```

```
   INFO  All [125] packages are trusted.
```

Vet exits with a non-zero status when a package is not trusted, so a package that nobody has read fails your build. In a terminal, vet then asks which packages you trust:

```
 ┌ Which packages do you trust? ────────────────────────────────┐
 │ ◼ acme/logger   1.2.0 → 2.0.0                                │
 │ ◻ acme/tooling  4.1.0 → 4.2.0                                │
 └──────────────────────────────────────────────────────────────┘

   INFO  Recorded [acme/logger] [2.0.0] at [8002bb9cf6c9].
```

To read every change of one package, pass its name:

```shell
./vendor/bin/vet acme/logger
```

## Reviewing With Your Agent

In a terminal, vet asks how you want to review. Pick your coding agent, and it reads the changes of each package and reports back. The agent reads, and you decide:

```
  acme/logger 1.2.0 → 2.0.0 ................................. 12 files changed
  │  FAIL   src/Ship.php reads .env and sends it to an unknown host

  carbonphp/carbon-doctrine-types 3.1.0 → 3.2.0 .............. 2 files changed
  │  PASS   the changes narrow two return types
```

`PASS` means the agent read every file and found no attack. `FAIL` names each file and the reason. `WARN` means part of the reading is yours, such as a file too big for the prompt. Vet picks every `PASS` package in the list for you, so one `enter` records them, and you read the rest.

The agent runs only when you ask for it. The Composer plugin never asks.

## The Trust File

`vet.json` holds one entry for each package: the version you read, and the hash of its files. When a package ships the same version with different bytes, vet asks you to read the difference:

```json
{
    "require": {
        "carbonphp/carbon-doctrine-types": {
            "version": "3.2.1",
            "hash": "tree-v2:0f158f3b909fc01e691ed5f5121186056232b049031e7d3a914676d49881ece5"
        }
    },
    "require-dev": { … },
    "minimum-release-age": 7,
    "minimum-release-age-exclude": [
        "laravel/*"
    ],
    "ignore": {
        "laravel/framework": [
            "config/cache.php"
        ]
    }
}
```

`minimum-release-age` is the number of days a release waits before vet lets it in. A release younger than that fails the audit, even one you trust. Packages under `minimum-release-age-exclude` skip the wait, and `*` matches any part of a name. Vet itself always skips it.

`ignore` lists the paths that a tool rewrites inside `vendor/` on purpose, such as the configuration files that Laravel Vapor changes during a deploy. Vet leaves them out of the hash and out of the changes.

To start the trust file again, run `./vendor/bin/vet --fresh`. It clears every entry, keeps your settings, and records what `vendor/` holds today.

## Contributing

Thank you for considering contributing to Laravel! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

Please review [our security policy](https://github.com/laravel/vet/security/policy) on how to report security vulnerabilities.

## License

Laravel Vet is open-sourced software licensed under the [MIT license](LICENSE.md).
