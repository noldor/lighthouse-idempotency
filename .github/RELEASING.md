# Development and release workflow

1. Fetch `origin/master`, switch to `master`, fast-forward it from the remote,
   then create a new task branch before changing files. Implement a coherent task
   and run the Docker quality checks, release-script tests and distribution
   verification below.
2. Complete independent review, push the task branch and open a PR into `master`.
3. Use Conventional Commits for branch commits and the PR title, then use an
   ordinary merge commit. Required GitHub Actions checks are `Quality` and
   `PR title`; all review threads must be resolved. Keep the branch up to date
   with `master`.
4. After merge, Actions rechecks the resulting `master` commit. Only a successful
   quality job can start the release job, which creates an immutable tag. GitHub
   sends the signed push webhook to Packagist, and the release job verifies the
   indexed version. Publication succeeds only when Packagist exposes the expected
   version at the exact checked commit.

## Versions

The first release is always `v0.1.0`. For subsequent releases, the commits
introduced since the previous tag select the SemVer increment. Ordinary merges
retain the branch commits for analysis; the highest applicable increment wins:

| Change | Example | Increment |
| --- | --- | --- |
| Compatible directive, scalar or API feature | `feat(directives): add a directive` | Minor |
| Incompatible behavior or configuration | `feat(directives)!: change an argument` | Major |
| Breaking-change footer | `BREAKING CHANGE: describe the incompatibility` | Major |
| Fixes and other merges | `fix(directives): handle nullable values` | Patch |

Versions are derived from `vX.Y.Z` tags. Keep `composer.json` without a `version`
field. Do not create or move release tags manually. The first-version override
applies only while there are no existing release tags. The release infrastructure
is excluded from distribution archives.

## Repository activation

For an empty repository, establish an empty initial commit on `master`, then
put the package and its automation in a task branch and PR. This creates the
base needed for branch-based development without placing the implementation
directly on `master`. Never bypass existing protection rules to seed a repository
that already has history.

Packagist can register the default branch after the first merge puts
`composer.json` on `master`. During bootstrap, missing package registration
stops the release preflight before tagging. After the first merge, register the
package and configure its GitHub webhook, then dispatch the workflow on `master`
to create `v0.1.0`. Later merges use the normal automatic release path.

Configure the merge settings before merging the initial PR, and complete
Packagist activation after its manifest reaches `master`:

- Default branch: `master`.
- Merge method: ordinary merge commits, preserving branch history.
- Active branch ruleset: import [rulesets/master.json](rulesets/master.json).
  It requires PRs, passing `Quality` and `PR title` checks from GitHub Actions,
  an up-to-date branch and resolved discussions; it blocks force pushes and
  deletion and has no bypass actors.
- Register `https://github.com/noldor/lighthouse-idempotency` on Packagist as
  `noldors/lighthouse-idempotency` after the repository contains its manifest.
- Configure an active GitHub repository webhook with payload URL
  `https://packagist.org/api/github?username=noldors` and content type
  `application/json`. Use the maintaining Packagist account's existing
  **safe API token** as the webhook secret, enable SSL verification and subscribe
  only to `push` events. Keep the token in the webhook configuration; the Actions
  workflow and verifier require no Packagist credentials.
- Verify a successful `ping` or recent push delivery in the webhook's GitHub
  Recent Deliveries list before dispatching the first release.
- Actions must be enabled. The release job requests `contents: write` for the
  repository's built-in `GITHUB_TOKEN`; a separate GitHub PAT is unnecessary.

Use the repository's Rulesets UI to import the JSON, or create the ruleset through
an authenticated GitHub CLI after checking existing rulesets to avoid duplicates:

```sh
gh api repos/noldor/lighthouse-idempotency/rulesets --input .github/rulesets/master.json
```

The release job uses `GITHUB_TOKEN` to create the tag. GitHub's signed push
webhook refreshes Packagist, and the same job waits for its metadata; no second
Actions workflow is needed. Packagist indexes Git tags; this workflow does not
upload a ZIP to Packagist or send a generic API refresh request.

## Verification and recovery

Root `testbench.yaml` explicitly loads Lighthouse's testing provider for Larastan's
Testbench bootstrap, including the first run in a fresh checkout. PHPUnit tests
retain their explicit provider registration. Formatter commands run without their
file cache so local checks match clean CI. The Testbench configuration is
development-only and excluded from distributions.

Use the package's Docker runtime (PHP 8.5.12 and Composer 2.10.3), with its own
PostgreSQL 16 service and test database. Set the container user to the checkout
owner so dependency and archive files remain writable:

```sh
export PACKAGE_UID="$(id -u)" PACKAGE_GID="$(id -g)"
docker compose build app
docker compose run --rm app composer validate --strict
docker compose run --rm app composer install --no-interaction --prefer-dist --no-progress
docker compose run --rm app composer check
PYTHONDONTWRITEBYTECODE=1 python3 -m unittest discover -s .github/tests
docker compose run --rm app composer archive --format=zip --dir=build/distribution --file=composer-package --no-interaction
git archive --format=zip --output=build/distribution/git-package.zip HEAD
PYTHONDONTWRITEBYTECODE=1 python3 .github/scripts/verify_archive.py build/distribution/composer-package.zip build/distribution/git-package.zip
docker compose down --volumes --remove-orphans
```

The Git archive uses committed files. Before committing new package files,
validate the same content with a disposable Git tree outside this checkout.
Both archives must contain exactly `composer.json`, `LICENSE`, `README.md` and
all tracked public files under `src/`, `config/`, `lang/`, `resources/`, `database/` and `docs/`. Development files, tests,
Docker configuration and `.github/` are excluded. CI uses a unique Compose
project for each run and removes its containers and database volume afterward.

Missing Packagist registration fails before a tag is created. A missing or
failing webhook is detected after tagging when publication verification times
out. The verifier makes up to 120 metadata requests, waiting five seconds between
attempts, within the release job's 15-minute limit. It retries temporary 404 and
connection errors; it never falls back to a generic API refresh.

Rerunning the release job or dispatching the workflow on `master` reuses a tag
already pointing at that commit; it is not bumped again, overwritten or deleted.
Reusing a tag does not emit another push webhook. Fix the webhook and redeliver
the original GitHub delivery, then rerun verification without moving the tag. If
the hook is verified, a maintainer may also use Packagist's UI Update action to
refresh the metadata before retrying. Retry the latest `master` run if the branch
has advanced. The workflow serializes releases, and obsolete checked-out commits
cannot tag a newer `master` commit.

Treat a Packagist version pointing at another commit as an error to investigate;
keep existing tags intact. Fix the webhook or registry problem and retry rather
than changing an already published version.

See [GitHub tag action](https://github.com/mathieudutour/github-tag-action) and
[Packagist GitHub hook documentation](https://packagist.org/about#how-to-update-packages).
