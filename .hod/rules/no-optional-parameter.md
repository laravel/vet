---
name: no-optional-parameter
description: Write no nullable type and no default value on a parameter of a constructor, a method, a function or a closure.
---

# Write no nullable type and no default value on a parameter

Write no `?Type $value`, no `$value = null`, no `$value = []`, no `$value = true` and no `$value ?? new Type` on a parameter of a constructor, a method, a function or a closure. Give the value at each call site. Read the `code-quality` skill for the replacement of each nullable type and of each default value. Bind a dependency in `app/Providers/AppServiceProvider.php`, then resolve it with `app(Type::class)` in the static factory of the class that needs it. A test passes its own instance to the constructor.
