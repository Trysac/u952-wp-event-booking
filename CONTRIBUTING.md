# Contributing

Thanks for helping improve `trysac/u952-wp-event-booking`.

## Workflow

1. Branch from `main`; never push to `main` directly.
2. `composer install` to fetch the dependencies pinned in `composer.lock`.
3. Write a failing test, then the change.
4. `php scripts/check.php` must pass.
5. Update `CHANGELOG.md` under **Unreleased** and the docs the change affects.
6. Open a pull request describing the problem and the solution.

## Commit messages

Use [Conventional Commits](https://www.conventionalcommits.org/):

```text
feat: add strict mode to normalize()
fix: reject tab-only input
docs: document the thrown exceptions
```

`feat` bumps the minor version, `fix` the patch version, and a `BREAKING CHANGE:` footer the major
version. Releases are git tags `vMAJOR.MINOR.PATCH`; Composer and Packagist read the version from
the tag, so `composer.json` holds none.

## Code of conduct

Be respectful and constructive. Maintainers may close contributions that ignore this guide.
