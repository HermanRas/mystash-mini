/**
 * Drives a background job's progress card (Docs/PLAN.md 4.28, 6.6).
 *
 * One poller for both jobs — a conversion on the watch page and a re-key on the
 * Profile page — because they are the same mechanism wearing different labels.
 * The card declares what to poll for:
 *
 *   <div id="job-card" data-kind="convert" data-target="7" data-done-url="…">
 *
 * data-done-url is where to go when the job *succeeds*; a failure just reloads
 * the page it is on, so the error is shown in place.
 *
 * data-status-url, when present, is polled instead of job_status.php. The v1 →
 * v2 upgrade page needs it: that job runs before anyone is signed in, so
 * job_status.php — which requires a login — cannot answer for it.
 */
(function () {
  const card = document.getElementById('job-card');
  if (!card) return;

  const bar = document.getElementById('job-bar');
  const message = document.getElementById('job-message');

  const url = card.dataset.statusUrl || ('job_status.php'
    + '?kind=' + encodeURIComponent(card.dataset.kind)
    + '&target=' + encodeURIComponent(card.dataset.target || ''));

  // A job started moments ago may not have a record yet, and a finished one is
  // eventually pruned. Neither is an error on its own; both are, if they
  // persist.
  let missing = 0;
  let failures = 0;

  function reload() {
    // The finished job changes what the whole page should show, and the page
    // was rendered while it was still running — so re-render it rather than
    // patching it up in place.
    window.location.reload();
  }

  function tick() {
    fetch(url, { cache: 'no-store' })
      .then((response) => (response.ok ? response.json() : Promise.reject()))
      .then((job) => {
        failures = 0;

        if (job.state === 'none') {
          if (++missing > 8) return reload();
          return void setTimeout(tick, 1000);
        }

        missing = 0;

        if (job.state === 'running') {
          const percent = Number(job.percent) || 0;
          card.classList.toggle('indeterminate', percent === 0);
          bar.style.width = percent + '%';
          message.textContent = job.message || 'Working…';
          return void setTimeout(tick, 1000);
        }

        card.classList.remove('indeterminate');
        card.dataset.state = job.state;
        bar.style.width = '100%';
        message.textContent = job.message || '';

        // Long enough to read the final line before the page changes under it.
        setTimeout(() => {
          if (job.state === 'done' && card.dataset.doneUrl) {
            window.location.href = card.dataset.doneUrl;
          } else {
            reload();
          }
        }, 1500);
      })
      .catch(() => {
        // A poll can fail transiently. Give up only after several in a row, and
        // say so — a bar that quietly stops moving is worse than an error.
        if (++failures > 5) {
          message.textContent = 'Lost contact with the job. Reload the page to check on it.';
          return;
        }
        setTimeout(tick, 2000);
      });
  }

  tick();
})();
