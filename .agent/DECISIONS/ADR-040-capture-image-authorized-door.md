# ADR-040

## Date
2026-09-08

## Context
Gap E1: capture images (bottle photos) persisted correctly to the
PRIVATE `local` disk as audit artifacts (spec §14), but the EcoStation
hub could only show metadata — the owner could not SEE the latest
capture. The images may contain students (minors): a public disk or a
signed-URL scheme that leaks would be a privacy failure, not just a
bug.

## Decision
One authorized door: `GET /api/v1/admin/captures/{deposit}/image`
(admin-only). It streams the stored bytes from the private disk
(`Storage::disk('local')->response(...)`) with
`Cache-Control: private, max-age=60`, 404 when the deposit has no
image or the file is gone. The EcoStation page fetches it same-origin
with the session cookie (Sanctum's stateful API middleware); teacher
and student requests 403 at the role wall BEFORE any byte of the file
is read. The private disk stays private — no copies, no public
symlinks, no presigned URLs.

## Alternatives Considered
- Copy images to the public disk on classify — rejected: audit
  artifacts containing minors on a web-served path; also duplicates
  storage and desyncs on deletion.
- Time-limited signed URLs — rejected (for now): no queue/token
  infrastructure justified by ONE consumer (the admin hub); a signed
  URL adds a second surface to rotate.
- Base64-embed in a JSON API — rejected: bloats payloads, breaks
  browser caching, complicates the honest 404 story.

## Reasoning
The single-consumer reality (the admin EcoStation desk) makes one
role-guarded route the smallest honest surface. `private` cache
control keeps shared/proxy caches out of student-adjacent bytes. The
404 branch keeps "image missing on disk" honest instead of serving a
placeholder that would look like a real capture.

## Consequences
- Any future consumer (teacher view? parent report?) must extend this
  ADR, not bypass it — the route stays the only door.
- Deposit rows without image_path keep the honest private-storage note
  (spec §14 preserved).
- CaptureImageTest pins: admin streams bytes + private cache header,
  404 for missing file, 403 teacher/student, 401 guest, 404 unknown.

## Status
ACTIVE
