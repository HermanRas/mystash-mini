/**
 * Upload progress for the wall's upload panel.
 *
 * The form still posts to upload.php exactly as it did; this only takes the
 * submit over so the browser can report how far the transfer has got. With JS
 * off, or on a browser without XHR upload events, nothing here runs and the
 * plain form submit is still the whole feature — which is why the markup is a
 * real <form action="upload.php"> and not a button wired to fetch().
 *
 * An upload has two halves and only the first has a percentage. Sending the
 * bytes is measurable; what happens afterwards — ffmpeg reading the duration,
 * grabbing the preview frame, encrypting the video into the datastore — is
 * work the browser cannot see. (The hover clip, which decodes the whole video,
 * is no longer part of it: that is a background job, see upload.php.) So the
 * card switches to the same sweeping indeterminate bar the conversion job uses (assets/job.js) rather than
 * sitting at 100% looking hung.
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
  const cancel = document.getElementById('upload-cancel');
  const fileInput = document.getElementById('video-file');
  const submit = form.querySelector('button[type="submit"]');
  const errorBox = panel.querySelector('.upload-error');

  // post_max_size, handed down from PHP so the two cannot drift. Over it, PHP
  // discards the request body before upload.php runs and $_FILES arrives empty
  // — the user would watch a 3 GB transfer finish and then be told "no video
  // was received". Cheaper to say so before sending anything.
  const maxBytes = Number(card.dataset.maxBytes) || 0;

  let xhr = null;

  function showError(text) {
    card.hidden = true;
    card.classList.remove('indeterminate');
    form.hidden = false;
    if (submit) submit.disabled = false;
    if (errorBox) {
      errorBox.textContent = text;
      errorBox.hidden = false;
    } else {
      // No error paragraph was rendered (this load had no upload_error), so
      // make one rather than dropping the message.
      const p = document.createElement('p');
      p.className = 'upload-error';
      p.textContent = text;
      panel.insertBefore(p, form);
    }
  }

  function human(bytes) {
    if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(2) + ' GB';
    if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
    if (bytes >= 1024) return Math.round(bytes / 1024) + ' KB';
    return bytes + ' B';
  }

  function duration(seconds) {
    if (!isFinite(seconds) || seconds < 0) return '';
    const m = Math.floor(seconds / 60);
    const s = Math.round(seconds % 60);
    return m > 0 ? m + 'm ' + s + 's' : s + 's';
  }

  form.addEventListener('submit', (event) => {
    const file = fileInput && fileInput.files && fileInput.files[0];
    if (!file) return; // `required` has its own message; let the browser give it.

    if (maxBytes > 0 && file.size > maxBytes) {
      event.preventDefault();
      showError(
        'That file is ' + human(file.size) + '. The upload limit is '
        + human(maxBytes) + '.',
      );
      return;
    }

    event.preventDefault();

    if (errorBox) errorBox.hidden = true;
    if (submit) submit.disabled = true;
    form.hidden = true;
    card.hidden = false;
    card.removeAttribute('data-state');
    card.classList.remove('indeterminate');
    bar.style.width = '0%';
    message.textContent = 'Uploading ' + file.name;
    detail.textContent = human(0) + ' of ' + human(file.size);

    const started = Date.now();

    xhr = new XMLHttpRequest();
    xhr.open('POST', form.action);

    xhr.upload.addEventListener('progress', (e) => {
      if (!e.lengthComputable) {
        card.classList.add('indeterminate');
        return;
      }
      const percent = (e.loaded / e.total) * 100;
      bar.style.width = percent.toFixed(1) + '%';

      const elapsed = (Date.now() - started) / 1000;
      const rate = elapsed > 0 ? e.loaded / elapsed : 0;
      const remaining = rate > 0 ? (e.total - e.loaded) / rate : Infinity;

      detail.textContent = human(e.loaded) + ' of ' + human(e.total)
        + ' · ' + Math.round(percent) + '%'
        + (rate > 0 ? ' · ' + human(rate) + '/s' : '')
        + (isFinite(remaining) && remaining > 1 ? ' · ' + duration(remaining) + ' left' : '');
    });

    // The bytes are all with the server; everything from here is ingestion,
    // which reports nothing back until it returns. Hence the sweep.
    xhr.upload.addEventListener('load', () => {
      card.classList.add('indeterminate');
      message.textContent = 'Processing video';
      detail.textContent =
        'Reading the video, capturing the thumbnail and encrypting it into your '
        + 'stash. The hover preview is built afterwards in the background.';
    });

    xhr.addEventListener('load', () => {
      // upload.php answers with a redirect to wall.php on every path it
      // handles, success and refusal alike, and XHR follows it — so
      // responseURL is wall.php (carrying ?upload_error=… when it refused)
      // and simply going there reproduces the non-JS behaviour exactly.
      const landed = xhr.responseURL || '';
      if (landed && landed.indexOf('upload.php') === -1) {
        card.classList.remove('indeterminate');
        bar.style.width = '100%';
        window.location.href = landed;
        return;
      }

      // No redirect: upload.php died partway through and what came back is
      // whatever PHP printed. The status line is not worth showing the user,
      // but the fact that it failed very much is.
      showError(
        'The upload failed on the server' + (xhr.status ? ' (HTTP ' + xhr.status + ')' : '')
        + '. Nothing was added to your stash.',
      );
    });

    xhr.addEventListener('error', () => {
      showError('The connection dropped during the upload. Nothing was added to your stash.');
    });

    xhr.addEventListener('abort', () => {
      card.hidden = true;
      card.classList.remove('indeterminate');
      form.hidden = false;
      if (submit) submit.disabled = false;
    });

    xhr.send(new FormData(form));
  });

  if (cancel) {
    cancel.addEventListener('click', () => {
      if (xhr) xhr.abort();
    });
  }

  // Closing the tab mid-upload throws the transfer away, and there is no
  // resume — worth one confirmation prompt.
  window.addEventListener('beforeunload', (event) => {
    if (xhr && xhr.readyState !== 4 && !card.hidden) {
      event.preventDefault();
      event.returnValue = '';
    }
  });
})();
