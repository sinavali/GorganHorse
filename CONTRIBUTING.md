# Contributing — Gorgan Horse Federation Panel

## Branch Strategy

- **main** — Production-ready code only. Never commit directly.
- **kilo/modular-chime-ft3** — Active development branch. Create PRs from feature branches to main.

## How to Contribute

### Before You Start
1. Read `AGENTS.md` for project architecture and conventions.
2. Read `documents/Backend Blueprint — Gorgan Horse Federation Panel.md` for domain rules.
3. Read `documents/Technical — Gorgan Horse Federation Panel.md` for implementation rules.
4. Read `documents/User Usage — Gorgan Horse Federation Panel.md` for frontend contract.

### Creating a Feature Branch
```bash
git checkout kilo/modular-chime-ft3
git checkout -b your-feature-branch
```

### Code Standards
- `declare(strict_types=1);` in every PHP file
- PHP 8.1+ syntax, PSR-12 style
- Docblocks at file, class, and method level (mandatory)
- All code strings in English; all user-facing strings in fa-IR
- No closing `?>` in PHP-only files
- Prepared statements everywhere; whitelist-driven report queries
- Writes go through services; controllers never touch DB directly

### Committing
Follow conventional commit format:
```
feat(scope): description
fix(scope): description
docs(scope): description
test(scope): description
```

### Testing
- Add unit tests in `tests/Unit/` for new services
- Add integration tests in `tests/Integration/` for controller flows
- Run tests: `vendor/bin/phpunit` or `phpunit`

### Pull Request Process
1. Push your feature branch
2. Create a PR to `main` from `kilo/modular-chime-ft3`
3. PR must include tests for new logic
4. PR description must follow the template in `.github/instructions/pull-request.instructions.md`
5. Wait for review approval
6. After approval, merge to `main`

### What NOT to Change
- `documents/Features.md` — Agents may edit only Status and Notes fields of existing entries. Do not add, remove, reorder, or rename entries without owner approval.
- `database/schema.sql` / `database/schema_logs.sql` — Schema changes require Blueprint update first.
- `public/assets/vendor/` — Vendored libraries; update manually, do not modify internals.

## Running the Project Locally
1. Copy project folder to web root
2. Ensure PHP 8.1+ with required extensions (see AGENTS.md)
3. Visit `/install` to set up databases, settings, and first admin
4. No Composer, no Node, no build step at runtime

## Contact
For specification questions, refer to the specific document and section number in `documents/`.
