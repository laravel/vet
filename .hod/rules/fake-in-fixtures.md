---
name: fake-in-fixtures
description: Put a fake, a stub or a test double in `tests/Fixtures/`.
---

# Put a fake, a stub or a test double in `tests/Fixtures/`

Write a class that a test uses in place of a real dependency, such as a fake HTTP client, in `tests/Fixtures/<Name>.php` with the namespace `Tests\Fixtures`. Put no such class at the top of `tests/`. Pint, Rector and PHPStan read a file at the top of `tests/Fixtures/` and skip each directory below it, thus a fake obeys the same lint as the code in `app/`.
