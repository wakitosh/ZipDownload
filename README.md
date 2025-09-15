# ZipDownload for Omeka S

Server-side ZIP builder for selected media on an item page. Local file store only; when an original is not available and the media is IIIF-backed, the module fetches the full-resolution image from the remote IIIF server.

Features
- Streams a ZIP with safe headers (no gzip) and RFC 5987 filename.
- Prioritizes local originals; falls back to IIIF full, then large thumbnail.
- IIIF v2/v3 parsing, info.json probing, robust candidate URLs, retries.
- Debug headers (X-Zip-*) and logging for observability.

Routes
- Global: /zip-download/item/:id
- Site child: /s/:site/zip-download/item/:id

Usage
- Frontend posts a comma-separated list of media IDs as `media_ids`.
- Response is a streamed ZIP file.

Limitations
- No S3/external stores.
- Authentication/authorization uses Omeka S permissions.

License
- GPL-3.0