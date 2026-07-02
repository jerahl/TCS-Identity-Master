# Swap the dev box git checkout back to `main`

The dev box runs the app straight from a git checkout of this repo (see
[`../scripts/setup-dev-debian12.sh`](../scripts/setup-dev-debian12.sh)). When a
feature branch has been checked out on the box for testing, use this runbook to
return the checkout to the `main` branch.

> Run these on the **dev box**, from the repo directory (the `WorkingDirectory`
> of the systemd units — e.g. `/opt/tcs-identity` or wherever it was cloned).
> `.env`, `vendor/`, and `db/seeds/*.csv` are gitignored, so switching branches
> never touches your local config, dependencies, or seed data.

## 1. Check where you are

```sh
cd /opt/tcs-identity           # <- your repo path
git status                     # current branch + any local changes
git branch --show-current      # just the branch name
```

If `git status` shows **uncommitted changes** you want to keep, stash or commit
them first — the checkout below refuses to clobber unsaved work:

```sh
git stash push -m "dev-box wip before switching to main"
# ...or discard them entirely if they're throwaway test edits:
# git checkout -- . && git clean -fd     # DESTRUCTIVE: drops all local changes
```

## 2. Switch to main and fast-forward

```sh
git fetch origin
git checkout main
git pull --ff-only origin main
```

`--ff-only` keeps the pull honest: it updates `main` only if it can
fast-forward, so you never get a surprise merge commit on the box. If it refuses
because the local `main` has drifted, reset it to the remote:

```sh
git reset --hard origin/main   # DESTRUCTIVE: local main == origin/main exactly
```

## 3. Reinstall deps and apply any new migrations

Switching branches can change `composer.lock` and add migrations, so re-sync
both before trusting the app:

```sh
composer install --no-interaction --prefer-dist
php bin/migrate.php --status    # see what's pending
php bin/migrate.php             # apply any new migrations
```

## 4. Verify

```sh
git branch --show-current       # -> main
git log --oneline -3            # confirm you're at the expected commit
php bin/migrate.php --status    # -> no pending migrations
composer test                   # optional: run the suite
```

If the app runs under php-fpm/nginx, no restart is needed (PHP reads the files
per request), but bounce php-fpm if you have OPcache on:

```sh
sudo systemctl reload php8.2-fpm    # only if OPcache is enabled
```

## Quick copy-paste (no local changes to preserve)

```sh
cd /opt/tcs-identity
git fetch origin
git checkout main
git reset --hard origin/main
composer install --no-interaction --prefer-dist
php bin/migrate.php
git branch --show-current    # -> main
```

> `git reset --hard` throws away any uncommitted edits and local commits on the
> current branch. Only use the copy-paste block when the dev box is a scratch
> checkout with nothing worth keeping.
