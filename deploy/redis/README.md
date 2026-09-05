# Redis on the app server

Redis is the queue (`QUEUE_CONNECTION=redis`): every queued notification, payslip PDF
and scheduled report waits in it until a worker takes it. Redis holds that in memory.
Whether it also holds it on disk is a **server** setting the application cannot make
for you — and with neither setting below, a Redis restart empties the queue with no
error anywhere. The health check **Redis persistence** reports which state you are in.

## What to set

In `redis.conf` (usually `/etc/redis/redis.conf`):

```
appendonly yes
appendfsync everysec
```

`appendonly` writes every command to an append-only file that is replayed on start,
so a restart brings the queue back intact. `everysec` is the usual trade: at most one
second of jobs at risk, at a fraction of the cost of syncing every write.

RDB snapshots (`save 3600 1 300 100 60 10000`) are a weaker fallback — a restart loses
whatever was queued since the last snapshot. The check warns on RDB-only and fails on
neither.

Then `sudo systemctl restart redis` (or `redis-server`), and verify:

```
redis-cli INFO persistence | grep aof_enabled     # aof_enabled:1
```

## Why this box and not the app

Persistence is a property of the Redis process, not of anything Laravel configures.
The health check reads `INFO persistence` and `CONFIG GET save` from the running
server so the panel says what is actually true, but changing it is done here.
