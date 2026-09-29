'use strict';
const basePath = document.querySelector('meta[name="neo-base-path"]')?.content || '';
const chunkSize = Number(document.querySelector('meta[name="neo-chunk-size"]')?.content || 8388608);
const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
document.querySelectorAll('[data-confirm]').forEach(form => form.addEventListener('submit', event => { if (!confirm(form.dataset.confirm)) event.preventDefault(); }));
document.querySelectorAll('[data-back]').forEach(button => button.addEventListener('click', () => history.back()));
document.querySelectorAll('[data-copy]').forEach(button => button.addEventListener('click', async () => {
  const input = document.getElementById(button.dataset.copy);
  try { await navigator.clipboard.writeText(input.value); button.textContent = 'Copied!'; }
  catch { input.focus(); input.select(); button.textContent = 'Select & copy the URL'; }
}));
async function api(url, options = {}) {
  const response = await fetch(basePath + url, { ...options, headers: { 'X-CSRF-Token': csrf, ...options.headers } });
  let body; try { body = await response.json(); } catch { throw new Error('Server response interrupted. Reselect your file to resume.'); }
  if (!response.ok) throw new Error(body.error || `Request failed (${response.status}).`);
  return body;
}
const uploadForm = document.getElementById('upload-form');
if (uploadForm) uploadForm.addEventListener('submit', async event => {
  event.preventDefault();
  const file = document.getElementById('media-file').files[0]; if (!file) return;
  const button = document.getElementById('upload-button'), progress = document.getElementById('upload-progress'), status = document.getElementById('upload-status');
  button.disabled = true; progress.hidden = false; status.textContent = 'Preparing upload…';
  const key = `neo-upload:${basePath}:${uploadForm.dataset.episode}`;
  try {
    // Hash the selected file incrementally by chunk digests. Reselection must match the entire source.
    const hashes = [];
    for (let start = 0; start < file.size; start += 8388608) {
      hashes.push(new Uint8Array(await crypto.subtle.digest('SHA-256', await file.slice(start, start + 8388608).arrayBuffer())));
    }
    const joined = new Uint8Array(hashes.length * 32); hashes.forEach((hash, index) => joined.set(hash, index * 32));
    const fingerprint = Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256', joined)), x => x.toString(16).padStart(2, '0')).join('');
    let saved; try { saved = JSON.parse(localStorage.getItem(key) || 'null'); } catch { saved = null; }
    let upload;
    if (saved?.fingerprint === fingerprint) {
      try { upload = await api(`/api/uploads/${saved.id}`); } catch { upload = null; }
      if (upload && upload.status !== 'uploading') upload = null;
    }
    if (!upload) {
      upload = await api('/api/uploads', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ episode_id: uploadForm.dataset.episode, name: file.name, size: file.size }) });
      localStorage.setItem(key, JSON.stringify({ id: upload.id, fingerprint }));
    }
    let offset = Number(upload.offset);
    while (offset < file.size) {
      const body = file.slice(offset, offset + chunkSize);
      const result = await api(`/api/uploads/${upload.id}`, { method: 'PUT', headers: { 'Upload-Offset': String(offset), 'Content-Type': 'application/octet-stream' }, body });
      offset = result.offset; progress.value = offset / file.size * 100;
      status.textContent = `Uploading… ${Math.round(progress.value)}%`;
    }
    const result = await api(`/api/uploads/${upload.id}/finish`, { method: 'POST' });
    localStorage.removeItem(key);
    status.textContent = result.state === 'ready' ? 'Your MP3 is ready.' : (result.error || 'Upload complete. Media processing is queued.');
    location.reload();
  } catch (error) { status.textContent = error.message; }
  finally { button.disabled = false; }
});
const processing = document.getElementById('processing-status');
if (processing) {
  const initial = processing.textContent.trim().toLowerCase();
  if (['queued', 'processing'].includes(initial)) {
    const timer = setInterval(async () => {
      try {
        const status = await api(`/api/episodes/${processing.dataset.episode}/status`);
        processing.textContent = status.state === 'processing' ? 'Creating your MP3…' : status.state;
        if (['ready', 'failed'].includes(status.state)) { clearInterval(timer); location.reload(); }
      } catch (error) { processing.textContent = error.message; clearInterval(timer); }
    }, 3000);
  }
}
