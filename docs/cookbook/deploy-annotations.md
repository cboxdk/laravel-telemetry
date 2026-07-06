---
title: "Cookbook: Deploy annotations (Forge, Envoyer, CI)"
description: Mark releases with git commit info so regressions map to deploys at a glance
weight: 5
---

# Cookbook: Deploy annotations

`php artisan telemetry:deploy` emits an `app.deployment` event that every
bundled dashboard renders as a purple vertical line — see
[Grafana stack](../production/grafana-stack.md#deploy-annotations) for
what that looks like. `--id` already auto-detects the current git sha
(`Support\GitVersion` — a plain read of `.git/HEAD`/`.git/refs/...`, no
`exec()`, same reason this package never shells out anywhere). `--notes`
has no equivalent auto-fill: getting the commit *message* means parsing
an actual git object, which is frequently delta-packed after the first
`git gc` — not worth reimplementing git's pack format inside a telemetry
package for a nice-to-have.

**Don't reach for `git log`/`git rev-parse` in the deploy script either
— your deploy platform already computed this for you.** Whether `.git`
even survives into the deploy path (zero-downtime builds, release
directories) is an implementation detail you'd otherwise have to track;
Forge's and Envoyer's own deployment variables sidestep the question
entirely.

## Forge

Forge injects deployment metadata as env vars into every deploy script
([full list](https://forge.laravel.com/docs/sites/deployments#environment-variables)),
including exactly the id/notes pair:

| Variable | Contains |
|---|---|
| `FORGE_DEPLOY_COMMIT` | the git sha being deployed |
| `FORGE_DEPLOY_MESSAGE` | the commit message |
| `FORGE_DEPLOY_AUTHOR` | the commit author |
| `FORGE_SITE_BRANCH` | the branch being deployed |

Add this to the bottom of your site's **Deploy Script**, after the app
is actually live (config cache, migrations, `queue:restart`, …):

```bash
$FORGE_PHP artisan telemetry:deploy \
    --id="$FORGE_DEPLOY_COMMIT" \
    --notes="$FORGE_DEPLOY_MESSAGE"
```

No quoting gymnastics needed — these are plain env var expansions, not
command substitutions, so `"$FORGE_DEPLOY_MESSAGE"` passes through as
one argument even if the commit message itself contains a `"`. Want the
author on the annotation too:

```bash
--notes="$FORGE_DEPLOY_MESSAGE (by $FORGE_DEPLOY_AUTHOR)"
```

## GitHub Actions

Same idea, right after whatever step ships the code (SSH, Vapor, Forge's
deploy-hook API):

```yaml
- name: Mark deployment
  run: |
    ssh forge@your-server "cd /home/forge/your-site.com && php artisan telemetry:deploy \
      --id='${{ github.sha }}' \
      --notes='${{ github.event.head_commit.message }}'"
```

`github.sha` and `github.event.head_commit.message` are already exactly
the id/notes pair — no git command needed inside the workflow itself.

## Envoyer

Envoyer has its own [template variables](https://docs.envoyer.io/projects/deployment-hooks)
for deployment hooks — `{{ }}` syntax, not shell env vars, and different
names than Forge's:

| Variable | Contains |
|---|---|
| `{{ sha }}` | the commit hash being deployed |
| `{{ message }}` | the commit message |
| `{{ author }}` | the commit author |
| `{{ release }}` | the current release directory |
| `{{ php }}` | the server's configured PHP executable |

Add a **Deploy Hook** (the "After loading in the console" step, once the
release is live):

```bash
cd {{ release }}
{{ php }} artisan telemetry:deploy --id="{{ sha }}" --notes="{{ message }}"
```

## Verifying it worked

```bash
php artisan telemetry:deploy --id=test --notes="dry run"
```

Check Loki (`{app="your-app"} |= "app.deployment"`) or the `deployments`
counter — both update immediately, since `telemetry:deploy` flushes
synchronously rather than waiting for the next scheduled
`telemetry:flush`.
