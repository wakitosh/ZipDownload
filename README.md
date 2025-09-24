 # ZipDownload Module for Omeka S

 This module streams ZIP archives of selected media for an item using ZipStream-PHP and an IIIF-first strategy. It is intended for large exports where building a whole archive on-disk is undesirable.

 ## Features

 - Streams ZIP files on-demand using ZipStream-PHP (bundled in module vendor).
 - Prefers IIIF-rendered images if available, then local original files, then large thumbnails.
 - Provides server-side progress via a per-download token and temp-file JSON records.
 - Conservative server-side limits to avoid overloading memory/IO-heavy services.

 ## Endpoints

 - POST /zip-download/item/:id — start streaming a ZIP for item `:id` (POST `media_ids`, `progress_token`, optional `estimated_total_bytes`/`estimated_file_count`).
 - GET /zip-download/status?token=TOKEN — read progress JSON for token.
 - POST/GET /zip-download/estimate — best-effort estimate of `total_bytes` and `total_files` for given `media_ids`.

 ## Usage

 ### Client flow

 1. Call `/zip-download/estimate` with `media_ids` (csv or array) to get `total_bytes` and `total_files`.
 2. Generate a `progress_token` (random string) and POST to `/zip-download/item/:id` with `media_ids=...` and `progress_token=TOKEN` to start streaming.
 3. Poll `/zip-download/status?token=TOKEN` to get `status`, `bytes_sent`, `total_bytes`, and `started_at`.

 ### curl examples

 ```bash
 # Estimate
 curl -X POST "http://your-site/zip-download/estimate" -d "media_ids=1,2,3"

 # Start streaming (client should send token and handle binary response)
 curl -X POST "http://your-site/zip-download/item/123" -d "media_ids=1,2,3" -d "progress_token=tok123" -o download.zip

 # Poll status
 curl "http://your-site/zip-download/status?token=tok123"
 ```

 ## Server-side limits and tuning

 The module sets conservative defaults to avoid overloading typical production instances (config in `ZipController`):

 - `MAX_CONCURRENT_DOWNLOADS_GLOBAL` = 1
 - `MAX_BYTES_PER_DOWNLOAD` = 3GB
 - `MAX_TOTAL_ACTIVE_BYTES` = 6GB
 - `MAX_FILES_PER_DOWNLOAD` = 1000
 - `PROGRESS_TOKEN_TTL` = 7200 (seconds)

 Adjust these constants inside `modules/ZipDownload/src/Controller/ZipController.php` to match your server capacity.

 ## Notes and caveats

 - The module uses filesystem temp files (`sys_get_temp_dir()`) for progress state. On multi-instance deployments, consider replacing this with a shared store (Redis, DB) for consistent global limits.
 - `estimateAction` tries metadata file_size, local filesystem sizes, then IIIF HEAD requests (short timeouts) to improve accuracy.
 - The module disables `zlib.output_compression` for streaming; ensure PHP output buffering and any reverse-proxy buffering are configured appropriately.

 ## Security

 - Only media belonging to the specified item id are included.
 - The module will reject requests exceeding configured size or file count limits with 413/429.

 ## Troubleshooting

 - If downloads are rejected, check logs and consider increasing limits or reducing requested media per ZIP.
 - Check webserver/proxy timeouts; long-running streams can be interrupted by short proxy timeouts.

 ## Development / Hints

 - For multi-server deployments, replace the temp-file progress store with a central store and implement distributed semaphores for concurrent-download limits.
 - Consider offloading heavy IIIF requests to an internal image proxy if Cantaloupe or other image servers are under load.

 ## License

 Same license as the host Omeka S installation.
