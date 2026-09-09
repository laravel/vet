---
name: message-value-brackets
description: Put each value that a message interpolates inside square brackets.
---

# Put each interpolated value of a message inside square brackets

Write `[%s]`, `[%d]` and `[%.1f]` for each value that an exception message, a log line or a line of prose output interpolates, thus the reader sees where the value starts and where it ends. This rule holds for each `sprintf` and each `printf` whose result is a sentence: the message of an exception, the text that `$this->components->info()`, `warn()` and `error()` receive, the text of a note, and the text of a problem. Write no bracket around a placeholder that carries markup, such as `<fg=%s>`, around a placeholder that holds the whole styled line, such as `<fg=gray>%s</>`, around the value and the unit of `Bytes::format()`, around the numbers of a unified diff header, and around the segment of a URL template.
