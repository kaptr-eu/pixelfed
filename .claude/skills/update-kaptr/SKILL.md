---
name: update-kaptr
description: Merge upstream Pixelfed changes into the kaptr.eu fork, resolve the recurring composer/npm conflicts, and verify the result. Use this whenever the user asks to pull, fetch, sync, merge, update, or rebase from upstream, from the `source` remote, or "from Pixelfed", and whenever a merge in this repo leaves conflicts in `.npmrc`, `composer.json`, or `composer.lock`. Also use it when a `composer install` or `composer update` here fails on missing `ext-redis` or `ext-ffi`, since this repo needs specific flags to get past that.
---

# Updating kaptr.eu from upstream Pixelfed

This repo is a fork of Pixelfed with a small set of local customizations. Upstream
moves fast (thousands of commits between syncs), so the merge itself is routine but
the same three files conflict every time. This skill records how they were resolved
before so you do not have to rediscover it.

## Remotes

| Remote   | Repository                | Role |
|----------|---------------------------|------|
| `origin` | `kaptr-eu/pixelfed`       | Our fork, where our work is pushed |
| `source` | `pixelfed/pixelfed`       | Upstream, read-only for us |

"Pull in the latest changes" means merge `source/dev` into local `dev`. Do not touch
`origin` until the merge is verified, and do not push without being asked.

## What must survive every merge

These are the local customizations. If any of them disappear during conflict
resolution, the merge is wrong:

1. **Aikido middleware** `app/Http/Middleware/AikidoMiddleware.php`, registered in `bootstrap/app.php`
2. **Deployer** `deploy.php` plus `deployer/deployer` in `composer.json` (terminates Horizon on deploy)
3. **`doctrine/dbal`** in `composer.json` (not an upstream dependency)
4. **Belgian branding** `public/img/logo_be.jpg`, `public/img/logo/pwa/*`, `convert-be-logo-overwrite.sh`
5. **`.gitignore`** entry for the Telescope assets, and `.npmrc` with our own comment

`git log --oneline source/dev..HEAD --no-merges` lists the local commits. Run it before
merging so you know what you are protecting.

## Workflow

### 1. Confirm a clean tree and fetch

```bash
git status --short --branch
git fetch source
git rev-list --count HEAD..source/dev   # commits coming in
git rev-list --count source/dev..HEAD   # local commits ahead
```

Stop and ask if the tree is dirty. Merging over uncommitted local work makes the
conflict resolution below impossible to reason about.

### 2. Merge

```bash
git merge source/dev --no-edit
```

Expect conflicts in `.npmrc`, `composer.json`, and `composer.lock`. Anything else
conflicting is new, so read it rather than assuming.

### 3. Resolve `.npmrc`

Both sides set `legacy-peer-deps=true` (the stale `vue-blurhash` peer range on
`blurhash`). Only the comment differs, and ours explains the reasoning better, so keep
ours:

```bash
git checkout --ours .npmrc && git add .npmrc
```

### 4. Resolve `composer.json`

The conflict hunk is in the `require` block. Keep our additions, accept upstream's
removals and version bumps. Concretely, the resolved hunk is usually just:

```json
        "deployer/deployer": "^7.5",
        "doctrine/dbal": "^4.0",
```

Upstream has already removed `buzz/laravel-h-captcha`, having replaced it with its own
`app/Services/Captcha/HCaptchaDriver.php`. That removal is correct to accept. Before
accepting any upstream dependency removal, check nothing local still uses it, for
example `grep -rn "Buzz\\\\" app config resources routes`.

Then validate the JSON is still well formed:

```bash
php -r 'json_decode(file_get_contents("composer.json")); echo json_last_error_msg(),"\n";'
composer validate --no-check-publish --no-check-all
```

### 5. Resolve `composer.lock`

Do not hand-merge the lock file. Take upstream's wholesale, then let Composer re-add
our packages and recompute `content-hash`:

```bash
git checkout --theirs composer.lock
COMPOSER_MEMORY_LIMIT=-1 composer update deployer/deployer doctrine/dbal \
  --no-install --no-scripts -W \
  --ignore-platform-req=ext-redis --ignore-platform-req=ext-ffi
git add composer.json composer.lock
```

Taking upstream's lock first matters because upstream rewrites most of the package
list between syncs. Git's automatic merge of the lock file produces a plausible-looking
file whose `content-hash` and dependency graph do not actually agree.

### 6. Verify before committing

```bash
git diff --name-only --diff-filter=U | wc -l                  # must be 0
grep -rl '^<<<<<<< ' --exclude-dir=node_modules \
  --exclude-dir=vendor --exclude-dir=.git .                   # must be empty
```

### 7. Commit the merge

```bash
git commit --no-edit
```

### 8. Install dependencies

The local PHP (nix build) has neither `ext-redis` nor `ext-ffi`, which `composer`
refuses to work around on its own. Both flags are required on every invocation here:

```bash
COMPOSER_MEMORY_LIMIT=-1 composer install \
  --ignore-platform-req=ext-redis --ignore-platform-req=ext-ffi
```

Post-install scripts (`package:discover`, `app:composer-post-install-command`) do run
successfully despite the missing extensions, so let them.

### 9. Verify the result

Name the check, run it, report what it printed:

```bash
php artisan --version                                          # app boots
git diff --name-only --diff-filter=d HEAD^1 HEAD | grep '\.php$' | \
  xargs -P8 -n1 php -l | grep -v 'No syntax errors'            # must be empty
```

`--diff-filter=d` matters: upstream deletes files in these merges, and linting a path
that no longer exists reports "Could not open input file", which looks like a failure
but is not.

## After the merge

Report these as remaining work rather than doing them unasked:

1. `php artisan migrate` against the target database. Upstream crosses major Laravel
   versions between syncs, so there are usually pending migrations.
2. `npm ci && npm run production` for front-end assets (Laravel Mix).
3. Deploy via `deploy.php`.

Never start a long-running process (`artisan serve`, `horizon`, `npm run watch`) to
verify a merge. Stop at install, lint, and `artisan --version`, and say what is left to
check by hand.

## Things worth flagging to the user

- New upstream packages that get auto-discovered and were not there before, for
  example `laravel/sentinel` appearing in `package:discover` output.
- Upstream dependency removals you accepted, and the evidence that nothing local
  needed them.
- The Laravel version before and after, since that drives the migration risk.
