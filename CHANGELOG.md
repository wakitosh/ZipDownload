# Changelog

## Unreleased

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