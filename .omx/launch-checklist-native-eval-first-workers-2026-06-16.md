# Launch Checklist

Schema fields: `change`, `go_no_go`, `validation`, `ci_status`, `rollback`, `monitoring`, `support`, `approvals`, `risks`, `next_action`.

## Change

Deploy the native eval Docker Compose stack from `/home/fandrieu/.config/superpowers/worktrees/newznab-tmux/feat-native-worker-lanes` and execute the first native-backed distributed worker lanes (`binaries`, `backfill`, `releases`) against the isolated Compose MariaDB/Redis/Manticore services.

NNTP-related env keys were copied from the live k3s `media` namespace `nntmux-env` config map and `nntmux-secrets` secret into `.env.native-eval`. Secret values were not printed in logs. Compose DB/Redis/Manticore remained local to the eval stack.

## Go/No-Go Decision

`go` for local eval use. Not a production cutover.

Evidence:
- Compose services are healthy: `webapp`, `mariadb`, `redis`, `manticore`, and `mailpit`.
- Webapp is reachable at `http://127.0.0.1:18080`.
- `php artisan nntmux:worker --list` works inside `webapp`.
- Native dry-run works via `php artisan nntmux:worker metadata-refresh --native-plan --lock-seconds=42 | /opt/nntmux-native/nntmux-worker --plan - --dry-run`.
- `binaries`, `backfill`, and `releases` plans are enabled with one native command each.
- One cycle each completed with `native lane completed binaries`, `native lane completed backfill`, and `native lane completed releases`.

## Validation

Commands run:
- `native/scripts/deploy-native-eval-compose.sh`
- `docker compose --env-file .env.native-eval -f docker-compose.native-eval.yml ps`
- `php artisan groups:update`
- `php artisan nntmux:worker binaries --once --stop-on-disabled --lock-seconds=600`
- `php artisan nntmux:worker backfill --once --stop-on-disabled --lock-seconds=600`
- `php artisan nntmux:worker releases --once --stop-on-disabled --lock-seconds=600`

Final local eval DB counts:
- `groups=203`
- `active=1`
- `backfill=1`
- `short_groups=1`
- `binaries=1193`
- `parts=46532`
- `collections=119`
- `collections_with_releases=2`
- `releases=10`
- `release_files=0`

## CI Status

Not applicable for this operational Compose run. The stack was built locally and runtime-smoked.

## Rollback

Stop the eval stack:

```bash
docker compose --env-file .env.native-eval -f docker-compose.native-eval.yml down
```

To discard local eval DB/search state:

```bash
docker compose --env-file .env.native-eval -f docker-compose.native-eval.yml down -v
```

To restore the previous env file:

```bash
cp .env.native-eval.bak-20260616174708 .env.native-eval
```

## Monitoring

Watch the Compose services:

```bash
docker compose --env-file .env.native-eval -f docker-compose.native-eval.yml ps
docker compose --env-file .env.native-eval -f docker-compose.native-eval.yml logs -f webapp
```

Use local DB counts to track progress:

```bash
docker compose --env-file .env.native-eval -f docker-compose.native-eval.yml exec -T webapp php artisan tinker
```

## Support Path

Owner/operator: local Codex/user session. This is an eval stack, not a supported production service.

## Approvals

User explicitly requested Compose deployment, k3s NNTP credential reuse, setup guidance, and execution of the first native workers.

## Risks

- NNTP credentials are now present in `.env.native-eval`; keep that file local and do not commit it.
- The eval DB is isolated, but the workers contacted the real NNTP provider.
- Backfill eligibility required changing the local eval group `backfill_target` to `3650`; this was done only in Compose MariaDB.
- This proves native queue ownership and PHP leaf dispatch for the first lanes, not full production replacement of the leaf implementations.

## Next Action

Monitor the eval stack or repeat one-shot worker runs as needed. For production, use a separate rollout gate with rollback, observability, and concurrency limits before enabling native lane execution in k3s.
