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
