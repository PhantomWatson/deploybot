# 🤖 Phantom Deploy-bot 🤖

A cold, unfeeling, mechanical monster, hell-bent on overseeing the automatic deployment of various websites. 


## How it works

1. GitHub sends a push webhook to `webroot/index.php`, which verifies the signature and hands off to `App\Webhook`.
2. `Webhook` validates the repository and branch, writes a job file to `jobs/`, starts `scripts/deploy-worker.php` in the background, and responds `202` immediately.
3. The worker runs `App\Deploy`, which runs the site's commands from `config/sites.php`, posts the results to Slack, and appends them to `logs/<repo>.html`.

### Overlapping deployments

Each site directory has a lock in `locks/`. If a push arrives while that site is deploying, it leaves a marker and exits. When the running deployment finishes, it deploys once more, so any number of pushes during a deployment result in one extra deployment.

### Skipping builds

A command in `config/sites.php` can be an array with a `paths` list (see the `$build` helper). It only runs if something in those paths has changed since it last *succeeded*, which is recorded per site in `state/`. If anything is uncertain (no record, an unknown commit, a git error), the command runs. Failed commands are never recorded, so they run again next time. Add every directory a build reads from to its `paths`, or it may be skipped when it shouldn't be. To force a rebuild, delete the site's file in `state/`.

### Configuration

- `config/app.php`: the CLI PHP binary and the directory that contains the sites. Override values for local development in `config/app.local.php` (not tracked).
- `config/sites.php`: sites, branches, and deployment commands.
- `config/slack_webhook_urls.php`, `config/github_secret.php`, `config/allowed_ip_addresses.php`: not tracked.

`jobs/`, `locks/`, `logs/` and `state/` are created as needed and must be writable by the web server user.
