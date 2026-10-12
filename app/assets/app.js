// AWS Poll App: theme switch, copy links, live results, admin helpers.
(function () {
  var root = document.documentElement;

  // ---------- Theme ----------
  function currentTheme() {
    if (root.dataset.theme) return root.dataset.theme;
    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }
  var toggle = document.querySelector('.theme-toggle');
  if (toggle) {
    var label = function () {
      toggle.setAttribute('aria-label', currentTheme() === 'dark' ? 'Switch to light theme' : 'Switch to dark theme');
    };
    label();
    toggle.addEventListener('click', function () {
      var next = currentTheme() === 'dark' ? 'light' : 'dark';
      root.dataset.theme = next;
      try { localStorage.setItem('theme', next); } catch (e) {}
      label();
    });
  }

  // ---------- Copy link ----------
  document.querySelectorAll('[data-copy]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var text = btn.getAttribute('data-copy');
      var done = function () {
        var old = btn.textContent;
        btn.textContent = 'Copied';
        setTimeout(function () { btn.textContent = old; }, 1600);
      };
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(done);
      } else {
        // Plain-HTTP fallback (the ALB serves HTTP, where the clipboard API is blocked)
        var t = document.createElement('textarea');
        t.value = text;
        t.style.position = 'fixed';
        t.style.opacity = '0';
        document.body.appendChild(t);
        t.select();
        try { document.execCommand('copy'); done(); } catch (e) {}
        document.body.removeChild(t);
      }
    });
  });

  // ---------- Confirm destructive actions ----------
  document.querySelectorAll('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      if (!window.confirm(f.getAttribute('data-confirm'))) e.preventDefault();
    });
  });

  // ---------- Add option fields (max 6) ----------
  var addBtn = document.querySelector('[data-add-option]');
  var box = document.querySelector('[data-options]');
  if (addBtn && box) {
    var sync = function () { addBtn.hidden = box.querySelectorAll('input').length >= 6; };
    sync();
    addBtn.addEventListener('click', function () {
      var n = box.querySelectorAll('input').length + 1;
      var input = document.createElement('input');
      input.type = 'text';
      input.name = 'options[]';
      input.maxLength = 100;
      input.placeholder = 'Option ' + n + ' (optional)';
      box.appendChild(input);
      input.focus();
      sync();
    });
  }

  // ---------- QR code (admin) ----------
  document.querySelectorAll('details.qr').forEach(function (d) {
    d.addEventListener('toggle', function () {
      var target = d.querySelector('[data-qr]');
      if (!d.open || target.childElementCount || typeof QRCode === 'undefined') return;
      new QRCode(target, { text: target.getAttribute('data-qr'), width: 192, height: 192 });
    });
  });

  // ---------- Results: animate in, then update live ----------
  var results = document.querySelector('.results');
  if (results) {
    requestAnimationFrame(function () {
      requestAnimationFrame(function () { results.classList.add('is-ready'); });
    });
  }

  var poll = document.querySelector('.poll[data-live="1"]');
  if (!poll || !results) return;

  var id = poll.getAttribute('data-poll');
  var answered = document.querySelector('[data-answered]');
  var lastServer = null;
  var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function refresh() {
    fetch('/api.php?id=' + encodeURIComponent(id), { cache: 'no-store', credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) {
        if (!data) return;
        if (!data.open) { window.location.reload(); return; }
        var total = data.total;
        data.options.forEach(function (o) {
          var row = results.querySelector('[data-option="' + o.id + '"]');
          if (!row) return;
          var pct = total > 0 ? Math.round(o.votes * 100 / total) : 0;
          row.style.setProperty('--pct', pct + '%');
          row.querySelector('[data-pct]').textContent = pct + '%';
          row.querySelector('[data-count]').textContent = o.votes;
        });
        results.querySelector('[data-total]').textContent = total;
        results.querySelector('[data-total-word]').textContent = total === 1 ? 'vote' : 'votes';

        if (answered && data.server) {
          var key = data.server.instance + '|' + data.server.az;
          answered.querySelector('[data-live-instance]').textContent = data.server.instance;
          answered.querySelector('[data-live-az]').textContent = data.server.az;
          answered.hidden = false;
          if (lastServer && key !== lastServer && !reduce) {
            answered.classList.remove('flash');
            void answered.offsetWidth;
            answered.classList.add('flash');
            setTimeout(function () { answered.classList.remove('flash'); }, 1200);
          }
          lastServer = key;
        }
      })
      .catch(function () {});
  }

  refresh();
  setInterval(function () { if (!document.hidden) refresh(); }, 4000);
})();
