# Contributing to Phue

Thanks for considering a contribution. Phue is an open-source, beginner-friendly
PHP web framework, and contributions of all sizes are welcome — bug reports,
documentation fixes, tests, and code.

By participating you agree to the
[Developer Certificate of Origin](#developer-certificate-of-origin-dco) for every
commit you submit.

---

## Ways to contribute

- **Report bugs** — open an issue with a minimal reproduction, expected vs actual
  behavior, PHP version, and the `APP_ENV` you ran with.
- **Improve docs** — `docs/*.md` are as important as code.
- **Write tests** — the suite is the safety net for the framework.
- **Fix bugs / add features** — open an issue first for anything non-trivial, so
  we can agree on the approach before you spend time on a PR.

Do **not** report security vulnerabilities in public issues — see
[Reporting security issues](#reporting-security-issues).

---

## Development environment

Phue targets **PHP >= 8.5**. Bun is optional (SSR sidecar / JS vendor packages),
Docker is optional but it is the canonical test harness.

### Local (no Docker)

```bash
composer install
./phue serve:php        # http://localhost:8000, docroot public/
```

### Docker (recommended)

```bash
./phue-docker build
./phue-docker up
./phue-docker start         # also boots the Phue HTTP + Bun SSR servers
./phue-docker restart
./phue-docker shell         # interactive shell in the app container
./phue-docker down
```

### Running the tests

The canonical harness is `bin/phue_test` (a selector in front of PHPUnit) —
**not** `vendor/bin/phpunit` directly:

```bash
# Inside the container: only what your working tree can affect
docker exec phue_app php bin/phue_test --changed

# Everything, at the close of a task (a few minutes)
docker exec phue_app php bin/phue_test

# Re-run last run's failures
docker exec phue_app php bin/phue_test --only-failed

# What would run, without running it
docker exec phue_app php bin/phue_test --changed --list

# Locally, same modes
./bin/phue_test --changed
```

The runner never runs the suite concurrently — the MariaDB-backed tests share a
server and the DevTool's PDO wrapper resolves its callback by method name, so
sharding loses races. Full suite output is large; redirect it and search.

---

## Coding standards

- **`declare(strict_types=1);`** at the top of every PHP file.
- **PSR-12** formatting and PSR-4 autoloading (`Phue\` → `src/`, `App\` → `App/`).
- Prefer **typed properties, constructor promotion, and readonly** where they fit.
- **No comments that restate the code.** Comment the *why*, not the *what*.
- Follow the existing patterns; when in doubt, mirror a neighboring file.
- Framework code (`src/`) must **not** reference application code (`App\`).
- New features need tests. Bug fixes need a regression test that fails before and
  passes after.

---

## Commit & pull-request conventions

- Keep commits focused; one logical change per commit.
- Write imperative, present-tense commit subjects (e.g. `Add DCO enforcement to CI`).
- **Sign off every commit** (see the DCO below):

  ```bash
  git commit -s -m "Fix schema diff envelope"
  ```

- Open the PR against `main`, describe **what** and **why**, and link the issue.
- CI must be green before review. Reviewers may ask for changes; please squash
  fixups before merge when asked.

---

## Developer Certificate of Origin (DCO)

Every contribution must be certified with the following (verbatim):

```
Developer's Certificate of Origin 1.1

By making a contribution to this project, I certify that:

(a) The contribution was created in whole or in part by me and I
    have the right to submit it under the open source license
    indicated in the file; or

(b) The contribution is based upon previous work that, to the best
    of my knowledge, is covered under an appropriate open source
    license and I have the right under that license to submit that
    work with modifications, whether created in whole or in part
    by me, under the same open source license (unless I am
    permitted to submit under a different license), as indicated
    in the file; or

(c) The contribution was provided directly to me by some other
    person who certified (a), (b) or (c) and I have not modified
    it.

(d) I understand and agree that this project and the contribution
    are public and that a record of the contribution (including all
    personal information I submit with it, including my sign-off) is
    maintained indefinitely and may be redistributed consistent with
    this project or the open source license(s) involved.
```

Adding `-s` to `git commit` appends a `Signed-off-by: Full Name <email>` line,
which is how you assert the DCO.

---

## Licensing of contributions

Phue is licensed under the [Apache License 2.0](LICENSE). Unless you explicitly
state otherwise, any contribution you submit is under the same terms, as set out
in section 5 of the license ("Submission of Contributions").

> Maintainer note: because Apache-2.0 is irrevocable, a DCO preserves the project
> license but does **not** grant the maintainer the right to relicense the project
> under different terms. If you ever want to offer a commercial or dual license,
> switch to a CLA *before* external contributions land.

---

## Reporting security issues

Please do **not** open a public issue for security problems. Instead, email the
maintainer directly (see `composer.json` → `authors`) with a description, impact,
and reproduction. We will acknowledge and coordinate a fix and disclosure
privately.
