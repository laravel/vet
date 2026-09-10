# Laravel Vet

Laravel Vet shows you what a `composer update` is about to write into your `vendor/` directory, and records the trees you trust in a `vet.json` file. With vet, you can read each change one package at a time, hand a delta to the coding agent already on your machine, record a note next to your decision, and hold your build to the bytes you have read.

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

   ERROR  1 package(s) are not covered. Read every change with `composer update -v`. Run `vet` in a terminal to record the ones that you trust.

```

## Installation

> **Requires [PHP 8.3+](https://php.net/releases/)**.

You can install Laravel Vet via Composer:

```shell
composer require laravel/vet --dev
```

By default, vet is invoked using the `./vendor/bin/vet` script that is included with the package:

```shell
./vendor/bin/vet
```

vet has one command. It audits what `vendor/` holds, and when you run it in a terminal, it asks which of the uncovered packages you trust. Until `vet.json` exists, vet has no earlier tree to compare an update against, so the first step is to record the packages you trust today.

## Recording Your Baseline

The `--fresh` option records every package that `vendor/` holds today, and writes `vet.json` for the first time:

```shell
vet --fresh
```

```
  to trust (125)

  brianium/paratest v7.24.0 (dev) ........ no entry; this tree is 3629153db155
  brick/math 0.18.0 ...................... no entry; this tree is 2874e68aa900
  carbonphp/carbon-doctrine-types 3.2.0 .. no entry; this tree is ad33848c07e8
  …

   INFO  Trusted 125 package(s), and wrote vet.json.
```

The `--fresh` option covers the bytes that are already on your disk, and nothing else. When `composer.lock` asks for a tree that `vendor/` does not hold yet, vet leaves that tree alone and asks you to read it:

```
   ERROR  composer would write 2 package(s) that vendor/ does not hold. Run `vet` in a terminal to read them, or run `composer install` first.
```

## Auditing Your Dependencies

Once the trust file exists, `vet` tells you where you stand. It reads the tree of every installed package, compares it against your entries, and names the packages that have none:

```shell
vet

   INFO  All 125 packages are covered.

```

vet exits with a non-zero status when a package is not covered, which is what makes it useful in a build. Without a terminal, in your CI or inside the Composer plugin, the report is all that vet writes.

### Picking What to Trust

In a terminal, vet follows the report with a question. Every package without an entry appears in the list, marked `installed` or `incoming`, so you always know whether the bytes are on your disk or on their way in. Press the space bar to pick a package, `ctrl+a` to pick every package, and enter to record the ones that you picked. The delta of each package sits in the report above the list, so you read first and pick second:

```
 ┌ How do you want to review these packages? ───────────────────┐
 │ › Manually, and pick the packages that I trust               │
 │   Automatically, with my coding agent reading the changes first│
 └──────────────────────────────────────────────────────────────┘

 ┌ Which packages do you trust? ────────────────────────────────┐
 │ ◼ acme/logger  1.2.0 → 2.0.0  12 files  incoming             │
 │ ◻ acme/tooling  4.1.0 → 4.2.0  8 files  incoming             │
 │ ◻ brick/math  0.18.0  31 files  installed                    │
 └──────────────────────────────────────────────────────────────┘

   INFO  Recorded 1 package(s).
   INFO  Run composer install to write those bytes to vendor/.
```

The `--notes` option records a note alongside each entry that the run writes. The run exits with a non-zero status until every package is covered. A package you skip fails the run, in the same way it fails your build.

When the project holds no `vet.json` yet, there is no delta to hand to an agent, so vet skips the first question and shows the list right after the report. Pick the packages you trust today, or press `ctrl+a` to record every one, which is what `--fresh` does without a question.

### Auditing a Single Package

You may audit one package, or a few, by passing their names. vet shows you the tree, and records nothing:

```shell
vet acme/logger acme/tooling
```

```
  acme/logger ....................................... 1.2.0 → 2.0.0
  hash  tree-v2:8002bb9cf6c918d597582aaebf943f3ef0455d8a9ce724fafb3aac307c63cfe0
  source ..................................................... dist
  contents ......................................... 12 files, 41.2 KB
  path ......................................... vendor/acme/logger
```

When the trust file already covers the installed version, the report stays local. When the trust file holds an earlier version, vet fetches that version from Packagist and shows you the delta. The `--from` and `--to` options compare any two versions, and the `--notes` option writes a note on the entry of a package that you already trust:

```shell
vet carbonphp/carbon-doctrine-types --from=3.1.0
vet carbonphp/carbon-doctrine-types --from=3.1.0 --to=3.2.0
vet acme/logger --notes="Read with the team on Friday."
```

### Reading a Delta

vet sorts the files of a delta into four buckets, and shows you the ones that can hurt you first:

| Bucket | What it holds |
| --- | --- |
| `install-manifest` | The `composer.json` of the package, which can add a script that runs at install time |
| `opaque` | Bytes that nobody can read, such as a `.phar` or a compiled library |
| `runtime-source` | The source that your application autoloads and executes |
| `inert` | Everything else, such as tests, documentation and images |

The `--bucket` option reads one bucket at a time:

```shell
vet symfony/console --bucket=runtime-source
```

## Handing a Review to Your Agent

Reading every delta by hand takes time. The `--agent` option hands each delta to the coding agent already on your machine, and prints the verdict it writes next to the package:

```shell
vet --agent
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

In a terminal, each verdict sits on its row of the list, and vet picks every `clear` row for you before you read it. One `enter` records the packages the agent cleared, and you read `RISK` before you decide. The first question offers the agent too, so you can ask for it without the option.

Before the agent reads, vet asks which model it uses. Pick one from the list, type a name, or press `enter` to keep the default model of the agent:

```
 ┌ Which model do you want the agent to use? ───────────────────┐
 │ Press enter for the default model of [claude].               │
 ├──────────────────────────────────────────────────────────────┤
 │ fable                                                        │
 │ opus                                                         │
 │ sonnet                                                       │
 │ haiku                                                        │
 └──────────────────────────────────────────────────────────────┘
```

The `--model` option gives the answer without the question, which is what a script needs:

```shell
vet --agent --model=opus
```

The agent runs only when you ask for it. The Composer plugin never asks, and a verdict writes nothing to `vet.json` until you answer the question, so the decision stays yours.

### How the Agent Reads

vet looks for `claude`, then `codex`, then `gemini` on your `PATH`, and gives it the prompt on standard input. The `VET_AGENT_BINARY` environment variable names a different one.

vet turns the tools of the agent off and asks for one JSON object back, so the agent reads the delta and does nothing else. The delta stands inside a marker that carries a token of the run, and vet checks every file the answer names against the files the delta holds.

A package with no entry in your trust file has no earlier tree to compare against. vet sends its whole tree instead, because that is the package you know least.

## The Trust File

The trust file lives in `vet.json`, at the root of your project, next to `composer.json`. You should commit it. It holds one entry for each package: the version you read, and the hash of the tree you read:

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

The hash covers every file of the tree. When a package ships the same version with different bytes, the entry stops covering it, and vet asks you to read the difference.

## Continuous Integration

Your build audits your dependencies the moment it installs them. vet ships a Composer plugin, and the plugin runs the audit after every `composer install`, and again before `composer update` writes anything into `vendor/`. There is no step to add.

The `--json` option emits the report for another program to read:

```shell
vet --json
```

## Configuration

vet reads three environment variables:

```ini
VET_AGENT_BINARY=
VET_GITHUB_TOKEN=
VET_CACHE_DIR=
```

`VET_AGENT_BINARY` names the coding agent that `--agent` runs. `VET_GITHUB_TOKEN` authenticates the archives that vet downloads from GitHub, and vet falls back to `GITHUB_TOKEN`, to `GH_TOKEN`, and to your Composer authentication file. `VET_CACHE_DIR` holds the archives that vet has already downloaded, and defaults to `vet` inside `$XDG_CACHE_HOME`, or inside `$HOME/.cache`.

Pass the `--no-cache` option to download an archive again instead of reading the cached one:

```shell
vet acme/logger --no-cache
```

## Contributing

Thank you for considering contributing to Laravel! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

Please review [our security policy](https://github.com/laravel/vet/security/policy) on how to report security vulnerabilities.

## License

Laravel Vet is open-sourced software licensed under the [MIT license](LICENSE.md).
