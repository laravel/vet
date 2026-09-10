---
name: laravel-helpers-first
description: Call the Laravel helper before you write the routine by hand.
---

# Call the Laravel helper before you write the routine by hand

Before you write a loop, a filesystem routine or a string routine in `app/Actions/`, `app/Commands/`, `app/Support/` or `app/Providers/`, search `Illuminate\Support\Facades\File`, `Illuminate\Support\Str`, `Illuminate\Support\Arr` and `Illuminate\Support\Collection` for the same operation, and call that operation. Delete a directory with `File::deleteDirectory()`. Create a directory and its parents with `File::ensureDirectoryExists()`, then test the result with `File::isDirectory()`. Cut a prefix with `Str::chopStart()`. Read the first item that matches a condition with `Arr::first()`. Test each item of a list with `Collection::every()` and `Collection::contains()`. Split a list in two with `Collection::partition()`.

Call `env()` in a file of `config/` and in no other file. Read a variable of the environment with `getenv()`, thus the program reads the value that the process holds at that moment. Read `config()` for a value that a file of `config/` declares.

Call no helper of `Illuminate` in `app/ValueObjects/`, `app/Enums/`, `app/Exceptions/` and `app/Composer/`. `tests/Arch.php` stops the first three, and `app/Composer/` runs inside composer, where no container and no facade exists. Write plain PHP in those four directories.

Use no method of `Illuminate\Support\Number`. Each one needs `ext-intl`, and `composer.json` requires no such extension, thus the program stops on a machine that does not load it.
