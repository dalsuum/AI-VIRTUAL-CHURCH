# Production systemd units (single-droplet deploy)

System-level ports of the local `--user` units, for the DigitalOcean deploy in
[`/DEPLOY.md`](../../DEPLOY.md). They assume the app lives at `/opt/ai-church`
and runs as the `simon` user (see DEPLOY.md §2, §6).

Unlike local dev, the **HTTP layer is nginx + php-fpm** (not `php artisan serve`),
so there is no `backend` app unit here — only the five background processes:

| Unit                          | Process                                              |
|-------------------------------|------------------------------------------------------|
| `aivc-queue.service`          | `php artisan queue:work`                             |
| `aivc-scheduler.service`      | `php artisan schedule:work`                          |
| `aivc-workers.service`        | Celery (`ai:sermon,ai:avatar,ai:narration`, `-c 2`)  |
| `aivc-workers-music.service`  | Celery (`ai:music`, `-c 1`, MusicGen isolated)       |
| `aivc-bridge.service`         | `python bridge.py` (Redis `ai:intake` → Celery)      |

> **Why two worker units?** MusicGen loads a ~300 MB model + ~2 GB PyTorch overhead per
> process. With `-c 4` all on one unit, all 4 workers cached the model simultaneously
> (~8 GB), exhausting the server's 8 GB RAM and causing everything to time out.
> The music unit uses `-c 1` so the model loads exactly once.

## Install

```bash
sudo cp /opt/ai-church/.systemd/prod/aivc-*.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now aivc-queue aivc-scheduler aivc-workers aivc-workers-music aivc-bridge
sudo systemctl status  aivc-queue aivc-scheduler aivc-workers aivc-workers-music aivc-bridge --no-pager
```

If your app path or user differ from `/opt/ai-church` / `simon`, edit the
`WorkingDirectory` and `User`/`Group` lines before copying.
