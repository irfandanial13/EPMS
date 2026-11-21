/* EPMS — validasi sebaris (UI-05): borang bertanda [data-validasi].
   Mesej merah dipaparkan betul-betul di bawah medan semasa pengguna mengisi (blur/input), bukan selepas Simpan.
   Hanya kekangan HTML sedia ada (required, pattern, min, step, minlength) — validasi pelayan TIDAK diubah dan tetap muktamad. */
(function () {
  'use strict';
  function mesej(el) {
    var v = el.validity;
    if (v.valueMissing) return 'Medan ini wajib diisi.';
    if (v.patternMismatch) return el.getAttribute('title') || 'Format tidak sah.';
    if (v.rangeUnderflow) return 'Nilai mesti sekurang-kurangnya ' + el.min + '.';
    if (v.rangeOverflow) return 'Nilai mesti tidak melebihi ' + el.max + '.';
    if (v.stepMismatch) return el.step === '1' ? 'Mesti nombor bulat.' : 'Bilangan tempat perpuluhan tidak sah.';
    if (v.badInput || v.typeMismatch) return 'Nilai tidak sah.';
    if (v.tooShort) return 'Sekurang-kurangnya ' + el.minLength + ' aksara.';
    return el.validationMessage || 'Nilai tidak sah.';
  }
  function bekas(el) { return el.closest('.input-group') || el.parentElement; }
  function papar(el) {
    if (el.disabled || !el.willValidate) return true;
    var ok = el.checkValidity(), b = bekas(el), fb = b.querySelector('.invalid-feedback');
    if (ok) {
      el.classList.remove('is-invalid'); el.removeAttribute('aria-invalid');
      if (fb && fb.classList.contains('js-ralat')) fb.remove();
      else if (fb && el.dataset.ralatPelayan === '1') fb.style.display = 'none';   // ralat pelayan lama disembunyi selepas dibetulkan
      return true;
    }
    if (!fb || !fb.classList.contains('js-ralat')) {
      if (fb) { el.dataset.ralatPelayan = '1'; fb.style.display = 'none'; }
      fb = document.createElement('div'); fb.className = 'invalid-feedback js-ralat';
      fb.id = (el.id || el.name) + '-ralat-klien'; b.appendChild(fb);
    }
    fb.textContent = mesej(el);
    el.classList.add('is-invalid'); el.setAttribute('aria-invalid', 'true'); el.setAttribute('aria-describedby', fb.id);
    return false;
  }
  document.querySelectorAll('form[data-validasi]').forEach(function (f) {
    f.setAttribute('novalidate', '');                 // gantikan gelembung pelayar dengan mesej sebaris
    f.querySelectorAll('input, select, textarea').forEach(function (el) {
      el.addEventListener('blur', function () { if (el.value !== '' || el.dataset.disentuh) papar(el); el.dataset.disentuh = '1'; });
      el.addEventListener('input', function () { el.dataset.disentuh = '1'; if (el.classList.contains('is-invalid')) papar(el); });
      el.addEventListener('change', function () { if (el.dataset.disentuh) papar(el); });
    });
    f.addEventListener('submit', function (e) {
      var pertama = null;
      f.querySelectorAll('input, select, textarea').forEach(function (el) { if (!papar(el) && !pertama) pertama = el; });
      if (pertama) { e.preventDefault(); e.stopImmediatePropagation(); pertama.focus(); }
    });
  });
})();

/* EPMS — layout global (UI-02): togol sidebar, drawer mudah alih, tooltip mod ikon. */
(function () {
  'use strict';
  if (window.__epmsLayout) return;            // elak pendaftaran event berganda
  window.__epmsLayout = true;

  var root = document.documentElement;
  var sidebar = document.getElementById('sidebar');
  var togol = document.getElementById('togolSidebar');
  if (!sidebar || !togol || !window.bootstrap) return;

  var desktop = window.matchMedia('(min-width: 768px)');
  var simpan = function (v) { try { localStorage.setItem('epms-sidebar', v); } catch (e) {} };
  var mini = function () { return root.classList.contains('sidebar-mini'); };

  function kemasAria() {
    togol.setAttribute('aria-expanded', desktop.matches ? String(!mini()) : String(sidebar.classList.contains('show')));
  }

  // Tooltip hanya dipaparkan semasa mod ikon (desktop/tablet)
  var tips = [].map.call(sidebar.querySelectorAll('[data-epms-tip]'), function (el) {
    el.addEventListener('show.bs.tooltip', function (ev) { if (!(desktop.matches && mini())) ev.preventDefault(); });
    return new bootstrap.Tooltip(el, { title: el.getAttribute('data-epms-tip'), placement: 'right', trigger: 'hover focus', container: 'body' });
  });

  togol.addEventListener('click', function () {
    if (desktop.matches) {
      root.classList.toggle('sidebar-mini');
      simpan(mini() ? 'mini' : 'penuh');
      tips.forEach(function (t) { t.hide(); });
    } else {
      bootstrap.Offcanvas.getOrCreateInstance(sidebar).toggle();
    }
    kemasAria();
  });

  sidebar.addEventListener('shown.bs.offcanvas', kemasAria);
  sidebar.addEventListener('hidden.bs.offcanvas', function () { kemasAria(); togol.focus(); });
  desktop.addEventListener('change', function () { tips.forEach(function (t) { t.hide(); }); kemasAria(); });
  kemasAria();
})();
