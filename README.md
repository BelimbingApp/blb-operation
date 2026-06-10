# BLB Operation

Operation domain for the [Belimbing (BLB)](https://github.com/BelimbingApp/belimbing) framework: IT support and Quality (NCR / SCAR / CAPA workflows).

This repository is a **nested-git domain repo**. It mounts at `app/Modules/Operation/` inside a Belimbing checkout; the framework discovers its providers, migrations, menus, routes, settings, and tests by path convention — no registration step. See `docs/architecture/module-system.md` in the main repo.

```bash
git clone https://github.com/BelimbingApp/belimbing
git clone https://github.com/BelimbingApp/blb-operation belimbing/app/Modules/Operation
```

Licensed under AGPL-3.0-only, same as the framework.
