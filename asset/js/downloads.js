// Full-feature ZipDownload client JS centralized in the module
(function () {
    if (window.__ZipDownloadInited) { return; }
    window.__ZipDownloadInited = true;
    function t(panel, keyJa, keyEn) {
        const loc = (panel.getAttribute('data-locale') || '').toLowerCase();
        const isJa = loc.startsWith('ja');
        return isJa ? keyJa : keyEn;
    }
    function qs(el, sel) { return el.querySelector(sel); }
    function qsa(el, sel) { return Array.from(el.querySelectorAll(sel)); }
    function notify(panel, msg, timeout = 7000) {
        try {
            let container = qs(panel, '.download-panel__notify');
            if (!container) {
                const progress = qs(panel, '.download-panel__progress');
                if (progress) {
                    container = document.createElement('div');
                    container.className = 'download-panel__notify';
                    container.style.margin = '8px 0';
                    progress.parentNode.insertBefore(container, progress.nextSibling);
                }
            }
            if (container) {
                const note = document.createElement('div');
                note.className = 'download-panel__notify__item';
                note.textContent = String(msg);
                note.style.padding = '8px';
                note.style.background = '#fff3cd';
                note.style.border = '1px solid #ffeeba';
                note.style.borderRadius = '4px';
                note.style.color = '#856404';
                note.style.marginBottom = '6px';
                container.appendChild(note);
                setTimeout(() => { try { note.remove(); } catch (e) { } }, timeout);
                return;
            }
        } catch (e) { }
        try { alert(String(msg)); } catch (e) { }
    }
    function findRowByMediaId(panel, id) {
        return qsa(panel, '.download-panel__check').find(b => String(b.getAttribute('data-media-id')) === String(id));
    }
    function toggleAll(panel) {
        const boxes = qsa(panel, '.download-panel__check');
        const allChecked = boxes.every(b => b.checked);
        boxes.forEach(b => b.checked = !allChecked);
        updateState(panel);
    }
    function updateState(panel) {
        const agree = qs(panel, '.download-panel__agree-check');
        const anyChecked = qsa(panel, '.download-panel__check').some(b => b.checked);
        const btn = qs(panel, '[data-action="download"]');
        if (btn) btn.disabled = !(agree && agree.checked && anyChecked);
    }
    function blobSave(blob, filename) {
        const a = document.createElement('a');
        const url = URL.createObjectURL(blob);
        a.href = url; a.download = filename || 'download';
        document.body.appendChild(a); a.click();
        setTimeout(() => { URL.revokeObjectURL(url); a.remove(); }, 0);
    }
    async function fetchAsBlob(url) {
        // Use no credentials for cross-origin image fetches to satisfy CORS when ACAO is '*'.
        const res = await fetch(url, { credentials: 'omit' });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        return await res.blob();
    }
    async function downloadSelected(panel) {
        const boxes = qsa(panel, '.download-panel__check').filter(b => b.checked);
        if (boxes.length === 0) return;
        const mediaIds = boxes.map(b => b.getAttribute('data-media-id')).filter(Boolean);
        const progress = qs(panel, '.download-panel__progress');
        const title = (panel.getAttribute('data-item-title') || 'item').trim();
        const endpoint = panel.getAttribute('data-zip-endpoint');
        const itemId = panel.getAttribute('data-item-id');
        if (!itemId) return;

        progress.textContent = t(panel, 'ZIPを作成しています…', 'Building ZIP…');
        function normalizeUrl(u) { if (!u) return ''; return String(u).trim().replace(/\s+/g, ''); }

        const estimateEndpointRaw = panel.getAttribute('data-zip-endpoint') || '/zip-download/estimate';
        const estimateUrl = normalizeUrl(estimateEndpointRaw).replace(/\/item\/\d+\/?$/i, '/estimate') || '/zip-download/estimate';
        let estimated = null;
        let userCanceled = false;
        try {
            const estRes = await fetch(estimateUrl, {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                body: new URLSearchParams({ media_ids: mediaIds.join(',') }).toString(),
            });
            if (estRes.ok) estimated = await estRes.json();
        } catch (e) { estimated = null; }
        const totalBytes = (estimated && Number(estimated.total_bytes)) ? Number(estimated.total_bytes) : 0;

        const params = new URLSearchParams();
        params.set('media_ids', mediaIds.join(','));
        const token = (crypto && crypto.randomUUID) ? crypto.randomUUID() : ('t' + Date.now() + Math.random().toString(36).slice(2, 8));
        params.set('progress_token', token);
        if (totalBytes > 0) params.set('estimated_total_bytes', String(totalBytes));

        const epSite = normalizeUrl(panel.getAttribute('data-zip-endpoint-site') || '');
        const epGlobal = normalizeUrl(panel.getAttribute('data-zip-endpoint-global') || '');
        const epExplicit = normalizeUrl(endpoint || '');
        const fallbackItem = `/zip-download/item/${encodeURIComponent(itemId)}`;
        const candidates = [epGlobal, epExplicit, epSite, fallbackItem].map(v => v && String(v)).filter((v, i, arr) => v && arr.indexOf(v) === i);

        let res = null, lastErr = null, lastJsonErr = null, lastRetryAfter = null, chosenUrl = null, fetchController = null;
        for (const url of candidates) {
            try {
                fetchController = new AbortController();
                res = await fetch(url, { method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' }, body: params.toString(), signal: fetchController.signal });
                const ct = String(res.headers.get('content-type') || '');
                if (res.ok && ct.includes('zip')) { chosenUrl = normalizeUrl(url); break; }
                if (ct.includes('application/json')) {
                    try {
                        const j = await res.json();
                        lastJsonErr = j; lastRetryAfter = j.retry_after || null; lastErr = new Error(j.error || j.message || ('Server ' + res.status));
                    } catch (e) { lastErr = new Error('Bad response: ' + res.status + ' ' + ct); }
                } else { lastErr = new Error('Bad response: ' + res.status + ' ' + ct); }
            } catch (e) { lastErr = e; }
            res = null;
        }

        if (!res) {
            if (lastJsonErr) {
                const msg = lastJsonErr.error || lastJsonErr.message || JSON.stringify(lastJsonErr);
                let combined = msg;
                if (lastRetryAfter) {
                    const retryLine = t(panel, `${lastRetryAfter}秒後を目安に再試行してください。`, `Please retry in about ${lastRetryAfter} seconds.`);
                    combined = msg + '\n' + retryLine;
                }
                notify(panel, t(panel, combined, combined));
            } else if (lastErr) {
                notify(panel, t(panel, 'ZIPを作成できません。\nエラー: ' + String(lastErr.message), 'Unable to build ZIP. Error: ' + String(lastErr.message)));
            } else {
                notify(panel, t(panel, 'ZIPを作成できません。時間をおいて再試行してください。', 'ZIP not available. Please try again later.'));
            }
            const btn = qs(panel, '[data-action="download"]'); if (btn) btn.disabled = false;
            progress.textContent = t(panel, 'しばらく経ってから再度お試しください。', 'Please try building ZIP again later');
            return;
        }

        let polling = true; const pollInterval = 1200;
        const pollStatus = async () => {
            try {
                const baseForStatus = chosenUrl || normalizeUrl(panel.getAttribute('data-zip-endpoint') || '/zip-download/status');
                let statusBase = normalizeUrl(baseForStatus).replace(/\/item\/\d+\/?$/i, '/status'); statusBase = statusBase.replace(/%20+/g, '');
                let statusUrl = null;
                try { statusUrl = new URL(statusBase, window.location.origin); statusUrl.searchParams.set('token', token); statusUrl = statusUrl.toString().replace(/%20+/g, ''); }
                catch (e) { statusUrl = statusBase.replace(/\s+/g, '') + '?token=' + encodeURIComponent(token); }
                const r = await fetch(statusUrl, { credentials: 'include' }); if (!r.ok) return null; return await r.json();
            } catch (e) { return null; }
        };

        let smoothedSent = 0, lastUpdate = Date.now();
        const pollLoop = (async () => {
            while (polling) {
                const s = await pollStatus();
                if (s) {
                    const sent = Number(s.bytes_sent || 0); const total = Number(s.total_bytes || totalBytes || 0);
                    const now = Date.now(); const dt = Math.max(1, now - lastUpdate); lastUpdate = now;
                    const alpha = Math.min(0.6, 0.2 + Math.log10(Math.min(1000, dt)) * 0.05);
                    smoothedSent = Math.round(alpha * sent + (1 - alpha) * smoothedSent);
                    if (s.status === 'running') {
                        if (total > 0) {
                            const pct = Math.min(100, Math.round((smoothedSent / total) * 100));
                            const started = Number(s.started_at || 0);
                            let etaText = '';
                            if (started > 0 && smoothedSent > 0 && total > smoothedSent) {
                                const elapsed = Math.max(1, Date.now() / 1000 - started);
                                const speed = smoothedSent / elapsed;
                                const remain = Math.max(0, total - smoothedSent);
                                const eta = Math.round(remain / Math.max(1, speed));
                                const h = Math.floor(eta / 3600);
                                const m = Math.floor((eta % 3600) / 60);
                                const ssec = eta % 60;
                                etaText = h > 0 ? `${h}:${String(m).padStart(2, '0')}:${String(ssec).padStart(2, '0')}` : `${m}:${String(ssec).padStart(2, '0')}`;
                            }
                            progress.textContent = t(panel, `処理中: ${pct}% 予想残り: ${etaText}`, `Processing: ${pct}% ETA: ${etaText}`);
                        } else {
                            progress.textContent = t(panel, `処理中: ${Math.round(smoothedSent / 1024)}KB`, `Processing: ${Math.round(smoothedSent / 1024)}KB`);
                        }
                    } else if (s.status === 'done') {
                        progress.textContent = t(panel, 'ZIP 準備完了。ダウンロード中…', 'ZIP ready. Downloading…');
                        break;
                    } else if (s.status === 'error') {
                        progress.textContent = t(panel, 'ダウンロードに失敗しました。', 'Download failed.'); polling = false; break;
                    }
                }
                await new Promise(r => setTimeout(r, pollInterval));
            }
        })();

        const btn = qs(panel, '[data-action="download"]'); if (btn) btn.disabled = true;
        let cancelBtn = qs(panel, '[data-action="cancel"]'); let createdCancel = false;
        if (!cancelBtn) {
            cancelBtn = document.createElement('button'); cancelBtn.type = 'button'; cancelBtn.setAttribute('data-action', 'cancel'); cancelBtn.textContent = t(panel, 'キャンセル', 'Cancel'); cancelBtn.style.marginLeft = '8px';
            if (btn && btn.parentNode) btn.parentNode.insertBefore(cancelBtn, btn.nextSibling);
            createdCancel = true;
        }
        if (cancelBtn) cancelBtn.disabled = false;
        const onCancel = async () => {
            try {
                const baseForCancel = chosenUrl || panel.getAttribute('data-zip-endpoint') || '/zip-download/cancel';
                const cancelEndpoint = String(baseForCancel).replace(/\/item\/\d+\/?$/i, '/cancel');
                try { if (fetchController && typeof fetchController.abort === 'function') fetchController.abort(); } catch (e) { }
                const resp = await fetch(cancelEndpoint, { method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' }, body: new URLSearchParams({ progress_token: token }).toString() });
                if (resp.ok) { userCanceled = true; polling = false; progress.textContent = t(panel, 'ダウンロードがキャンセルされました。', 'Download canceled.'); }
            } catch (e) { console.error('Cancel request error', e); }
        };
        cancelBtn.addEventListener('click', onCancel);

        try {
            const blob = await res.blob(); polling = false; await pollLoop;
            const safe = title.replace(/\s+/g, ' ').replace(/[\\/:*?"<>|]/g, '_').slice(0, 120) || 'download';
            blobSave(blob, `${safe}.zip`);
        } catch (e) {
            console.error(e);
            try { userCanceled = true; progress.textContent = t(panel, 'ダウンロードは中止されました。', 'Download canceled.'); }
            catch (e2) { console.error(e2); notify(panel, t(panel, 'ZIPの作成に失敗しました。時間をおいて再度お試しください。', 'Failed to build ZIP. Please try again later.')); }
        } finally {
            polling = false; if (btn) btn.disabled = false;
            if (cancelBtn) { cancelBtn.removeEventListener('click', onCancel); if (cancelBtn.parentNode) cancelBtn.remove(); }
            progress.textContent = '';
        }
    }
    function sanitizeName(s, max = 120) { return (String(s || 'file').trim().replace(/\s+/g, ' ').replace(/[\\/:*?"<>|]/g, '_').slice(0, max)) || 'file'; }
    function getRowFullLabel(row) { const span = row.closest('label').querySelector('.download-panel__label'); return (span && span.getAttribute('title')) ? span.getAttribute('title') : (span ? span.textContent.trim() : ''); }
    async function fetchManifest(url) { const res = await fetch(url, { credentials: 'omit', headers: { 'Accept': 'application/json' } }); if (!res.ok) throw new Error('Manifest HTTP ' + res.status); return await res.json(); }
    function pickIiifFromV3(man) { const out = []; const items = Array.isArray(man.items) ? man.items : []; items.forEach(cv => { const label = (cv && cv.label && cv.label.none && Array.isArray(cv.label.none) && cv.label.none.length) ? String(cv.label.none[0]) : ''; const anno = cv && cv.items && cv.items[0] && cv.items[0].items && cv.items[0].items[0]; const body = anno ? (anno.body || {}) : {}; let serviceId = ''; if (body.service) { if (Array.isArray(body.service) && body.service[0]) serviceId = String(body.service[0].id || body.service[0]['@id'] || ''); else if (body.service.id || body.service['@id']) serviceId = String(body.service.id || body.service['@id'] || ''); } const directId = String(body.id || body['@id'] || ''); out.push({ label, serviceId, directId }); }); return out; }
    function pickIiifFromV2(man) { const out = []; const canvases = (man.sequences && man.sequences[0] && Array.isArray(man.sequences[0].canvases)) ? man.sequences[0].canvases : []; canvases.forEach(cv => { let label = ''; if (typeof cv.label === 'string') label = cv.label; else if (cv.label && typeof cv.label === 'object') { const k = Object.keys(cv.label)[0]; if (k && Array.isArray(cv.label[k]) && cv.label[k].length) label = String(cv.label[k][0]); } const img = cv.images && cv.images[0]; const res = img && img.resource ? img.resource : {}; let serviceId = ''; if (res.service) serviceId = String(res.service['@id'] || res.service.id || ''); const directId = String(res['@id'] || res.id || ''); out.push({ label, serviceId, directId }); }); return out; }
    function buildIiifFullUrl(serviceOrDirect) { const base = String(serviceOrDirect || '').replace(/\/$/, ''); if (!base) return ''; return `${base}/full/max/0/default.jpg`; }
    async function downloadIndividually(panel, boxes) {
        const manifestUrl = panel.getAttribute('data-manifest-url') || '';
        const title = (panel.getAttribute('data-item-title') || 'item').trim();
        const progress = qs(panel, '.download-panel__progress');
        const itemSafe = sanitizeName(title);
        let entries = [];
        if (manifestUrl) { try { const man = await fetchManifest(manifestUrl); const isV3 = (man.type === 'Manifest') || (man['@context'] && String(man['@context']).includes('/presentation/3')); entries = isV3 ? pickIiifFromV3(man) : pickIiifFromV2(man); } catch (e) { console.warn('manifest fetch failed', e); } }
        let done = 0;
        for (const b of boxes) {
            const label = getRowFullLabel(b) || 'media';
            let url = '';
            if (entries.length) {
                const found = entries.find(e => String(e.label || '').trim() === String(label).trim());
                const base = (found && (found.serviceId || found.directId)) ? (found.serviceId || found.directId) : '';
                url = buildIiifFullUrl(base);
            }
            if (!url) { url = b.getAttribute('data-url'); }
            if (!url) continue;
            try { const blob = await fetchAsBlob(url); const labelSafe = sanitizeName(label, 80); blobSave(blob, `${itemSafe}_${labelSafe}.jpg`); }
            catch (e) { console.error('download failed', e); }
            done++; if (progress) progress.textContent = t(panel, `ダウンロード中… ${done}/${boxes.length}`, `Downloading… ${done}/${boxes.length}`);
        }
    }
    function extractLabelsFromManifest(man) {
        const isV3 = (man.type === 'Manifest') || (man['@context'] && String(man['@context']).includes('/presentation/3'));
        const byMediaId = new Map(); const byFilename = new Map(); const labelsByOrder = [];
        function getV3CanvasLabelNone(label) { if (!label) return ''; if (label.none && Array.isArray(label.none) && label.none.length) return label.none[0]; return ''; }
        function getV2CanvasLabel(label) { if (!label) return ''; if (typeof label === 'string') return label; const firstKey = Object.keys(label)[0]; if (firstKey && Array.isArray(label[firstKey]) && label[firstKey].length) return label[firstKey][0]; return ''; }
        function parseUrlParts(id) { const out = { mediaId: '', filename: '' }; if (!id) return out; try { const u = new URL(String(id), window.location.origin); out.mediaId = u.searchParams.get('id') || u.pathname.split('/').filter(Boolean).pop(); const path = u.pathname || ''; out.filename = path.split('/').filter(Boolean).pop(); return out; } catch (e) { const str = String(id); const q = str.split('?')[0]; const seg = q.split('/').filter(Boolean); out.mediaId = seg.pop() || ''; out.filename = out.mediaId; return out; } }
        if (isV3 && Array.isArray(man.items)) {
            man.items.forEach(cv => {
                const canvasLabel = getV3CanvasLabelNone(cv.label || ''); if (canvasLabel) labelsByOrder.push(canvasLabel);
                const anns = (cv.items && cv.items[0] && Array.isArray(cv.items[0].items)) ? cv.items[0].items : [];
                anns.forEach(a => { const body = a.body || {}; const resId = body.id || body['@id'] || ''; const { mediaId, filename } = parseUrlParts(resId); const label = canvasLabel; if (mediaId) byMediaId.set(String(mediaId), label); if (filename) byFilename.set(String(filename), label); });
            });
        } else if (man.sequences && man.sequences[0] && Array.isArray(man.sequences[0].canvases)) {
            man.sequences[0].canvases.forEach(cv => {
                const canvasLabel = getV2CanvasLabel(cv.label || ''); if (canvasLabel) labelsByOrder.push(canvasLabel);
                const images = Array.isArray(cv.images) ? cv.images : [];
                images.forEach(img => { const res = (img.resource && (img.resource.id || img.resource['@id'])) || ''; const { mediaId, filename } = parseUrlParts(res); const label = canvasLabel; if (mediaId) byMediaId.set(String(mediaId), label); if (filename) byFilename.set(String(filename), label); });
            });
        }
        return { byMediaId, byFilename, labelsByOrder };
    }
    function applyLabelsFromManifest(panel, maps) {
        const byId = maps && maps.byMediaId ? maps.byMediaId : new Map();
        const byFile = maps && maps.byFilename ? maps.byFilename : new Map();
        const ordered = Array.isArray(maps && maps.labelsByOrder) ? maps.labelsByOrder.slice() : [];
        qsa(panel, '.download-panel__check').forEach((b, idx) => {
            const id = String(b.getAttribute('data-media-id') || '');
            const fname = String(b.getAttribute('data-filename') || '');
            let label = byId.get(id) || byFile.get(fname); if (!label && ordered.length) label = ordered.shift();
            if (label) { const span = b.closest('label').querySelector('.download-panel__label'); let raw = String(label).trim(); const low = raw.toLowerCase(); if (low.startsWith('http://') || low.startsWith('https://') || low.includes('/iiif/')) { raw = String(idx + 1); } const short = (raw.length > 60) ? raw.slice(0, 57) + '...' : raw; span.textContent = short; span.title = raw; }
        });
    }
    function init(panel) {
        const manifestUrl = panel.getAttribute('data-manifest-url');
        if (manifestUrl) { fetchManifest(manifestUrl).then(man => extractLabelsFromManifest(man)).then(maps => { applyLabelsFromManifest(panel, maps); }).catch(() => { }); }
        panel.addEventListener('change', (e) => { if (e.target.matches('.download-panel__check') || e.target.matches('.download-panel__agree-check')) { updateState(panel); } });
        panel.addEventListener('click', (e) => { const act = e.target.closest('[data-action]'); if (!act) return; const a = act.getAttribute('data-action'); if (a === 'toggle-all') toggleAll(panel); else if (a === 'download') downloadSelected(panel); });
        updateState(panel);
    }
    document.addEventListener('DOMContentLoaded', () => { document.querySelectorAll('.download-panel').forEach(init); });
})();
