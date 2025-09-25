# Changelog

## 0.2.4 (2025-09-26)

- i18n: Localize server messages to Japanese when the site locale is set to JA, without relying on ext/intl.
	- Localized 429 errors (download slots busy, total active bytes exceeded).
	- Localized 413 errors (requested size too large, too many files).
	- Localized common jsonError messages (e.g., "No media selected", "Missing token", "Token not found").
	- Added lightweight helpers: `currentLocaleIsJa()` and `translateMessage()`.
	- JSON schema unchanged (keys are stable: `error`, `retry_after`, etc.).

## 0.2.3 (2025-09-26)

- Endpoints: Add explicit `POST /zip-download/cancel` entry to docs and wire up site-scoped routes (`/s/:site-slug/zip-download/...`) so site context works consistently.
- Client UX: Clarify that the client can cancel in-flight ZIP builds and will not fall back to individual downloads on failure/cancel.
- Settings: Reiterate that server-side limits are configurable via the admin UI and accept human-friendly byte sizes (K/KB, M/MB, G/GB, T/TB).

## 0.2.2 (2025-09-25)

- Feature: Admin settings now accept human-friendly byte sizes for limits (e.g., 512M, 1G, 10G); also supports K/KB, M/MB, G/GB, T/TB.
- UX: Updated labels/placeholders in the settings form to indicate supported size suffixes.
- Dev: Fixed and normalized ConfigForm indentation/array alignment to satisfy linter.

## 0.2.1 (2025-09-25)

- Fix: Ensure client cancel reliably marks progress token as canceled and prevents accidental fallback to individual downloads.
- Fix: Preserve canceled state when progress files are written concurrently; use atomic write and locking to avoid races.
- Fix: Template and client JS updates to avoid stale cached JS causing fallback behavior (cache-busted asset path).

## 0.2.0 (2025-09-24)

- Rewrote ZipDownload streaming implementation to use ZipStream-PHP and stream archives without building a local temp ZIP.
- Added IIIF-first strategy: prefer IIIF-rendered images when available, fallback to local original, then large thumbnail.
- Added server-side progress tokens stored as temp JSON files and endpoints:
	- GET /zip-download/status?token=TOKEN
	- POST /zip-download/estimate
- Implemented conservative server-side limits to protect memory/IO-heavy services.
- Client-side theme JS added to generate progress token, POST with token, and poll status for ETA and progress UI.
- Added started_at to progress records so client can compute ETA from server-side start time.

## 0.1.0 (2025-09-16)

- Initial repository import.
- Streamed ZIP response with safe headers and cleanup.
- Local originals prioritized; IIIF full-resolution fallback; large thumbnail as last resort.
- IIIF v2/v3 parsing, info.json probing, candidate URL generation, retries.
- Added X-Zip-* debug headers and structured logging.

## 2025-09-24

- Minor fixes and cleanup: ensure progress writes include started_at; fix indentation and lint issues in controller.