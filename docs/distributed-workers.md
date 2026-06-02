# Distributed Workers

NNTmux can run processing lanes without tmux by starting one long-running
Artisan worker per former tmux pane:

```bash
php artisan nntmux:worker --list
php artisan nntmux:worker binaries
php artisan nntmux:worker backfill
```

Each worker resolves the current site settings on every cycle, runs the same
Artisan commands used by the tmux monitor, then sleeps for the configured pane
timer. This makes settings changed in the Web UI effective on the next cycle
without restarting the pod.

## Jobs

| Job | Purpose |
| --- | --- |
| `binaries` | Download new headers for active groups. |
| `backfill` | Safely backfill enabled groups. This always uses `multiprocessing:safe backfill`. |
| `releases` | Create and categorize releases from complete collections. |
| `fixnames` | Run release name-fixing passes. |
| `hashed-fixnames` | Run full-history name-fixing passes for `Other > Hashed` when the count exceeds 100. |
| `removecrap` | Remove releases matched by configured cleanup rules. |
| `post-additional` | Download/process additional files and NFOs. |
| `post-tv` | Run TV and anime post-processing. |
| `post-movies` | Run movie post-processing. |
| `post-amazon` | Run books, music, console, and games post-processing. |
| `irc` | Run the IRC scraper. |
| `per-group` | Run the sequential per-group worker when sequential mode 2 is enabled. |

## Locking

Workers use Laravel cache locks as a final guard against two pods running the
same lane at the same time. In orchestrated deployments, keep one replica per
lane and use a `Recreate` rollout strategy as the primary duplicate-processing
control. Configure the lock store and TTLs with:

```env
NNTMUX_DISTRIBUTED_LOCK_STORE=redis
NNTMUX_DISTRIBUTED_LOCK_SECONDS=900
NNTMUX_DISTRIBUTED_LONG_LOCK_SECONDS=3600
```

`irc` can use a different TTL, but keep it bounded. A pod killed during a
rollout cannot release its cache lock, so very long TTLs can leave the lane idle
until the key expires or an operator clears it. Locks do not heartbeat while a
long Artisan command is running, so do not scale one lane above one replica.

The `hashed-fixnames` lane uses the normal `fix_timer` sleep. It is disabled
unless `fix_names` is enabled and the exact `Other > Hashed` count is greater
than 100. It runs the hashed-only selector, while the regular `fixnames`
worker excludes `Other > Hashed` to avoid duplicate rename attempts.

## Kubernetes Pattern

A Kubernetes deployment should run one pod per job:

```yaml
args: [php, artisan, nntmux:worker, backfill]
```

Use ReadWriteMany storage for `storage`, temporary resources, covers, and any
shared install state. Use preferred pod anti-affinity and `ScheduleAnyway`
topology spread constraints to distribute load without pinning the image to a
single CPU architecture.

Do not run the legacy tmux worker and distributed workers at the same time
unless you intentionally want duplicate processing. During cutover, scale the
legacy tmux worker to zero before applying the distributed worker deployments.

## Monitoring

Watch a lane directly through pod logs:

```bash
kubectl -n media logs -f deploy/nntmux-worker-backfill
```

For backfill, `first_record` should move backward over time:

```bash
php artisan tinker --execute='use Illuminate\Support\Facades\DB; $g=DB::table("usenet_groups")->where("name","alt.binaries.movies")->first(); echo now()."\t".$g->name."\tfirst=".$g->first_record."\tfirst_post=".$g->first_record_postdate."\n";'
```

The Web UI group count can remain unchanged while article cursors are moving;
that count reflects eligible groups, not per-article progress.
