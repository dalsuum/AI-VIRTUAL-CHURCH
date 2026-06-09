"""Object storage for generated media, with two interchangeable backends.

The worker generates a file (narration mp3, Suno track) and the *browser* later
fetches it, so we need a URL the browser can reach. Two ways to provide one:

* **Local** (single-machine dev): write the file into a directory the Laravel app
  already serves over HTTP, and hand back a `LOCAL_MEDIA_URL/...` link. No S3, no
  credentials. Enabled by setting `LOCAL_MEDIA_DIR`.
      LOCAL_MEDIA_DIR  — filesystem dir Laravel serves (e.g. backend/storage/app/public)
      LOCAL_MEDIA_URL  — public base URL for that dir (e.g. http://localhost:8000/storage)

* **S3** (production): worker and web server are different hosts and can't share a
  disk, so the file goes to S3-compatible object storage and we hand back a
  presigned URL. Used whenever `LOCAL_MEDIA_DIR` is unset.

The same upload_bytes()/presign() pair backs both, so callers (narrator, Suno) never
care which is active.
"""

from __future__ import annotations

import os

_LOCAL_DIR = os.getenv("LOCAL_MEDIA_DIR")


def _is_local() -> bool:
    return bool(_LOCAL_DIR)


# --- S3 backend (lazy: importing this module must not require S3 creds) ----------
_s3 = None


def _client():
    global _s3
    if _s3 is None:
        import boto3

        _s3 = boto3.client(
            "s3",
            endpoint_url=os.getenv("S3_ENDPOINT"),      # OCI namespace endpoint or None for AWS
            aws_access_key_id=os.environ["S3_ACCESS_KEY"],
            aws_secret_access_key=os.environ["S3_SECRET_KEY"],
            region_name=os.getenv("S3_REGION", "me-dubai-1"),
        )
    return _s3


def upload_bytes(key: str, data: bytes, content_type: str) -> str:
    if _is_local():
        path = os.path.join(_LOCAL_DIR, key)
        os.makedirs(os.path.dirname(path), exist_ok=True)
        with open(path, "wb") as f:
            f.write(data)
        return key
    _client().put_object(Bucket=os.environ["S3_BUCKET"], Key=key, Body=data, ContentType=content_type)
    return key


def presign(key: str, expires: int = 3600) -> str:
    if _is_local():
        # Already a real, directly-fetchable URL — local files don't expire.
        base = os.environ["LOCAL_MEDIA_URL"].rstrip("/")
        return f"{base}/{key}"
    return _client().generate_presigned_url(
        "get_object",
        Params={"Bucket": os.environ["S3_BUCKET"], "Key": key},
        ExpiresIn=expires,
    )
