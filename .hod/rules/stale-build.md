---
name: stale-build
description: Treat a `builds/vet` that is older than the source as normal.
---

# Treat a stale `builds/vet` as normal

Do not report a `builds/vet` that differs from the source in `app/` as a fault, a blocker of a push or a blocker of a commit. The user rebuilds `builds/vet` with `./vet app:build` at release time, not on each change. Do not run `./vet app:build` unless the user asks for it.
