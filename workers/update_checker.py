"""
Standalone update checker. Reads installed pip package versions, queries PyPI for
latest releases, checks systemd service statuses, and reports git state. Writes a
JSON snapshot to /tmp/aivc_update_status.json and also prints it to stdout.

Run directly: python update_checker.py
Or via the Laravel queue job: RunUpdateCheck dispatches it as a subprocess.
"""
from __future__ import annotations

import json
import os
import subprocess
import sys
import time
import urllib.request
import urllib.error

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
PROJECT_DIR = os.path.dirname(SCRIPT_DIR)          # /opt/ai-church
VENV_DIR    = os.path.join(SCRIPT_DIR, ".venv")
PIP_BIN     = os.path.join(VENV_DIR, "bin", "pip")
CACHE_FILE  = "/tmp/aivc_update_status.json"

# Key packages to track versions for (subset of requirements.txt + extras).
KEY_PACKAGES = [
    "edge-tts",
    "anthropic",
    "celery",
    "redis",
    "requests",
    "torch",
    "transformers",
    "httpx",
    "fastapi",
    "uvicorn",
    "boto3",
    "scipy",
]

# Systemd services whose active/inactive status we surface.
SERVICES = [
    "aivc-workers",
    "aivc-workers-music",
    "aivc-bridge",
    "aivc-queue",
    "aivc-scheduler",
    "aivc-tedim-api",
    "aivc-burmese-api",
    "redis-server",
    "nginx",
]


def _run(cmd: list[str], timeout: int = 8) -> str:
    try:
        return subprocess.check_output(
            cmd, text=True, timeout=timeout,
            stderr=subprocess.DEVNULL,
        ).strip()
    except Exception:
        return ""


def get_installed_versions() -> dict[str, str]:
    versions: dict[str, str] = {}
    for pkg in KEY_PACKAGES:
        raw = _run([PIP_BIN, "show", pkg])
        for line in raw.splitlines():
            if line.startswith("Version:"):
                versions[pkg] = line.split(":", 1)[1].strip()
                break
    return versions


def get_pypi_latest(pkg: str) -> str | None:
    try:
        url = f"https://pypi.org/pypi/{pkg}/json"
        req = urllib.request.Request(url, headers={"User-Agent": "aivc-update-checker/1.0"})
        with urllib.request.urlopen(req, timeout=6) as resp:
            data = json.loads(resp.read())
            return data["info"]["version"]
    except Exception:
        return None


def get_service_statuses() -> dict[str, str]:
    statuses: dict[str, str] = {}
    for svc in SERVICES:
        result = _run(["systemctl", "is-active", svc], timeout=4)
        statuses[svc] = result or "unknown"
    return statuses


def get_git_info(pull: bool = False) -> dict:
    info: dict = {"commit": "unknown", "message": "", "branch": "unknown", "behind": 0, "pull_output": ""}
    try:
        if pull:
            out = _run(["git", "-C", PROJECT_DIR, "pull", "--ff-only"], timeout=30)
            info["pull_output"] = out

        info["branch"] = _run(["git", "-C", PROJECT_DIR, "branch", "--show-current"]) or "unknown"
        log = _run(["git", "-C", PROJECT_DIR, "log", "--oneline", "-1"])
        if log:
            parts = log.split(" ", 1)
            info["commit"] = parts[0]
            info["message"] = parts[1] if len(parts) > 1 else ""

        # Fetch quietly then count how many commits behind origin we are.
        _run(["git", "-C", PROJECT_DIR, "fetch", "--quiet", "--no-tags"], timeout=15)
        branch = info["branch"]
        behind_raw = _run(["git", "-C", PROJECT_DIR, "rev-list", "--count", f"HEAD..origin/{branch}"])
        info["behind"] = int(behind_raw) if behind_raw.isdigit() else 0
    except Exception:
        pass
    return info


def build_report(*, pull: bool = False) -> dict:
    installed = get_installed_versions()
    packages: dict[str, dict] = {}
    for pkg in KEY_PACKAGES:
        current = installed.get(pkg)
        if not current:
            continue
        latest = get_pypi_latest(pkg)
        packages[pkg] = {
            "current": current,
            "latest": latest,
            "update_available": latest is not None and latest != current,
        }

    return {
        "checked_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        "checking": False,
        "packages": packages,
        "services": get_service_statuses(),
        "git": get_git_info(pull=pull),
    }


def write_cache(data: dict) -> None:
    tmp = CACHE_FILE + ".tmp"
    with open(tmp, "w") as f:
        json.dump(data, f)
    os.replace(tmp, CACHE_FILE)


def main() -> None:
    pull = "--pull" in sys.argv
    report = build_report(pull=pull)
    write_cache(report)
    print(json.dumps(report, indent=2))


if __name__ == "__main__":
    main()
