---
name: message-value-brackets
description: Put each value and each command of a message inside square brackets.
---

# Put each interpolated value and each command of a message inside square brackets

Write `[%s]`, `[%d]` and `[%.1f]` for each value that an exception message, a log line or a line of prose output interpolates, thus the reader sees where the value starts and where it ends. This rule holds for each `sprintf` and each `printf` whose result is a sentence: the message of an exception, the text that `$this->components->info()`, `warn()` and `error()` receive, the text of a note, and the text of a problem. Write no bracket around a placeholder that carries markup, such as `<fg=%s>`, around a placeholder that holds the whole styled line, such as `<fg=gray>%s</>`, around the value and the unit of `Bytes::format()`, around the numbers of a unified diff header, and around the segment of a URL template.

Write a command that a message names inside square brackets too, such as `[composer install]` and `[vet trust <package>]`. Write no backtick in a message. Write one pair of brackets around a command that interpolates a value, such as `[vet trust %s]`, and add no second pair around that value.

Write the name of an option, such as `[--from]`, and the name of an environment variable, such as `[VET_COMPOSER_BINARY]`, inside square brackets too. Write no brackets around an option that stands inside a bracketed command, such as `[vet trust <package> --from=<version>]`.
