/**
 * Bulk upload for the wall's upload panel (MyStashMini).
 *
 * The form still posts to upload.php, and without JS the browser sends every
 * picked file in one request. With JS, this takes the submit over and sends the
 * files ONE AT A TIME, in order, each as its own request:
 *
 *  - every upload rewrites the whole encrypted index, so two at once would each
 *    save a copy missing the other's entry;
 *  - one huge request would hit post_max_size as soon as the total, rather than
 *    any single file, passed the limit;
 *  - and one bad file only fails itself, with its own message in the list.
 *
 * Each file has two halves and only the first has a percentage: sending the
 * bytes is measurable, but reading, thumbnailing and encrypting it on the
 * server is not — so the bar sweeps (the .indeterminate style from job.js)
 * while the server works.
 */
(function () {
  const panel = document.getElementById('upload-panel');
  if (!panel) return;

  const form = panel.querySelector('form');
  const card = document.getElementById('upload-progress');
  if (!form || !card || !window.FormData || !window.XMLHttpRequest) return;
  if (!('upload' in new XMLHttpRequest())) return;

  const bar = document.getElementById('upload-bar');
  const message = document.getElementById('upload-message');
  const detail = document.getElementById('upload-detail');
  const queueList = document.getElementById('upload-queue');
  const cancel = document.getElementById('upload-cancel');
  const fileInput = document.getElementById('media-files');
  const previewAt = document.getElementById('preview-at');
  const submit = form.querySelector('button[type="submit"]');
  const errorBox = panel.querySelector('.upload-error');

  // The smaller of upload_max_filesize and post_max_size, handed down by PHP.
  const maxBytes = Number(card.dataset.maxBytes) || 0;

  let xhr = null;
  let cancelled = false;

  function human(bytes) {
    if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(2) + ' GB';
    if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
    if (bytes >= 1024) return Math.round(bytes / 1024) + ' KB';
    return bytes + ' B';
  }

  function showError(text) {
    if (!errorBox) return;
    errorBox.textContent = text;
    errorBox.hidden = false;
  }

  function row(file) {
    const li = document.createElement('li');
    const name = document.createElement('span');
    name.className = 'name';
    name.textContent = file.name;
    const state = document.createElement('span');
    state.className = 'state';
    state.textContent = file.tooBig ? 'too large' : 'waiting';
    li.append(name, state);
    queueList.append(li);
    return { li, state };
  }

  function setRow(r, cls, text) {
    r.li.className = cls;
    r.state.textContent = text;
  }

  /** Sends one file; resolves to {ok, error} and never rejects. */
  function send(file, r, index, total) {
    return new Promise((resolve) => {
      const data = new FormData();
      data.append('media[]', file, file.name);
      if (previewAt && previewAt.value !== '') data.append('preview_at', previewAt.value);

      card.classList.remove('indeterminate');
      bar.style.width = '0%';
      message.textContent = 'Uploading ' + (index + 1) + ' of ' + total + ': ' + file.name;
      setRow(r, 'active', '0%');
      r.li.scrollIntoView({ block: 'nearest' });

      xhr = new XMLHttpRequest();
      xhr.open('POST', form.action);
      xhr.setRequestHeader('X-Requested-With', 'fetch');

      xhr.upload.addEventListener('progress', (e) => {
        if (!e.lengthComputable) return;
        const percent = (e.loaded / e.total) * 100;
        bar.style.width = percent.toFixed(1) + '%';
        detail.textContent = human(e.loaded) + ' of ' + human(e.total);
        r.state.textContent = Math.round(percent) + '%';
      });

      xhr.upload.addEventListener('load', () => {
        card.classList.add('indeterminate');
        detail.textContent = 'Processing on the server — thumbnail and encryption.';
        r.state.textContent = 'processing';
      });

      xhr.addEventListener('load', () => {
        let body = null;
        try { body = JSON.parse(xhr.responseText); } catch (_) { /* not JSON */ }

        if (body && body.added && body.added.length > 0) {
          resolve({ ok: true });
        } else if (body && body.errors && body.errors.length > 0) {
          resolve({ ok: false, error: body.errors.join(' ') });
        } else {
          resolve({ ok: false, error: 'server error' + (xhr.status ? ' (HTTP ' + xhr.status + ')' : '') });
        }
      });
      xhr.addEventListener('error', () => resolve({ ok: false, error: 'connection dropped' }));
      xhr.addEventListener('abort', () => resolve({ ok: false, error: 'cancelled' }));

      xhr.send(data);
    });
  }

  form.addEventListener('submit', async (event) => {
    const files = Array.from((fileInput && fileInput.files) || []);
    if (files.length === 0) return; // `required` gives its own message.

    event.preventDefault();

    if (errorBox) errorBox.hidden = true;
    if (submit) submit.disabled = true;
    form.hidden = true;
    card.hidden = false;
    queueList.replaceChildren();
    cancelled = false;

    files.forEach((f) => { f.tooBig = maxBytes > 0 && f.size > maxBytes; });
    const rows = files.map(row);
    const failures = [];
    let added = 0;

    for (let i = 0; i < files.length; i += 1) {
      if (cancelled) {
        setRow(rows[i], 'failed', 'cancelled');
        continue;
      }
      if (files[i].tooBig) {
        setRow(rows[i], 'failed', 'too large (limit ' + human(maxBytes) + ')');
        failures.push(files[i].name + ': larger than the ' + human(maxBytes) + ' limit.');
        continue;
      }

      const result = await send(files[i], rows[i], i, files.length);
      if (result.ok) {
        added += 1;
        setRow(rows[i], 'done', 'done');
      } else {
        setRow(rows[i], 'failed', result.error === 'cancelled' ? 'cancelled' : 'failed');
        if (result.error !== 'cancelled') failures.push(result.error);
      }
    }

    xhr = null;
    card.classList.remove('indeterminate');
    bar.style.width = '100%';

    if (failures.length === 0 && !cancelled) {
      message.textContent = 'Uploaded ' + added + ' item' + (added === 1 ? '' : 's');
      detail.textContent = 'Refreshing the wall…';
      window.location.href = 'wall.php';
      return;
    }

    // Something failed or was cancelled: leave the list on screen so the user
    // can see which, and offer the form again. Whatever did upload is safe.
    message.textContent = 'Uploaded ' + added + ' of ' + files.length;
    detail.textContent = added > 0 ? 'Reload the wall to see what was added.' : '';
    if (failures.length > 0) showError(failures.join(' '));
    form.hidden = false;
    if (submit) submit.disabled = false;
    fileInput.value = '';
    cancel.textContent = 'Close';
  });

  if (cancel) {
    cancel.addEventListener('click', () => {
      if (xhr) {
        cancelled = true;
        xhr.abort();
        return;
      }
      // Nothing in flight: this is the "Close" after a partial run.
      window.location.href = 'wall.php';
    });
  }

  window.addEventListener('beforeunload', (event) => {
    if (xhr && xhr.readyState !== 4) {
      event.preventDefault();
      event.returnValue = '';
    }
  });
})();
