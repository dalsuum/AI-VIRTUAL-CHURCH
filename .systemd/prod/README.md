# Production systemd units (single-droplet deploy)

System-level ports of the local `--user` units, for the DigitalOcean deploy in
[`/DEPLOY.md`](../../DEPLOY.md). They assume the app lives at `/opt/ai-church`
and runs as the `simon` user (see DEPLOY.md §2, §6).

Unlike local dev, the **HTTP layer is nginx + php-fpm** (not `php artisan serve`),
so there is no `backend` app unit here — only the four background processes:

| Unit                    | Process                                   |
|-------------------------|-------------------------------------------|
| `aivc-queue.service`    | `php artisan queue:work`                  |
| `aivc-scheduler.service`| `php artisan schedule:work`               |
| `aivc-workers.service`  | Celery (`ai:sermon,ai:music,ai:avatar,ai:narration`) |
| `aivc-bridge.service`   | `python bridge.py` (Redis `ai:intake` → Celery) |

## Install

```bash
sudo cp /opt/ai-church/.systemd/prod/aivc-*.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now aivc-queue aivc-scheduler aivc-workers aivc-bridge
sudo systemctl status  aivc-queue aivc-scheduler aivc-workers aivc-bridge --no-pager
```

If your app path or user differ from `/opt/ai-church` / `simon`, edit the
`WorkingDirectory` and `User`/`Group` lines before copying.
