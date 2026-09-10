# Laravel Vet

<a name="introduction"></a>
## Introduction

[vet](https://github.com/laravel/vet) is a dependency audit for PHP. It shows you what a `composer update` is about to put into the `vendor/` directory, and lets you **or your agent** review those changes, one by one, before they land.

```
❯ composer update

  to review (1, worst first)

  carbonphp/carbon-doctrine-types 3.1.0 → 3.2.0  2 files to review (delta from 3.1.0)
      composer would install these bytes; you trust 3.1.0  ·  8.0 KB

  runtime source (2)
    ~ src/Carbon/Doctrine/DateTimeImmutableType.php
      @@ -17,7 +17,7 @@
           /**
            * @SuppressWarnings(PHPMD.UnusedFormalParameter)
            */
      -    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?DateTimeImmutable
      +    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?CarbonImmutable
           {
               return $this->doConvertToPHPValue($value);
           }

    ~ src/Carbon/Doctrine/DateTimeType.php
      @@ -17,7 +17,7 @@
           /**
            * @SuppressWarnings(PHPMD.UnusedFormalParameter)
            */
      -    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?DateTime
      +    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?Carbon
           {
               return $this->doConvertToPHPValue($value);
           }


  audited .................................................. 124 / 125 (99.2%)

   ERROR  1 package(s) are not covered. Read every change with `composer update -v`, then record them with `vet trust`.

```

<a name="installation"></a>
## Installation

> **Requires [PHP 8.3+](https://php.net/releases/)**.

You may install vet into your project via the Composer package manager:

```shell
composer require laravel/vet --dev
```

By default, vet commands are invoked using the `./vendor/bin/vet` script that is included with the package:

```shell
./vendor/bin/vet audit
```

<a name="trusting-your-dependencies"></a>
## Trusting Your Dependencies

Before vet can show you what changed, it needs to know what you trust today. The `trust` command lists every installed package with the reason it needs an entry, records the tree on disk, and writes the `vet.json` trust file:

```shell
vet trust
```

```
  to trust (125)

  brianium/paratest v7.24.0 (dev) ........ no entry; this tree is 3629153db155
  brick/math 0.18.0 ...................... no entry; this tree is 2874e68aa900
  carbonphp/carbon-doctrine-types 3.2.0 .. no entry; this tree is ad33848c07e8
  …

   INFO  Trusted 125 package(s), and wrote vet.json.
```

<a name="auditing-your-dependencies"></a>
## Auditing Your Dependencies

Once the trust file exists, run the `audit` command whenever you want to know where you stand. It reports the packages that have no entry:

```shell
vet audit

   INFO  All 125 packages are covered.

```

<a name="auditing-a-single-package"></a>
### Auditing a Single Package

You may audit one package by passing its name to the `audit` command:

```shell
vet audit symfony/console
```

```
  symfony/console .................................................... v7.4.18
  hash  tree-v2:8002bb9cf6c918d597582aaebf943f3ef0455d8a9ce724fafb3aac307c63cfe0
  source ................................................................ dist
  contents ............................................... 140 files, 619.4 KB
  path ................................................ vendor/symfony/console
```

When the trust file already covers the installed version, the report stays local and instant. When the trust file holds an earlier version, vet fetches that version from Packagist and shows you the delta. If you would like to compare against some other version, you may name it using the `--from` option:

```shell
vet audit carbonphp/carbon-doctrine-types --from=3.1.0
```

<a name="handing-a-review-to-your-agent"></a>
### Handing a Review to Your Agent

Reading every delta by hand takes time. The `--agent` option hands each delta to the coding agent already on your machine, and prints the verdict it writes next to the package:

```shell
vet audit --agent
```

```
  to review (3, worst first)

  acme/logger 1.2.0 → 2.0.0  you trust 1.2.0     12 files (delta from 1.2.0)
    agent  RISK  src/Ship.php reads .env and sends it to an unknown host
           src/Ship.php  it posts the contents of [.env] to [telemetry.example.com]

  acme/tooling 4.1.0 → 4.2.0  you trust 4.1.0     8 files (delta from 4.1.0)
    agent  partial  [1] file(s) did not reach the agent. the delta adds two commands

  carbonphp/carbon-doctrine-types 3.1.0 → 3.2.0   2 files (delta from 3.1.0)
    agent  clear  the delta changes two return types

  audited .................................................. 123 / 125 (98.4%)
```

A verdict is one of four. `clear` means the agent read every byte and found no attack. `RISK` comes with one line for each file the agent names. `partial` means one file never reached the prompt, such as a `.phar` that holds no readable text, so nobody read it. `no verdict` means the answer did not arrive, or it named a file that the delta does not hold.

vet looks for `claude`, then `codex`, then `gemini` on your `PATH`. Name a different one in the `VET_AGENT_BINARY` environment variable, and vet gives it the prompt on standard input.

vet turns the tools of the agent off and asks for one JSON object back, so the agent reads the delta and does nothing else. The delta stands inside a marker that carries a token of the run, and vet checks every file the answer names against the files the delta holds.

A package with no entry in your trust file has no earlier tree to compare against. vet sends its whole tree instead, because that is the package you know least.

The agent reads. You record. A verdict writes nothing to `vet.json`, so `vet trust` stays the moment you decide.

The `preview` command takes the same option, and reads the delta before the bytes reach `vendor/`:

```shell
vet preview --agent
```

<a name="the-trust-file"></a>
## The Trust File

The trust file lives in `vet.json`, at the root of your project, next to `composer.json`. You should commit it. It holds one entry for each package: the version you reviewed, and the hash of the tree you reviewed.

```json
{
    "schema": 4,
    "require": {
        "carbonphp/carbon-doctrine-types": {
            "version": "3.2.1",
            "hash": "tree-v2:0f158f3b909fc01e691ed5f5121186056232b049031e7d3a914676d49881ece5"
        }
    },
    "require-dev": {
        "brianium/paratest": {
            "version": "v7.20.0",
            "hash": "tree-v2:075f8b7e73532ba3689126db0f91288a199bcba5f2743bc17a9bf37d53e030c1"
        }
    }
}
```

<a name="continuous-integration"></a>
## Continuous Integration

Your build audits your dependencies the moment it installs them. vet ships a Composer plugin, and the plugin runs the audit after every `composer install`, and again before `composer update` writes anything into `vendor/`. There is no step to add.

## Contributing

Thank you for considering contributing to Laravel! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

Please review [our security policy](https://github.com/laravel/vet/security/policy) on how to report security vulnerabilities.

## License

The Laravel AI SDK is open-sourced software licensed under the [MIT license](LICENSE.md).
