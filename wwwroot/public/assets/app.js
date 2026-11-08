/* Exposure Lens UI. jQuery + Bootstrap 5 + Chart.js, no build step.
   Every value that comes from the server is passed through esc() before it
   becomes HTML. */
(function ($) {
  'use strict';

  var CSRF = $('meta[name="csrf-token"]').attr('content');
  var PAGE = document.body.dataset.page;
  var SEV = { 4: ['C', 'Critical'], 3: ['H', 'High'], 2: ['M', 'Medium'], 1: ['L', 'Low'], 0: ['I', 'Info'] };
  var SEV_COLOR = { 4: '#91243e', 3: '#dd4b50', 2: '#f18c43', 1: '#f8c851', 0: '#b8bcc6' };

  // ---- helpers ---------------------------------------------------------------
  function api(action, data, method) {
    return $.ajax({
      url: '/api.php?action=' + encodeURIComponent(action), method: method || 'GET', data: data, dataType: 'json',
      headers: method === 'POST' ? { 'X-CSRF-Token': CSRF } : {}
    });
  }
  function post(action, data) { return api(action, data, 'POST'); }
  function esc(value) {
    return String(value === null || value === undefined ? '' : value).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function errText(xhr) {
    return (xhr && xhr.responseJSON && xhr.responseJSON.error) ||
      'The server did not answer. Check that the web and php containers are running.';
  }
  function toast(message, kind) {
    var $t = $('<div class="toast align-items-center text-bg-' + (kind === 'error' ? 'danger' : 'dark') +
      '" role="status"><div class="d-flex"><div class="toast-body"></div>' +
      '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button></div></div>');
    $t.find('.toast-body').text(message);
    $('#toastBox').append($t);
    $t.on('hidden.bs.toast', function () { $t.remove(); });
    new bootstrap.Toast($t[0], { delay: kind === 'error' ? 9000 : 4000 }).show();
  }
  function parseDate(s) {
    if (!s) return null;
    // PostgreSQL: "2026-11-06 15:47:42.006758+00"; logs: ISO 8601.
    var iso = String(s).replace(' ', 'T').replace(/(\.\d{3})\d+/, '$1').replace(/([+-]\d{2})$/, '$1:00');
    var d = new Date(iso);
    return isNaN(d) ? null : d;
  }
  function pad(n) { return n < 10 ? '0' + n : '' + n; }
  function fmtDate(s, withSeconds) {
    var d = parseDate(s);
    if (!d) return '';
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + ' ' + pad(d.getHours()) + ':' +
      pad(d.getMinutes()) + (withSeconds ? ':' + pad(d.getSeconds()) : '');
  }
  function fmtDay(s) { return fmtDate(s).slice(0, 10); }
  function ago(s) {
    var d = parseDate(s);
    if (!d) return '';
    var sec = Math.max(0, Math.round((Date.now() - d.getTime()) / 1000));
    if (sec < 60) return 'just now';
    if (sec < 3600) return Math.floor(sec / 60) + ' min ago';
    if (sec < 86400) return Math.floor(sec / 3600) + ' h ago';
    return Math.floor(sec / 86400) + ' d ago';
  }
  function num(n) { return Number(n || 0).toLocaleString('en-US'); }
  function compact(n) {
    n = Number(n || 0);
    if (n >= 1e6) return (n / 1e6).toFixed(1).replace(/\.0$/, '') + 'M';
    if (n >= 1e4) return Math.round(n / 1e3) + 'K';
    if (n >= 1e3) return (n / 1e3).toFixed(1).replace(/\.0$/, '') + 'K';
    return String(n);
  }
  function duration(sec) {
    if (sec === null || sec === undefined) return '';
    sec = Number(sec);
    return sec < 60 ? sec + ' s' : Math.floor(sec / 60) + ' min ' + (sec % 60) + ' s';
  }
  function debounce(fn, ms) {
    var timer;
    return function () { var a = arguments, t = this; clearTimeout(timer); timer = setTimeout(function () { fn.apply(t, a); }, ms); };
  }
  function sevBadge(sev) {
    var s = SEV[sev] || SEV[0];
    return '<span class="sev sev-' + esc(sev) + '" title="' + s[1] + '">' + s[0] + '</span>';
  }
  function tierBadge(tier, source) {
    if (tier === null || tier === undefined) return source === 'manual' ? '<span class="badge-soft" title="Removed from every tier by hand">none</span>' : '';
    return '<span class="badge-soft tier-' + esc(tier) + '" title="' + (source === 'manual' ? 'Assigned by hand' : 'From Identity Exposure') + '">Tier ' +
      esc(tier) + (source === 'manual' ? ' ·m' : '') + '</span>';
  }
  function statusBadge(status) {
    var cls = { succeeded: 'ok', failed: 'bad', running: 'run', queued: 'wait' }[status] || '';
    return '<span class="badge-soft ' + cls + '">' + esc(status) + '</span>';
  }
  function enabledBadge(v) {
    if (v === null || v === undefined) return '';
    return v ? '<span class="badge-soft ok">Enabled</span>' : '<span class="badge-soft bad">Disabled</span>';
  }
  /** An error message for a table cell: capped, with the full text on hover. */
  function errSpan(text) {
    text = String(text || 'Failed without a message.');
    return '<span class="text-danger" title="' + esc(text) + '">' + esc(text.length > 320 ? text.slice(0, 320) + '…' : text) + '</span>';
  }
  function emptyRow(cols, text) { return '<tr><td colspan="' + cols + '" class="empty-state">' + text + '</td></tr>'; }
  function loadingRow(cols) { return emptyRow(cols, 'Loading…'); }
  function query() { return new URLSearchParams(window.location.search); }

  /** Pager: "1–50 of 1,880" with previous/next. */
  function renderPager($el, state, total, onChange) {
    var pages = Math.max(1, Math.ceil(total / state.size));
    var from = total ? (state.page - 1) * state.size + 1 : 0;
    var to = Math.min(total, state.page * state.size);
    $el.html('<span>' + num(from) + '–' + num(to) + ' of ' + num(total) + '</span>' +
      '<div class="btn-group btn-group-sm"><button type="button" class="btn btn-outline-secondary" data-go="-1"' + (state.page <= 1 ? ' disabled' : '') +
      '>Previous</button><button type="button" class="btn btn-outline-secondary" disabled>' + state.page + ' / ' + pages +
      '</button><button type="button" class="btn btn-outline-secondary" data-go="1"' + (state.page >= pages ? ' disabled' : '') + '>Next</button></div>');
    $el.off('click').on('click', '[data-go]', function () { state.page += Number(this.dataset.go); onChange(); });
  }

  /** Ask before a delete. */
  function confirmAction(title, text, okLabel, onOk) {
    $('#confirmTitle').text(title); $('#confirmText').text(text); $('#confirmOk').text(okLabel);
    var modal = bootstrap.Modal.getOrCreateInstance('#confirmModal');
    $('#confirmOk').off('click').on('click', function () { modal.hide(); onOk(); });
    modal.show();
  }

  /** Poll a job until it ends. onUpdate(job) runs on every poll; onDone(job) once. */
  function watchJob(id, onUpdate, onDone) {
    var stopped = false;
    (function tick() {
      if (stopped) return;
      api('job', { id: id }).done(function (job) {
        if (onUpdate) onUpdate(job);
        if (job.status === 'succeeded' || job.status === 'failed') { if (onDone) onDone(job); return; }
        setTimeout(tick, 2000);
      }).fail(function () { setTimeout(tick, 4000); });
    })();
    return function () { stopped = true; };
  }

  // ---- shell: flyout menus, menu labels, side panel -----------------------------
  function remember(key, value) { try { window.localStorage.setItem(key, value); } catch (e) { /* storage is optional */ } }
  function recall(key) { try { return window.localStorage.getItem(key); } catch (e) { return null; } }

  function closeFlyouts() {
    $('.flyout').attr('hidden', true);
    $('[data-flyout]').attr('aria-expanded', 'false');
  }
  $('[data-flyout]').on('click', function (e) {
    e.stopPropagation();
    var $panel = $('#flyout-' + this.dataset.flyout), open = $panel.attr('hidden') === undefined;
    closeFlyouts();
    if (!open) {
      $panel.removeAttr('hidden');
      $(this).attr('aria-expanded', 'true');
      if (!$panel.hasClass('flyout-right')) {
        var box = this.getBoundingClientRect(), centre = box.top + box.height / 2;
        var top = Math.max(65, Math.min(centre - 28, window.innerHeight - $panel.outerHeight() - 8));
        $panel.css({ top: top + 'px', '--arrow': (centre - top) + 'px' });
      }
      $panel.find('a').first().trigger('focus');
    }
  });
  $(document).on('click', function (e) { if (!$(e.target).closest('.flyout').length) closeFlyouts(); });
  $(document).on('keydown', function (e) { if (e.key === 'Escape') closeFlyouts(); });

  function setNavOpen(open) {
    $('body').toggleClass('nav-open', open);
    $('#navToggle').attr('aria-expanded', String(open));
  }
  setNavOpen(recall('el.nav') === 'open');
  $('#navToggle').on('click', function () {
    var open = !$('body').hasClass('nav-open');
    setNavOpen(open); remember('el.nav', open ? 'open' : 'closed'); closeFlyouts();
  });

  // ---- dashboard ------------------------------------------------------------------
  var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
  /** "November 4, 2022 2:12pm" */
  function fmtLong(d) {
    var h = d.getHours();
    return MONTHS[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear() + ' ' + (h % 12 || 12) + ':' + pad(d.getMinutes()) + (h < 12 ? 'am' : 'pm');
  }
  function plural(n, one, many) { return num(n) + ' ' + (Number(n) === 1 ? one : many); }

  function initDashboard() {
    function setSide(open) {
      $('body').toggleClass('sidebar-closed', !open);
      $('#sideToggle').attr('aria-expanded', String(open));
    }
    setSide(recall('el.side') !== 'closed' && window.innerWidth >= 1100);
    $('#sideToggle').on('click', function () {
      var open = $('body').hasClass('sidebar-closed');
      setSide(open); remember('el.side', open ? 'open' : 'closed');
    });
    $('.acc-toggle').each(function () {
      var btn = this, target = document.querySelector(btn.dataset.bsTarget);
      if (!target) return;
      target.addEventListener('show.bs.collapse', function (e) { if (e.target === target) btn.setAttribute('aria-expanded', 'true'); });
      target.addEventListener('hide.bs.collapse', function (e) { if (e.target === target) btn.setAttribute('aria-expanded', 'false'); });
    });

    api('dashboard').done(function (d) {
      var ad = d.ad || {}, a = d.assets || {}, t = d.totals || {};
      var sev = {};
      (d.severity || []).forEach(function (r) { sev[r.severity] = Number(r.n); });
      var tierTotal = { 0: 0, 1: 0, 2: 0 };
      (d.tiers || []).forEach(function (r) { if (tierTotal[r.tier] !== undefined) tierTotal[r.tier] += Number(r.n); });
      var sync = {};
      (d.last_sync || []).forEach(function (r) { sync[r.connector] = r; });
      var findings = Number(t.findings) || 0;
      var adObjects = Number(ad.users) + Number(ad.service_accounts) + Number(ad.groups) + Number(ad.computers) + Number(ad.group_policies);

      // page header
      var dates = [sync.sc, sync.tie_postgres].map(function (s) { return s && parseDate(s.finished_at || s.requested_at); }).filter(Boolean);
      $('#dashUpdated').text(dates.length ? 'Last Update: ' + fmtLong(new Date(Math.max.apply(null, dates))) : 'No sync has run yet.');

      // tiles
      var values = { users: ad.users, groups: ad.groups, service_accounts: ad.service_accounts, group_policies: ad.group_policies,
        tier0: tierTotal[0], tier1: tierTotal[1], tier2: tierTotal[2], users_disabled: ad.users_disabled,
        ad_computers: Number(a.ad_computers) + Number(a.joined_unknown_domain), not_joined: a.not_joined, iot: a.iot, sc_hosts: a.sc_hosts };
      var lu = d.local_users || {}, rk = d.risk || {};
      values.local_privileged = lu.privileged; values.local_normal = lu.normal;
      values.risk_critical_users = rk.critical_users; values.risk_acr_users = rk.acr_users;
      values.risk_tier0_hosts = rk.tier0_hosts; values.risk_service_accounts = rk.service_accounts;
      Object.keys(values).forEach(function (k) { $('[data-v="' + k + '"]').text(num(values[k])); });

      // top contributing factors
      var factors = [];
      if (findings) {
        factors.push(num(sev[4]) + ' critical and ' + num(sev[3]) + ' high findings are open on ' + plural(t.finding_hosts, 'host', 'hosts') + '.');
        factors.push(plural(t.vpr9, 'finding has', 'findings have') + ' a VPR of 9 or higher.');
      }
      if (adObjects) factors.push(plural(tierTotal[0], 'AD object is', 'AD objects are') + ' in Tier 0.');
      if (Number(a.sc_hosts)) factors.push(plural(a.not_joined, 'computer is', 'computers are') + ' not joined to the domain.');
      $('#dashFactors').html(factors.length ? factors.map(function (f) { return '<p>' + esc(f) + '</p>'; }).join('')
        : '<p class="muted">Nothing synced yet. Enter the connection details under Settings and run a sync.</p>');

      // sidebar: Did You Know
      var facts = [];
      if (adObjects) facts.push(['Tier 0 Reach', plural(tierTotal[0], 'AD object', 'AD objects') + ' can reach Tier 0. That is ' +
        Math.round(tierTotal[0] / adObjects * 100) + '% of the ' + num(adObjects) + ' synced objects.']);
      if (findings) facts.push(['Exploitable Findings', num(t.exploitable) + ' of ' + num(findings) + ' findings have a known exploit.']);
      if (Number(a.sc_hosts)) facts.push(['Domain Coverage', num(a.matched) + ' of ' + num(a.sc_hosts) + ' Security Center hosts match an AD computer by name.']);
      if (Number(ad.users)) facts.push(['Disabled Accounts', num(ad.users_disabled) + ' of ' + num(ad.users) + ' user accounts are disabled.']);
      if (!facts.length) facts.push(['Getting Started', 'Enter the Security Center and Identity Exposure connection details under Settings, then run a sync.']);
      function showFact(i) {
        $('#knowTitle').text(facts[i][0]); $('#knowText').text(facts[i][1]);
        $('#knowDots button').each(function (n) { $(this).toggleClass('active', n === i).attr('aria-selected', String(n === i)); });
      }
      $('#knowDots').html(facts.length > 1 ? facts.map(function (f, i) {
        return '<button type="button" role="tab" data-fact="' + i + '" aria-label="' + esc(f[0]) + '"></button>';
      }).join('') : '').on('click', 'button', function () { showFact(Number(this.dataset.fact)); });
      showFact(0);

      // sidebar: connectors
      function connector(code, title, href, job, stats) {
        var state = 'Not synced yet', ring = '';
        if (job) {
          var when = ago(job.finished_at || job.requested_at);
          if (job.status === 'succeeded') { state = 'Synced ' + when; ring = 'ok'; }
          else if (job.status === 'failed') { state = 'Last sync failed ' + when; ring = 'bad'; }
          else { state = job.status === 'running' ? 'Sync running' : 'Sync queued'; ring = 'run'; }
        }
        return '<a class="side-card" href="' + href + '"><div class="conn"><div class="ring ' + ring + '">' + code + '</div><div><h3>' + title +
          '</h3><div class="state">' + esc(state) + '</div></div></div><div class="stats">' + stats.map(function (s) {
            return '<div><span>' + s[0] + '</span><b>' + num(s[1]) + '</b></div>';
          }).join('') + '</div></a>';
      }
      $('#sideConnectors').html(
        connector('SC', 'Security Center', '/settings/security-center', sync.sc, [['Hosts', t.hosts], ['Findings', t.findings], ['Pools', t.pools]]) +
        connector('IE', 'Identity Exposure', '/settings/identity-exposure', sync.tie_postgres, [['Users', ad.users], ['Groups', ad.groups], ['Computers', ad.computers]]));

      // sidebar: one card per query
      var queries = d.by_query || [];
      $('#sidePools').html(queries.length ? queries.map(function (q) {
        var total = Number(q.total) || 0;
        return '<a class="side-card" href="/settings/security-center"><h3 title="' + esc(q.query) + '">' + esc(q.query) + '</h3><span class="tag" title="' + esc(q.pool) + '">' + esc(q.pool) +
          '</span><div class="bars"><div class="big">' + compact(total) + '</div><div>' + [[4, 'critical'], [3, 'high'], [2, 'medium']].map(function (s) {
            var n = Number(q[s[1]]) || 0;
            return '<div class="bar-row"><span class="label">' + SEV[s[0]][1] + ' Findings</span><span class="bar-track"><i style="width:' + (total ? n / total * 100 : 0) +
              '%;background:' + SEV_COLOR[s[0]] + '"></i></span><span>' + num(n) + '</span></div>';
          }).join('') + '</div></div><div class="stats"><div><span>Low</span><b>' + num(q.low) + '</b></div><div><span>Info</span><b>' + num(q.info) +
          '</b></div><div><span>Hosts</span><b>' + num(q.hosts) + '</b></div></div></a>';
      }).join('') : '<p class="muted mb-0">No pool yet. <a href="/settings/security-center">Add a pool and a query</a> to pull findings.</p>');

      // top vulnerabilities, as list rows
      $('#dashTopVpr').html((d.top_vpr || []).length ? d.top_vpr.map(function (v) {
        return '<div class="list-row"><span class="icon icon-accent i-goal"></span><div class="text"><div title="' + esc(v.plugin_name) + '">' + esc(v.plugin_name) +
          '</div><div class="small-text">Plugin ' + esc(v.plugin_id) + ' · ' + plural(v.hosts, 'host', 'hosts') + '</div></div><div class="meta">' +
          (v.exploit_available ? '<span class="badge-soft bad">Exploitable</span>' : '') + sevBadge(v.severity) + '<span class="vpr" title="VPR">' +
          esc(Number(v.vpr_score).toFixed(1)) + '</span></div></div>';
      }).join('') : '<div class="list-row flat"><span class="icon i-circle-add"></span><a href="/settings/security-center">No finding with VPR 9 or higher. Sync a Security Center pool.</a></div>');

      // severity legend and donut centre
      var order = [4, 3, 2, 1, 0], present = order.filter(function (s) { return sev[s]; });
      $('#sevLegend').append(order.map(function (s) {
        return '<div><i style="background:' + SEV_COLOR[s] + '"></i>' + SEV[s][1] + ' ' + num(sev[s]) + '</div>';
      }).join(''));
      var top = present.length ? present[0] : null;
      $('#donutGrade').text(findings ? compact(findings) : '0');
      $('#donutValue').html('<b>' + num(t.finding_hosts) + '</b> hosts');
      if (top !== null) {
        $('#donutCore').css({ background: SEV_COLOR[top], color: top >= 3 ? '#fff' : '#33363f',
          boxShadow: '0 0 0 7px #fff, 0 0 12px 12px ' + SEV_COLOR[top] + '66' });
      } else {
        $('#donutCore').css({ background: '#eceef1', boxShadow: '0 0 0 7px #fff' });
      }

      if (typeof Chart === 'undefined') return;
      Chart.defaults.font.family = 'Helvetica, Arial, sans-serif';
      Chart.defaults.font.size = 12;
      Chart.defaults.color = '#33363f';
      // Names outside the ring, as in the design.
      var outsideLabels = {
        id: 'outsideLabels',
        afterDatasetsDraw: function (chart) {
          var ctx = chart.ctx, meta = chart.getDatasetMeta(0);
          ctx.save(); ctx.fillStyle = '#33363f'; ctx.font = '12px Helvetica, Arial, sans-serif'; ctx.textBaseline = 'middle';
          meta.data.forEach(function (arc, i) {
            if (arc.endAngle - arc.startAngle < 0.12) return;
            var mid = (arc.startAngle + arc.endAngle) / 2, r = arc.outerRadius + 12;
            var x = arc.x + Math.cos(mid) * r, y = arc.y + Math.sin(mid) * r;
            ctx.textAlign = Math.cos(mid) > 0.25 ? 'left' : Math.cos(mid) < -0.25 ? 'right' : 'center';
            ctx.fillText(chart.data.labels[i], x, y);
          });
          ctx.restore();
        }
      };
      new Chart(document.getElementById('chartSeverity'), {
        type: 'doughnut',
        data: { labels: present.length ? present.map(function (s) { return SEV[s][1]; }) : ['No findings'],
          datasets: [{ data: present.length ? present.map(function (s) { return sev[s]; }) : [1],
            backgroundColor: present.length ? present.map(function (s) { return SEV_COLOR[s]; }) : ['#eceef1'], borderWidth: 2, borderColor: '#fff', hoverOffset: 0 }] },
        options: { responsive: false, cutout: '70%', layout: { padding: 48 }, plugins: { legend: { display: false }, tooltip: { enabled: present.length > 0 } } },
        plugins: [outsideLabels]
      });

      var rows = queries.filter(function (r) { return Number(r.total) > 0; });
      if (!rows.length) {
        $('#chartByQuery').parent().html('<p class="muted">No findings yet. Create a pool with a query under <a href="/settings/security-center">Settings</a> and sync it.</p>');
        return;
      }
      new Chart(document.getElementById('chartByQuery'), {
        type: 'bar',
        data: {
          labels: rows.map(function (r) { return r.query; }),
          datasets: [[4, 'critical'], [3, 'high'], [2, 'medium'], [1, 'low'], [0, 'info']].map(function (s) {
            return { label: SEV[s[0]][1], data: rows.map(function (r) { return Number(r[s[1]]); }), backgroundColor: SEV_COLOR[s[0]], borderRadius: 100, borderSkipped: false, maxBarThickness: 14 };
          })
        },
        options: { indexAxis: 'y', maintainAspectRatio: false,
          scales: { x: { stacked: true, border: { display: false }, grid: { color: '#d9dbe0' }, ticks: { callback: function (v) { return compact(v); } } },
            y: { stacked: true, border: { color: '#d9dbe0' }, grid: { display: false } } },
          plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, boxHeight: 12, usePointStyle: true, pointStyle: 'circle' } },
            tooltip: { callbacks: { title: function (items) { var r = rows[items[0].dataIndex]; return r.pool + ' / ' + r.query; } } } } }
      });
    }).fail(function (xhr) { $('.dash-card').prepend('<div class="alert alert-danger">' + esc(errText(xhr)) + '</div>'); });
  }

  // ---- detail panel (AD object or device) -------------------------------------------
  function showDetail(title, html, kind) {
    $('#detailTitle').text(title);
    $('#detailBody').html(html);
    $('#detailAnalyze').prop('hidden', !kind).attr('data-kind', kind || '');
    bootstrap.Offcanvas.getOrCreateInstance('#detailPanel').show();
  }
  function kv(pairs) {
    return '<div class="kv">' + pairs.filter(function (p) { return p[1] !== null && p[1] !== undefined && p[1] !== ''; }).map(function (p) {
      return '<div>' + esc(p[0]) + '</div><div>' + (p[2] ? p[1] : esc(p[1])) + '</div>';
    }).join('') + '</div>';
  }
  function openAdObject(type, id) {
    showDetail('Loading…', '');
    api('ad_object', { type: type, id: id }).done(function (o) {
      var html = kv([['Account', o.sam_account_name], ['Distinguished name', o.dn], ['SID', o.sid], ['Object GUID', o.object_guid],
        ['Tier', tierBadge(o.tier, o.tier_source) || 'none', true], ['Created', fmtDate(o.when_created)], ['Changed', fmtDate(o.when_changed)],
        ['Last synced', fmtDate(o.synced_at)]]);
      var detail = o.tier_detail;
      if (detail && detail.path && detail.path.length) {
        html += '<h3 class="fs-6 mt-4">Path to Tier 0 <span class="muted fw-normal">(' + esc(detail.hops) + ' hop' + (detail.hops === 1 ? '' : 's') + ')</span></h3><ol class="mb-0">' +
          detail.path.map(function (s) { return '<li>' + esc(s.from) + ' <span class="chip">' + esc(s.kind) + '</span> ' + esc(s.to) + '</li>'; }).join('') + '</ol>';
      } else if (detail && detail.origin === 'builtin') {
        html += '<p class="muted mt-3">Built-in privileged group.</p>';
      }
      var names = Object.keys(o.attributes || {}).sort();
      html += '<h3 class="fs-6 mt-4">Attributes <span class="muted fw-normal">(' + names.length + ')</span></h3>' + kv(names.map(function (n) {
        var v = o.attributes[n];
        var text = typeof v === 'object' ? JSON.stringify(v) : String(v);
        return [n, text.length > 600 ? text.slice(0, 600) + '…' : text];
      }));
      showDetail(o.name || o.sam_account_name || 'AD object', html, 'AD ' + String(type || 'object').replace(/_/g, ' ').replace(/s$/, ''));
    }).fail(function (xhr) { showDetail('Not available', '<div class="alert alert-danger">' + esc(errText(xhr)) + '</div>'); });
  }
  function openDevice(id, highlightPlugin) {
    showDetail('Loading…', '');
    api('device', { id: id }).done(function (h) {
      var html = kv([['IP address', h.ip], ['DNS name', h.dns_name], ['NetBIOS name', h.netbios_name], ['MAC address', h.mac_address], ['Operating system', h.os],
        ['Device type', h.device_type], ['Repository', h.repository_name],
        ['Domain', h.domain_joined ? 'Joined (' + esc(h.ad_sam_account_name || h.domain_name) + ')' : 'Not joined', true],
        ['Asset criticality (ACR)', h.acr_score], ['Asset exposure (AES)', h.aes_score], ['First seen', fmtDate(h.first_seen)], ['Last seen', fmtDate(h.last_seen)]]);
      html += '<h3 class="fs-6 mt-4">Vulnerabilities <span class="muted fw-normal">(' + num(h.vulnerability_count) + (h.vulnerability_count > h.vulnerabilities.length ? ', top ' + h.vulnerabilities.length + ' shown' : '') +
        ')</span></h3><table class="table"><thead><tr><th></th><th>Plugin</th><th>VPR</th><th>Port</th></tr></thead><tbody>' +
        h.vulnerabilities.map(function (v) {
          return '<tr' + (highlightPlugin && String(v.plugin_id) === String(highlightPlugin) ? ' class="table-active highlight"' : '') + '><td>' + sevBadge(v.severity) + '</td><td>' + esc(v.plugin_name) + '<div class="muted mono">' + esc(v.plugin_id) + (v.cve.length ? ' · ' + esc(v.cve.slice(0, 3).join(', ')) + (v.cve.length > 3 ? ' +' + (v.cve.length - 3) : '') : '') +
            '</div></td><td>' + (v.vpr_score === null ? '' : esc(Number(v.vpr_score).toFixed(1))) + '</td><td class="mono">' + (Number(v.port) ? esc(v.port) + '/' + esc(v.protocol) : '') + '</td></tr>';
        }).join('') + '</tbody></table>';
      showDetail(h.dns_name || h.ip || 'Host', html, 'host');
      if (highlightPlugin) setTimeout(function () { var el = document.querySelector('#detailBody tr.highlight'); if (el) el.scrollIntoView({ block: 'center' }); }, 350);
    }).fail(function (xhr) { showDetail('Not available', '<div class="alert alert-danger">' + esc(errText(xhr)) + '</div>'); });
  }

  // ---- column sets ---------------------------------------------------------------------
  function nameCell(r) { return '<b>' + esc(r.name || r.sam_account_name || '') + '</b>'; }
  /** "CN=x,OU=y,DC=tcorp,DC=local" -> "tcorp.local" */
  function domainOf(dn) { return (String(dn || '').match(/DC=[^,]+/gi) || []).map(function (p) { return p.slice(3); }).join('.'); }
  var DOMAIN_COL = ['Domain', null, function (r) { return '<span class="muted">' + esc(domainOf(r.dn)) + '</span>'; }];
  var AD_COLUMNS = {
    users: [['Name', 'name', nameCell], ['Account', 'sam_account_name', function (r) { return '<span class="mono">' + esc(r.sam_account_name) + '</span>'; }],
      ['Status', null, function (r) { return enabledBadge(r.enabled); }], ['Tier', 'tier', function (r) { return tierBadge(r.tier, r.tier_source); }],
      ['Admin', null, function (r) { return Number(r.admin_count) ? '<span class="badge-soft wait">adminCount</span>' : ''; }],
      ['Last logon', null, function (r) { return esc(fmtDay(r.last_logon)); }], ['Password set', null, function (r) { return esc(fmtDay(r.pwd_last_set)); }]],
    groups: [['Name', 'name', nameCell], ['Members', null, function (r) { return num(r.member_count); }], ['Tier', 'tier', function (r) { return tierBadge(r.tier, r.tier_source); }],
      ['Type', null, function (r) { return '<span class="muted">' + esc(String(r.group_type || '').toLowerCase().replace(/_/g, ' ')) + '</span>'; }],
      ['Changed', 'when_changed', function (r) { return esc(fmtDay(r.when_changed)); }]],
    computers: [['Name', 'name', nameCell], ['DNS name', null, function (r) { return esc(r.dns_host_name); }], ['Operating system', null, function (r) { return esc(r.os); }],
      ['Status', null, function (r) { return enabledBadge(r.enabled); }], ['Tier', 'tier', function (r) { return tierBadge(r.tier, r.tier_source); }],
      ['Last logon', null, function (r) { return esc(fmtDay(r.last_logon)); }]],
    group_policies: [['Name', 'name', function (r) { return '<b>' + esc(r.display_name || r.name) + '</b>'; }],
      ['Tier', 'tier', function (r) { return tierBadge(r.tier, r.tier_source); }],
      ['SYSVOL path', null, function (r) { return '<div class="truncate mono" title="' + esc(r.gpc_file_sys_path) + '">' + esc(r.gpc_file_sys_path) + '</div>'; }],
      ['Changed', 'when_changed', function (r) { return esc(fmtDay(r.when_changed)); }]]
  };
  ['users', 'groups', 'computers', 'group_policies'].forEach(function (t) { AD_COLUMNS[t].splice(t === 'users' ? 2 : 1, 0, DOMAIN_COL); });
  AD_COLUMNS.service_accounts = AD_COLUMNS.users.slice();
  AD_COLUMNS.service_accounts.splice(3, 0, ['Kind', null, function (r) { return '<span class="chip">' + (r.account_type === 'gmsa' ? 'Managed (gMSA)' : 'Has SPN') + '</span>'; }]);
  AD_COLUMNS.all_users = AD_COLUMNS.users.slice();
  AD_COLUMNS.all_users.splice(3, 0, ['Kind', null, function (r) { return r.account_type === 'user' ? '' : '<span class="chip">Service account</span>'; }]);

  function countCell(n) { n = Number(n); return '<span class="count-pill' + (n ? '' : ' zero') + '">' + num(n) + '</span>'; }
  var SOURCE_LABEL = { 'sc': 'Security Center', 'ad': 'AD', 'sc+ad': 'Security Center + AD' };
  var DEVICE_COLUMNS = [['Name', 'name', nameCell], ['IP address', 'ip', function (r) { return '<span class="mono">' + esc(r.ip) + '</span>'; }],
    ['Operating system', 'os', function (r) { return '<div class="truncate" style="max-width:260px" title="' + esc(r.os) + '">' + esc(r.os) + '</div>'; }],
    ['Seen by', null, function (r) { return '<span class="chip">' + SOURCE_LABEL[r.source] + '</span>'; }],
    ['Domain', null, function (r) { return r.domain_joined ? '<span class="badge-soft ok">Joined</span>' : '<span class="badge-soft">Not joined</span>'; }],
    ['C', 'critical', function (r) { return countCell(r.critical); }], ['H', 'high', function (r) { return countCell(r.high); }],
    ['M', null, function (r) { return countCell(r.medium); }], ['L', null, function (r) { return countCell(r.low); }],
    ['Last seen', 'last_seen', function (r) { return esc(fmtDay(r.last_seen)); }]];
  var IOT_COLUMNS = DEVICE_COLUMNS.slice();
  IOT_COLUMNS.splice(3, 2, ['Device type', null, function (r) { return '<span class="chip">' + esc(String(r.device_type || '').replace(/_/g, ' ')) + '</span>'; }]);

  function renderHead($head, columns, state, selectable) {
    $head.html('<tr>' + (selectable ? '<th style="width:34px"><input type="checkbox" class="form-check-input" id="selAll" aria-label="Select all on this page"></th>' : '') +
      columns.map(function (c) {
        return c[1] ? '<th class="sortable" data-sort="' + c[1] + '"' + (state.sort === c[1] ? ' data-dir="' + state.dir + '"' : '') + '>' + c[0] + '</th>' : '<th>' + c[0] + '</th>';
      }).join('') + '</tr>');
  }
  function bindSort($head, state, reload) {
    $head.off('click').on('click', 'th.sortable', function () {
      var key = this.dataset.sort;
      state.dir = state.sort === key && state.dir === 'asc' ? 'desc' : 'asc';
      state.sort = key; state.page = 1; reload();
    });
  }
  function setActive($group, attr, value) {
    $group.find('[data-' + attr + ']').each(function () { $(this).toggleClass('active', this.dataset[attr] === String(value)); });
  }

  // ---- Explore: Asset Inventory -----------------------------------------------------------
  function initAssets() {
    var q = query();
    var state = { type: ['users', 'devices', 'iot'].indexOf(q.get('type')) >= 0 ? q.get('type') : 'devices', source: '', q: q.get('q') || '', page: 1, size: 50, sort: '', dir: 'asc' };
    var $root = $('#assets');
    $('#assetSearch').val(state.q);
    if (q.get('host')) openDevice(Number(q.get('host')), q.get('plugin'));   // deep link from Agent Tenable

    function columns() { return state.type === 'users' ? AD_COLUMNS.all_users : state.type === 'iot' ? IOT_COLUMNS : DEVICE_COLUMNS; }
    function load() {
      var cols = columns();
      setActive($root, 'type', state.type); setActive($root, 'source', state.source);
      $('#assetSourceGroup').toggle(state.type === 'devices');
      renderHead($('#assetHead'), cols, state, false);
      $('#assetBody').html(loadingRow(cols.length));
      var request = state.type === 'users'
        ? api('ad_objects', { type: 'all_users', q: state.q, page: state.page, size: state.size, sort: state.sort, dir: state.dir })
        : api('devices', { 'class': state.type === 'iot' ? 'iot' : 'device', source: state.source, q: state.q, page: state.page, size: state.size, sort: state.sort, dir: state.dir });
      request.done(function (d) {
        $('#assetBody').html(d.rows.length ? d.rows.map(function (r) {
          var open = state.type === 'users' ? 'data-ad="' + esc(r.id) + '"' : (r.host_id ? 'data-host="' + esc(r.host_id) + '"' : 'data-computer="' + esc(r.ad_computer_id) + '"');
          return '<tr class="clickable" tabindex="0" ' + open + '>' + cols.map(function (c) { return '<td>' + c[2](r) + '</td>'; }).join('') + '</tr>';
        }).join('') : emptyRow(cols.length, state.q || state.source ? 'Nothing matches these filters.' :
          'No assets yet. Run a sync under <a href="/settings/security-center">Settings</a>.'));
        renderPager($('#assetPager'), state, d.total, load);
        $root.find('[data-count="' + state.type + '"]').text(state.q || state.source ? '' : num(d.total));
      }).fail(function (xhr) { $('#assetBody').html(emptyRow(cols.length, esc(errText(xhr)))); });
    }
    function counts() {
      api('ad_objects', { type: 'all_users', size: 10 }).done(function (d) { $root.find('[data-count="users"]').text(num(d.total)); });
      api('devices', { 'class': 'device', size: 10 }).done(function (d) { $root.find('[data-count="devices"]').text(num(d.total)); });
      api('devices', { 'class': 'iot', size: 10 }).done(function (d) { $root.find('[data-count="iot"]').text(num(d.total)); });
    }
    $root.on('click', '[data-type]', function () { state.type = this.dataset.type; state.page = 1; state.sort = ''; state.source = ''; load(); });
    $root.on('click', '[data-source]', function () { state.source = this.dataset.source; state.page = 1; load(); });
    $('#assetSearch').on('input', debounce(function () { state.q = this.value.trim(); state.page = 1; load(); }, 300));
    $('#assetBody').on('click keydown', 'tr.clickable', function (e) {
      if (e.type === 'keydown' && e.key !== 'Enter') return;
      if (this.dataset.host) openDevice(this.dataset.host);
      else if (this.dataset.ad) openAdObject('all_users', this.dataset.ad);
      else if (this.dataset.computer) openAdObject('computers', this.dataset.computer);
    });
    bindSort($('#assetHead'), state, load);
    counts(); load();
  }

  // ---- Explore: AD Objects ---------------------------------------------------------------------
  function initAd() {
    var q = query();
    var state = { type: AD_COLUMNS[q.get('type')] && q.get('type') !== 'all_users' ? q.get('type') : 'users', tier: q.get('tier') || '', enabled: q.get('enabled') || '',
      q: q.get('q') || '', page: 1, size: 50, sort: 'name', dir: 'asc' };
    var selected = {};
    var $root = $('#ad');
    $('#adSearch').val(state.q);
    if (q.get('open')) openAdObject(state.type, Number(q.get('open')));   // deep link from Agent Tenable

    function updateSelection() {
      var n = Object.keys(selected).length;
      $('#adTierBtn').prop('disabled', !n);
      $('#adSelected').text(n ? n + ' selected' : '');
    }
    function load() {
      var cols = AD_COLUMNS[state.type];
      setActive($root, 'type', state.type); setActive($root, 'tier', state.tier); setActive($root, 'enabled', state.enabled);
      $('#adEnabledGroup').toggle(['users', 'service_accounts', 'computers'].indexOf(state.type) >= 0);
      renderHead($('#adHead'), cols, state, true);
      $('#adBody').html(loadingRow(cols.length + 1));
      api('ad_objects', { type: state.type, tier: state.tier, enabled: state.enabled, q: state.q, page: state.page, size: state.size, sort: state.sort, dir: state.dir }).done(function (d) {
        $('#adBody').html(d.rows.length ? d.rows.map(function (r) {
          return '<tr class="clickable" tabindex="0" data-id="' + esc(r.id) + '"><td class="sel"><input type="checkbox" class="form-check-input row-sel" value="' + esc(r.object_key) + '"' +
            (selected[r.object_key] ? ' checked' : '') + ' aria-label="Select ' + esc(r.name) + '"></td>' + cols.map(function (c) { return '<td>' + c[2](r) + '</td>'; }).join('') + '</tr>';
        }).join('') : emptyRow(cols.length + 1, state.q || state.tier || state.enabled ? 'Nothing matches these filters.' :
          'No objects yet. Run a sync under <a href="/settings/identity-exposure">Settings</a>.'));
        renderPager($('#adPager'), state, d.total, load);
      }).fail(function (xhr) { $('#adBody').html(emptyRow(cols.length + 1, esc(errText(xhr)))); });
    }
    $root.on('click', '[data-type]', function () { state.type = this.dataset.type; state.page = 1; state.sort = 'name'; state.dir = 'asc'; state.enabled = ''; selected = {}; updateSelection(); load(); });
    $root.on('click', '[data-tier]', function () { state.tier = this.dataset.tier; state.page = 1; load(); });
    $root.on('click', '[data-enabled]', function () { state.enabled = this.dataset.enabled; state.page = 1; load(); });
    $('#adSearch').on('input', debounce(function () { state.q = this.value.trim(); state.page = 1; load(); }, 300));
    $('#adBody').on('change', '.row-sel', function () { if (this.checked) selected[this.value] = true; else delete selected[this.value]; updateSelection(); });
    $('#adHead').on('change', '#selAll', function () {
      var on = this.checked;
      $('#adBody .row-sel').each(function () { this.checked = on; if (on) selected[this.value] = true; else delete selected[this.value]; });
      updateSelection();
    });
    $('#adBody').on('click keydown', 'tr.clickable', function (e) {
      if ($(e.target).closest('.sel').length || (e.type === 'keydown' && e.key !== 'Enter')) return;
      openAdObject(state.type, this.dataset.id);
    });
    $root.on('click', '[data-set-tier]', function () {
      var tier = this.dataset.setTier, keys = Object.keys(selected);
      post('ad_tier_set', { tier: tier, keys: keys }).done(function (d) {
        toast(d.changed + ' object' + (d.changed === 1 ? '' : 's') + ' updated.');
        selected = {}; updateSelection(); load();
      }).fail(function (xhr) { toast(errText(xhr), 'error'); });
    });
    bindSort($('#adHead'), state, load);
    load();
  }

  // ---- job text shared by the settings pages --------------------------------------------------
  var WAIT_NOTE = 'Queued. The connector checks for work every 30 seconds.';
  function logLink(connector, job) {
    return ' <a href="/logs?connector=' + connector + '&job_id=' + esc(job.id) + '">View log</a>';
  }

  // ---- Settings: Security Center ------------------------------------------------------------------
  function initSettingsSc() {
    var meta = { repositories: [], families: [] };
    var pools = [];
    var pollTimer = null;

    function showMeta(m) {
      meta = m;
      if (m.ok === true) {
        $('#scStatus').html('<span class="badge-soft ok">Connected</span> as ' + esc(m.username) + ' (' + esc(m.role) + '), ' + m.repositories.length +
          ' repositories. <span class="muted">Tested ' + esc(ago(m.tested_at)) + '.</span>');
      } else if (m.ok === false) {
        $('#scStatus').html('<span class="badge-soft bad">Failed</span> ' + esc(m.error) + ' <span class="muted">Tested ' + esc(ago(m.tested_at)) + '.</span>');
      }
    }
    api('sc_meta').done(showMeta);

    function save() {
      return post('sc_settings_save', $('#scForm').serialize()).done(function (s) {
        $('#sc_access_key, #sc_secret_key').val('');
        $('#sc_access_key').attr('placeholder', s.access_key_set ? 'Saved. Leave empty to keep it.' : 'Not set');
        $('#sc_secret_key').attr('placeholder', s.secret_key_set ? 'Saved. Leave empty to keep it.' : 'Not set');
      });
    }
    $('#scForm').on('submit', function (e) {
      e.preventDefault();
      save().done(function () { $('#scStatus').html('<span class="badge-soft ok">Saved</span>'); toast('Security Center settings saved.'); })
        .fail(function (xhr) { $('#scStatus').html('<span class="text-danger">' + esc(errText(xhr)) + '</span>'); });
    });
    $('#scTest').on('click', function () {
      var $btn = $(this).prop('disabled', true);
      $('#scStatus').html('<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Saving and testing…');
      save().then(function () { return post('sc_test'); }).done(function (r) {
        $('#scStatus').html('<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>' + WAIT_NOTE);
        watchJob(r.id, function (job) {
          if (job.status === 'running') $('#scStatus').html('<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Contacting Security Center…');
        }, function (job) {
          $btn.prop('disabled', false);
          api('sc_meta').done(function (m) {
            showMeta(m);
            if (job.status === 'failed' && m.ok !== false) $('#scStatus').html('<span class="badge-soft bad">Failed</span> ' + esc(job.error) + logLink('sc', job));
          });
        });
      }).fail(function (xhr) { $btn.prop('disabled', false); $('#scStatus').html('<span class="text-danger">' + esc(errText(xhr)) + '</span>'); });
    });

    // -- pools --
    function filterChips(f) {
      var chips = [];
      if (f.severity && f.severity.length) chips.push('Severity: ' + f.severity.slice().sort().reverse().map(function (s) { return SEV[s][1]; }).join(', '));
      [['vpr', 'VPR'], ['cvss_v3', 'CVSS v3'], ['acr', 'ACR']].forEach(function (k) {
        var lo = f[k[0] + '_min'], hi = f[k[0] + '_max'];
        if (lo !== undefined || hi !== undefined) chips.push(k[1] + ' ' + (lo === undefined ? 0 : lo) + ' to ' + (hi === undefined ? 10 : hi));
      });
      if (f.ip_range) chips.push('IP: ' + f.ip_range);
      if (f.plugin_ids) chips.push('Plugins: ' + f.plugin_ids);
      if (f.name_contains) chips.push('Name contains: ' + f.name_contains);
      if (f.repositories && f.repositories.length) chips.push('Repositories: ' + f.repositories.map(function (r) { return r.name || r.id; }).join(', '));
      if (f.families && f.families.length) chips.push(f.families.length + ' plugin famil' + (f.families.length === 1 ? 'y' : 'ies'));
      if (f.exploit_only) chips.push('Exploitable only');
      return chips.length ? chips.map(function (c) { return '<span class="chip">' + esc(c) + '</span>'; }).join('') : '<span class="muted">No filter: every cumulative finding</span>';
    }
    function jobLine(job) {
      if (!job) return 'Never synced.';
      var p = job.progress || {}, s = job.stats || {};
      if (job.status === 'queued') return statusBadge('queued') + ' ' + WAIT_NOTE;
      if (job.status === 'running') {
        var total = Number(p.total) || 0, fetched = Number(p.fetched) || 0;
        var pct = total ? Math.min(100, Math.round(fetched / total * 100)) : 0;
        return statusBadge('running') + ' ' + (p.query ? 'Query ' + esc(p.query_index) + ' of ' + esc(p.query_count) + ' (' + esc(p.query) + '): ' : '') +
          (total ? num(fetched) + ' of ' + num(total) + ' findings' : 'starting…') +
          '<div class="progress" role="progressbar" aria-label="Sync progress" aria-valuenow="' + pct + '" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar" style="width:' + pct + '%"></div></div>';
      }
      var when = ' <span class="muted">' + esc(ago(job.finished_at)) + (job.trigger === 'schedule' ? ', scheduled' : '') + '</span>';
      if (job.status === 'failed') return statusBadge('failed') + when + ' ' + errSpan(job.error) + logLink('sc', job);
      return statusBadge('succeeded') + when + ' ' + num(s.findings_fetched) + ' findings fetched' +
        (Number(s.findings_deleted) ? ', ' + num(s.findings_deleted) + ' removed' : '') + '.' + logLink('sc', job);
    }
    function renderPools() {
      if (!pools.length) {
        $('#poolList').html('<div class="empty-state"><h2>No pool yet</h2><p>A pool groups the Security Center queries that are pulled together. Add a pool, then add queries to it.</p></div>');
        return;
      }
      $('#poolList').html(pools.map(function (p) {
        var busy = p.last_job && (p.last_job.status === 'queued' || p.last_job.status === 'running');
        return '<div class="pool" data-pool="' + esc(p.id) + '"><div class="pool-head"><span class="name">' + esc(p.name) + '</span>' +
          (p.enabled ? '' : '<span class="badge-soft">Disabled</span>') +
          '<span class="badge-soft">' + (Number(p.sync_interval_minutes) ? 'Every ' + esc(p.sync_interval_minutes) + ' min' : 'Manual sync') + '</span>' +
          '<span class="actions"><button type="button" class="btn btn-sm btn-primary" data-act="sync"' + (busy ? ' disabled' : '') + '>Sync now</button>' +
          '<button type="button" class="btn btn-sm btn-outline-primary" data-act="add-query">Add query</button>' +
          '<button type="button" class="btn btn-sm btn-outline-secondary" data-act="edit">Edit</button>' +
          '<button type="button" class="btn btn-sm btn-outline-danger" data-act="delete"' + (busy ? ' disabled' : '') + '>Delete</button></span></div>' +
          (p.description ? '<div class="pool-status">' + esc(p.description) + '</div>' : '') +
          '<div class="pool-status">' + jobLine(p.last_job) + '</div>' +
          (p.queries.length ? '<div class="table-wrap"><table class="table"><thead><tr><th>Query</th><th>Filters</th><th class="text-end">Findings</th><th>Last sync</th><th></th></tr></thead><tbody>' +
            p.queries.map(function (q) {
              return '<tr data-query="' + esc(q.id) + '"><td><b>' + esc(q.name) + '</b>' + (q.enabled ? '' : ' <span class="badge-soft">Disabled</span>') +
                (Number(q.max_records) ? '<div class="muted">Limit ' + num(q.max_records) + '</div>' : '') + '</td><td>' + filterChips(q.filters || {}) + '</td><td class="text-end">' +
                num(q.findings) + (q.last_total !== null && Number(q.last_total) > Number(q.last_fetched) ? '<div class="muted">of ' + num(q.last_total) + '</div>' : '') +
                '</td><td class="muted">' + (q.last_sync_at ? esc(ago(q.last_sync_at)) : 'never') + '</td><td class="text-end text-nowrap">' +
                '<button type="button" class="btn btn-sm btn-outline-secondary" data-act="edit-query">Edit</button> ' +
                '<button type="button" class="btn btn-sm btn-outline-danger" data-act="delete-query"' + (busy ? ' disabled' : '') + '>Delete</button></td></tr>';
            }).join('') + '</tbody></table></div>'
            : '<div class="pool-status">This pool has no query. Add one to pull findings.</div>') + '</div>';
      }).join(''));
    }
    function loadPools() {
      clearTimeout(pollTimer);
      return api('pools').done(function (d) {
        pools = d.pools;
        // Do not redraw under an open modal or a focused button; just keep polling.
        if (!$('.modal.show').length) renderPools();
        var busy = pools.some(function (p) { return p.last_job && (p.last_job.status === 'queued' || p.last_job.status === 'running'); });
        pollTimer = setTimeout(loadPools, busy ? 2500 : 20000);
      }).fail(function (xhr) { $('#poolList').html('<div class="alert alert-danger mb-0">' + esc(errText(xhr)) + '</div>'); });
    }
    function findPool(id) { return pools.filter(function (p) { return String(p.id) === String(id); })[0]; }

    $('#poolAdd').on('click', function () { openPool(null); });
    function openPool(pool) {
      var f = $('#poolForm')[0];
      f.reset(); $('#poolError').text('');
      f.elements.id.value = pool ? pool.id : '';
      $('#poolModalTitle').text(pool ? 'Edit pool' : 'Add pool');
      if (pool) {
        f.elements.name.value = pool.name; f.elements.description.value = pool.description;
        f.elements.sync_interval_minutes.value = pool.sync_interval_minutes; f.elements.enabled.checked = !!pool.enabled;
      }
      bootstrap.Modal.getOrCreateInstance('#poolModal').show();
    }
    $('#poolForm').on('submit', function (e) {
      e.preventDefault();
      post('pool_save', $(this).serialize()).done(function () {
        bootstrap.Modal.getInstance('#poolModal').hide(); toast('Pool saved.'); loadPools();
      }).fail(function (xhr) { $('#poolError').text(errText(xhr)); });
    });

    function pickList($el, items, chosen) {
      var ids = {};
      (chosen || []).forEach(function (c) { ids[c.id] = true; });
      $el.html(items.length ? items.map(function (i) {
        return '<label data-name="' + esc(String(i.name).toLowerCase()) + '"><input type="checkbox" class="form-check-input m-0" value="' + esc(i.id) + '" data-label="' + esc(i.name) + '"' +
          (ids[i.id] ? ' checked' : '') + '>' + esc(i.name) + '</label>';
      }).join('') : '<span class="muted">Not loaded</span>');
    }
    function picked($el) {
      return $el.find('input:checked').map(function () { return { id: this.value, name: this.dataset.label }; }).get();
    }
    function openQuery(pool, q) {
      var f = $('#queryForm')[0], filters = (q && q.filters) || {};
      f.reset(); $('#queryError').text(''); $('#q_family_search').val('');
      f.elements.id.value = q ? q.id : ''; f.elements.pool_id.value = pool.id;
      $('#queryModalTitle').text((q ? 'Edit query' : 'Add query') + ' · ' + pool.name);
      if (q) { f.elements.name.value = q.name; f.elements.max_records.value = q.max_records; f.elements.enabled.checked = !!q.enabled; }
      $('.q-sev').each(function () { this.checked = (filters.severity || []).indexOf(this.value) >= 0; });
      ['vpr', 'cvss_v3', 'acr'].forEach(function (k) {
        $('#q_' + k + '_min').val(filters[k + '_min'] === undefined ? '' : filters[k + '_min']);
        $('#q_' + k + '_max').val(filters[k + '_max'] === undefined ? '' : filters[k + '_max']);
      });
      $('#q_ip').val(filters.ip_range || ''); $('#q_plugins').val(filters.plugin_ids || ''); $('#q_name_contains').val(filters.name_contains || '');
      $('#q_exploit').prop('checked', !!filters.exploit_only);
      pickList($('#q_repositories'), meta.repositories || [], filters.repositories);
      pickList($('#q_families'), meta.families || [], filters.families);
      $('#queryMetaHint').text((meta.repositories || []).length ? '' : 'Repositories and plugin families are loaded by "Test connection".');
      bootstrap.Modal.getOrCreateInstance('#queryModal').show();
    }
    $('#q_family_search').on('input', function () {
      var needle = this.value.trim().toLowerCase();
      $('#q_families label').each(function () { $(this).toggle(!needle || this.dataset.name.indexOf(needle) >= 0); });
    });
    $('#queryForm').on('submit', function (e) {
      e.preventDefault();
      var f = this, filters = {
        severity: $('.q-sev:checked').map(function () { return this.value; }).get(),
        ip_range: $('#q_ip').val(), plugin_ids: $('#q_plugins').val(), name_contains: $('#q_name_contains').val(),
        repositories: picked($('#q_repositories')), families: picked($('#q_families')), exploit_only: $('#q_exploit').prop('checked')
      };
      ['vpr', 'cvss_v3', 'acr'].forEach(function (k) {
        ['min', 'max'].forEach(function (m) { var v = $('#q_' + k + '_' + m).val(); if (v !== '') filters[k + '_' + m] = Number(v); });
      });
      post('query_save', { id: f.elements.id.value, pool_id: f.elements.pool_id.value, name: f.elements.name.value, max_records: f.elements.max_records.value,
        enabled: f.elements.enabled.checked ? '1' : '', filters: JSON.stringify(filters) }).done(function () {
        bootstrap.Modal.getInstance('#queryModal').hide(); toast('Query saved. Sync the pool to apply it.'); loadPools();
      }).fail(function (xhr) { $('#queryError').text(errText(xhr)); });
    });
    $('#poolModal, #queryModal, #confirmModal').on('hidden.bs.modal', renderPools);

    $('#poolList').on('click', '[data-act]', function () {
      var pool = findPool($(this).closest('.pool').data('pool')), act = this.dataset.act;
      if (!pool) return;
      var queryId = $(this).closest('tr').data('query');
      var q = pool.queries.filter(function (x) { return String(x.id) === String(queryId); })[0];
      if (act === 'edit') openPool(pool);
      else if (act === 'add-query') openQuery(pool, null);
      else if (act === 'edit-query') openQuery(pool, q);
      else if (act === 'sync') {
        $(this).prop('disabled', true);
        post('pool_sync', { id: pool.id }).done(function () { toast('Sync queued for ' + pool.name + '.'); loadPools(); })
          .fail(function (xhr) { toast(errText(xhr), 'error'); loadPools(); });
      } else if (act === 'delete') {
        confirmAction('Delete pool', 'Delete "' + pool.name + '" and its ' + pool.queries.length + ' quer' + (pool.queries.length === 1 ? 'y' : 'ies') +
          '? Findings that no other query returns are removed too.', 'Delete pool', function () {
          post('pool_delete', { id: pool.id }).done(function (r) { toast('Pool deleted. ' + num(r.findings_deleted) + ' findings removed.'); loadPools(); })
            .fail(function (xhr) { toast(errText(xhr), 'error'); });
        });
      } else if (act === 'delete-query') {
        confirmAction('Delete query', 'Delete "' + q.name + '"? Findings that no other query returns are removed too.', 'Delete query', function () {
          post('query_delete', { id: q.id }).done(function (r) { toast('Query deleted. ' + num(r.findings_deleted) + ' findings removed.'); loadPools(); })
            .fail(function (xhr) { toast(errText(xhr), 'error'); });
        });
      }
    });
    loadPools();
  }

  // ---- Settings: Identity Exposure --------------------------------------------------------------------
  function initSettingsTie() {
    var timer = null;
    function summary(job) {
      var s = job.stats || {};
      if (job.status === 'failed') return errSpan(job.error) + logLink('tie_postgres', job);
      if (job.status !== 'succeeded') return job.status === 'queued' ? WAIT_NOTE : esc(String((job.progress || {}).step || 'starting').replace(/_/g, ' '));
      if (job.kind === 'test_connection') return 'Connector ' + esc(s.state) + ', ' + num(s.object_count) + ' objects in its snapshot';
      return num(s.ad_users) + ' users, ' + num(s.ad_groups) + ' groups, ' + num(s.ad_computers) + ' computers, ' + num(s.ad_group_policies) + ' policies; ' +
        num(s.deleted) + ' removed' + (s.delete_skipped ? ' (delete skipped)' : '') + logLink('tie_postgres', job);
    }
    function load() {
      clearTimeout(timer);
      api('tie_overview').done(function (d) {
        var c = d.counts || {};
        $('#tieCounts').html([['Users', c.users], ['Service accounts', c.service_accounts], ['Groups', c.groups], ['Computers', c.computers],
          ['Group policies', c.group_policies], ['Tier 0 objects', c.tier0]].map(function (s) {
          return '<div class="stat"><div class="n">' + num(s[1]) + '</div><div class="l">' + s[0] + '</div></div>';
        }).join(''));
        $('#tieJobs').html(d.jobs.length ? d.jobs.map(function (j) {
          return '<tr><td class="mono">#' + esc(j.id) + '</td><td>' + (j.kind === 'sync' ? 'Sync' : 'Connection test') + (j.trigger === 'schedule' ? ' <span class="chip">scheduled</span>' : '') +
            '</td><td>' + statusBadge(j.status) + '</td><td class="muted text-nowrap">' + esc(fmtDate(j.started_at || j.requested_at)) + '</td><td class="text-nowrap">' +
            esc(duration(j.duration_seconds)) + '</td><td>' + summary(j) + '</td></tr>';
        }).join('') : emptyRow(6, 'No job yet. Use "Sync now" to pull AD objects.'));
        var busy = d.jobs.some(function (j) { return j.status === 'queued' || j.status === 'running'; });
        $('#tieSync').prop('disabled', d.jobs.some(function (j) { return j.kind === 'sync' && (j.status === 'queued' || j.status === 'running'); }));
        if ($('#tieStatus').is(':empty') && d.meta.ok !== null) {
          $('#tieStatus').html(d.meta.ok ? '<span class="badge-soft ok">Connected</span> Connector ' + esc(d.meta.state) + '. <span class="muted">Tested ' + esc(ago(d.meta.tested_at)) + '.</span>'
            : '<span class="badge-soft bad">Failed</span> ' + esc(d.meta.error));
        }
        timer = setTimeout(load, busy ? 2500 : 20000);
      });
    }
    function save() { return post('tie_settings_save', $('#tieForm').serialize()).done(function (s) {
      $('#tie_token').val('').attr('placeholder', s.token_set ? 'Saved. Leave empty to keep it.' : 'Using the token file of this stack');
    }); }
    $('#tieForm').on('submit', function (e) {
      e.preventDefault();
      save().done(function () { $('#tieStatus').html('<span class="badge-soft ok">Saved</span>'); toast('Identity Exposure settings saved.'); })
        .fail(function (xhr) { $('#tieStatus').html('<span class="text-danger">' + esc(errText(xhr)) + '</span>'); });
    });
    $('#tieTest').on('click', function () {
      var $btn = $(this).prop('disabled', true);
      $('#tieStatus').html('<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Saving and testing…');
      save().then(function () { return post('tie_test'); }).done(function (r) {
        $('#tieStatus').html('<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>' + WAIT_NOTE);
        load();
        watchJob(r.id, null, function (job) {
          $btn.prop('disabled', false);
          $('#tieStatus').html(job.status === 'succeeded'
            ? '<span class="badge-soft ok">Connected</span> Connector ' + esc(job.stats.state) + ', ' + num(job.stats.object_count) + ' objects in its snapshot.'
            : '<span class="badge-soft bad">Failed</span> ' + esc(job.error) + logLink('tie_postgres', job));
          load();
        });
      }).fail(function (xhr) { $btn.prop('disabled', false); $('#tieStatus').html('<span class="text-danger">' + esc(errText(xhr)) + '</span>'); });
    });
    $('#tieSync').on('click', function () {
      $(this).prop('disabled', true);
      post('tie_sync').done(function () { toast('AD sync queued.'); load(); }).fail(function (xhr) { toast(errText(xhr), 'error'); load(); });
    });
    load();
  }

  // ---- Logs --------------------------------------------------------------------------------------------
  function initLogs() {
    var q = query();
    var state = { connector: $('#logTabs [data-connector="' + (q.get('connector') || '') + '"]').length ? q.get('connector') : $('#logTabs [data-connector]').first().data('connector'),
      jobId: q.get('job_id') || '' };
    var timer = null;
    var HIDDEN = { timestamp: 1, level: 1, event: 1, connector: 1, traceback: 1 };
    if (state.jobId) $('#logSearch').val('job:' + state.jobId);

    function load() {
      clearTimeout(timer);
      $('#logTabs [data-connector]').each(function () { $(this).toggleClass('active', this.dataset.connector === state.connector); });
      $('#logDownload').attr('href', '/api.php?action=logs_download&connector=' + encodeURIComponent(state.connector));
      var text = $('#logSearch').val().trim(), jobMatch = /^job:(\d+)$/.exec(text);
      api('logs', { connector: state.connector, level: $('#logLevel').val(), lines: $('#logLines').val(), q: jobMatch ? '' : text, job_id: jobMatch ? jobMatch[1] : '' }).done(function (d) {
        $('#logInfo').text(d.file.files ? (d.file.bytes / 1024).toFixed(0) + ' KB in ' + d.file.files + ' file' + (d.file.files === 1 ? '' : 's') : '');
        $('#logBody').html(d.entries.length ? d.entries.map(function (e) {
          var level = String(e.level || 'info').toLowerCase();
          var fields = Object.keys(e).filter(function (k) { return !HIDDEN[k]; }).map(function (k) {
            var v = e[k];
            return '<b>' + esc(k) + '</b>=' + esc(typeof v === 'object' && v !== null ? JSON.stringify(v) : v);
          }).join(' &nbsp; ');
          return '<tr><td class="ts mono">' + esc(fmtDate(e.timestamp, true)) + '</td><td><span class="lvl lvl-' + esc(level) + '">' + esc(level) + '</span></td><td class="mono">' +
            esc(e.event) + '</td><td class="log-fields">' + fields + (e.traceback ? '<details><summary>Traceback</summary><pre>' + esc(e.traceback) + '</pre></details>' : '') + '</td></tr>';
        }).join('') : emptyRow(4, d.file.files ? 'No log entry matches these filters.' : 'This connector has not written a log file yet.'));
      }).fail(function (xhr) { $('#logBody').html(emptyRow(4, esc(errText(xhr)))); })
        .always(function () { if ($('#logAuto').prop('checked')) timer = setTimeout(load, 5000); });
    }
    $('#logTabs').on('click', '[data-connector]', function () { state.connector = this.dataset.connector; load(); });
    $('#logLevel, #logLines, #logAuto').on('change', load);
    $('#logSearch').on('input', debounce(load, 300));
    $('#logRefresh').on('click', load);
    load();
  }

  // ---- Risk -----------------------------------------------------------------------------------------------
  var ACCESS = { session: 'Session', last_logon: 'Last logon', smb_session: 'SMB session', service_account: 'Service',
    local_admin: 'Local admin', rdp_right: 'RDP right', psremote_right: 'Remote PS right', scan_account: 'Scan account' };
  function accessChips(list) { return String(list || '').split(',').filter(Boolean).map(function (a) { return '<span class="chip">' + esc(ACCESS[a] || a) + '</span>'; }).join(''); }
  function identityCell(r) {
    return '<b>' + esc(r.name) + '</b>' + (r.confidence === 'low' ? ' <span class="badge-soft wait" title="Name only; the domain is not known">unverified</span>' : '');
  }
  function tierCell(r) {
    if (r.tier !== null && r.tier !== undefined) return tierBadge(r.tier);
    return r.privileged_hint ? '<span class="badge-soft tier-0" title="Well-known privileged SID or group; not matched to TIE">Privileged</span>' : '';
  }
  var RISK_VIEWS = {
    critical_users: { title: 'Users Reaching Hosts with Critical Vulnerabilities', classes: true, cols: [
      ['User', 'name', identityCell], ['Tier', null, tierCell], ['Access', null, function (r) { return accessChips(r.access); }], ['Hosts', 'hosts', function (r) { return num(r.hosts); }],
      ['Worst host', null, function (r) { return esc(r.worst_host); }], ['Critical', 'critical', function (r) { return num(r.critical); }],
      ['Top VPR', 'vpr', function (r) { return r.max_vpr === null ? '' : esc(Number(r.max_vpr).toFixed(1)); }], ['Last seen', 'last_seen', function (r) { return esc(fmtDay(r.last_seen)); }]] },
    acr_users: { title: 'Users Reaching High-ACR Hosts', classes: true, cols: [
      ['User', 'name', identityCell], ['Tier', null, tierCell], ['Access', null, function (r) { return accessChips(r.access); }], ['Hosts', 'hosts', function (r) { return num(r.hosts); }],
      ['Top host', null, function (r) { return esc(r.worst_host); }], ['ACR', 'acr', function (r) { return r.acr_score === null ? '' : esc(Number(r.acr_score)); }],
      ['AES', null, function (r) { return r.aes_score === null ? '' : esc(Number(r.aes_score)); }]] },
    tier0_hosts: { title: 'Hosts Where Tier 0 Accounts Are Used', classes: false, cols: [
      ['Host', 'name', function (r) { return '<b>' + esc(r.name) + '</b>'; }], ['Host tier', null, function (r) { return r.host_tier === null ? '<span class="badge-soft bad">Outside Tier 0</span>' : tierBadge(r.host_tier); }],
      ['Accounts', 'accounts', function (r) { return '<div class="truncate" title="' + esc(r.account_names) + '">' + esc(r.account_names) + '</div>'; }],
      ['Usage', null, function (r) { return accessChips(r.access); }], ['Critical', 'critical', function (r) { return num(r.critical); }], ['ACR', 'acr', function (r) { return r.acr_score === null ? '' : esc(Number(r.acr_score)); }]] },
    service_accounts: { title: 'Service Accounts on Hosts with Critical Vulnerabilities', classes: false, cols: [
      ['Account', 'name', identityCell], ['Type', null, function (r) { return '<span class="chip">' + esc(r.kind === 'service_account' ? 'AD service account' : r.kind) + '</span>'; }], ['Tier', null, tierCell],
      ['Password age', null, function (r) { var d = parseDate(r.pwd_last_set); return d ? num(Math.floor((Date.now() - d) / 864e5)) + ' days' : ''; }],
      ['Hosts', 'hosts', function (r) { return num(r.hosts); }], ['Services', null, function (r) { return '<div class="truncate" title="' + esc(r.services) + '">' + esc(r.services) + '</div>'; }],
      ['Critical', 'critical', function (r) { return num(r.critical); }]] }
  };

  function initRisk() {
    var state = { view: RISK_VIEWS[query().get('view')] ? query().get('view') : 'critical_users', q: '', cls: '', page: 1, size: 25, sort: '', dir: 'desc' };
    function load() {
      var view = RISK_VIEWS[state.view];
      $('#riskTiles .tile').each(function () { $(this).toggleClass('active', this.dataset.view === state.view); });
      $('#riskTitle').text(view.title);
      $('#riskClass').toggle(view.classes);
      $('#riskClass [data-class]').each(function () { $(this).toggleClass('active', this.dataset['class'] === state.cls); });
      var params = { view: state.view, q: state.q, 'class': view.classes ? state.cls : '', sort: state.sort, dir: state.dir };
      $('#riskExport').attr('href', '/api.php?action=risk_export&' + $.param(params));
      renderHead($('#riskHead'), view.cols, state, false);
      $('#riskBody').html(loadingRow(view.cols.length));
      api('risk_list', $.extend({ page: state.page, size: state.size }, params)).done(function (d) {
        $('#riskBody').html(d.rows.length ? d.rows.map(function (r) {
          return '<tr class="clickable" tabindex="0" data-key="' + esc(r.key) + '" data-shape="' + esc(d.shape) + '" data-name="' + esc(r.name) + '">' + view.cols.map(function (c) { return '<td>' + c[2](r) + '</td>'; }).join('') + '</tr>';
        }).join('') : emptyRow(view.cols.length, state.q ? 'Nothing matches this search.' : 'No result. An empty list does not mean no risk: check the scan coverage above.'));
        renderPager($('#riskPager'), state, d.total, load);
      }).fail(function (xhr) { $('#riskBody').html(emptyRow(view.cols.length, esc(errText(xhr)))); });
    }
    $('#riskTiles').on('click', '.tile', function () { state.view = this.dataset.view; state.page = 1; state.sort = ''; state.q = ''; $('#riskSearch').val(''); load(); });
    $('#riskClass').on('click', '[data-class]', function () { state.cls = this.dataset['class']; state.page = 1; load(); });
    $('#riskSearch').on('input', debounce(function () { state.q = this.value.trim(); state.page = 1; load(); }, 300));
    bindSort($('#riskHead'), state, load);
    function openEvidence(name, key, shape) {
      showDetail(name, 'Loading…');
      api('risk_evidence', { key: key, shape: shape }).done(function (d) {
        showDetail(name, '<table class="table"><thead><tr><th>Access</th><th>Who / where</th><th>Evidence</th><th>Seen</th></tr></thead><tbody>' + d.rows.map(function (r) {
          return '<tr><td><span class="chip">' + esc(ACCESS[r.access_type] || r.access_type) + '</span></td><td><b>' + esc(r.identity_name) + '</b><div class="muted"><a href="' + esc('/explore/assets?host=' + r.host_asset_id) + '">' + esc(r.host_name) + '</a> · <a href="' + esc('/explore/findings?host=' + r.host_asset_id + '&severity=4') + '">' + num(r.critical) + ' critical</a></div></td><td>Plugin ' +
            esc(r.evidence_plugin_id) + '<div class="muted">' + esc(r.plugin_name) + '</div><div class="muted">' + esc(r.evidence) + '</div></td><td class="text-nowrap">' + esc(fmtDay(r.last_seen)) + '</td></tr>';
        }).join('') + '</tbody></table>', 'risk evidence');
      }).fail(function (xhr) { showDetail(name, '<div class="alert alert-danger">' + esc(errText(xhr)) + '</div>'); });
    }
    $('#riskBody').on('click keydown', 'tr.clickable', function (e) {
      if (e.type === 'keydown' && e.key !== 'Enter') return;
      openEvidence(this.dataset.name, this.dataset.key, this.dataset.shape);
    });
    if (query().get('evidence')) openEvidence(query().get('name') || query().get('evidence'), query().get('evidence'), query().get('shape') === 'host' ? 'host' : 'identity');   // deep link from Agent Tenable
    api('risk_summary').done(function (d) {
      var c = d.counts || {}, v = d.coverage || {}, m = d.matched || {};
      Object.keys(c).forEach(function (k) { $('#risk [data-v="' + k + '"]').text(num(c[k])); });
      $('#acrHint').text('ACR ' + d.acr_threshold + ' or higher');
      $('#tier0Hint').text(num(c.tier0_hosts_outside) + ' outside Tier 0');
      $('#riskUpdated').text(d.correlated_at ? 'Last correlation: ' + fmtLong(parseDate(d.correlated_at)) : 'Correlation has not run yet.');
      $('#riskCoverage').html('<span><b>' + num(v.credentialed) + '</b> of ' + num(v.hosts) + ' hosts scanned with credentials</span>' +
        '<span><b>' + num(v.critical_no_credentials) + '</b> of ' + num(v.critical) + ' critical hosts have no credentialed data</span>' +
        '<span><b>' + num(v.high_acr_no_credentials) + '</b> of ' + num(v.high_acr) + ' high-ACR hosts have no credentialed data</span>' +
        '<span><b>' + num(m.matched) + '</b> of ' + num(m.identities) + ' identities matched to AD</span>');
      if (typeof Chart === 'undefined') return;
      Chart.defaults.font.family = 'Helvetica, Arial, sans-serif'; Chart.defaults.font.size = 12; Chart.defaults.color = '#33363f';
      new Chart(document.getElementById('chartRiskHosts'), { type: 'bar',
        data: { labels: d.top_hosts.map(function (r) { return r.host_name; }), datasets: [{ label: 'Privileged identities', data: d.top_hosts.map(function (r) { return Number(r.identities); }), backgroundColor: '#dd4b50', borderRadius: 100, borderSkipped: false, maxBarThickness: 14 }] },
        options: { indexAxis: 'y', maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { grid: { color: '#d9dbe0' }, border: { display: false }, ticks: { precision: 0 } }, y: { grid: { display: false } } } } });
      var color = { usage: '#dd4b50', right: '#026edd', scan: '#b8bcc6' };
      new Chart(document.getElementById('chartRiskTypes'), { type: 'bar',
        data: { labels: d.by_type.map(function (r) { return ACCESS[r.access_type] || r.access_type; }), datasets: [{ label: 'Facts', data: d.by_type.map(function (r) { return Number(r.n); }), backgroundColor: d.by_type.map(function (r) { return color[r.access_class]; }), borderRadius: 100, borderSkipped: false, maxBarThickness: 14 }] },
        options: { indexAxis: 'y', maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { grid: { color: '#d9dbe0' }, border: { display: false } }, y: { grid: { display: false } } } } });
    }).fail(function (xhr) { toast(errText(xhr), 'error'); });
    load();
  }

  // ---- Local users ------------------------------------------------------------------------------------------
  var FLAG_LABELS = { disabled: 'Disabled', password_never_changed: 'Password never changed', never_logged_in: 'Never logged in', password_never_expires: 'Password never expires' };
  function flagChips(flags) { return (flags || []).map(function (f) { return '<span class="chip">' + esc(FLAG_LABELS[f] || f) + '</span>'; }).join(''); }
  function statusText(v) { return v === true ? '<span class="badge-soft bad">Disabled</span>' : v === false ? '<span class="badge-soft ok">Enabled</span>' : ''; }
  function privilegeBadge(v) { return v ? '<span class="badge-soft tier-0">Privileged</span>' : ''; }
  var LOCAL_ROW_COLS = [['Account', 'name', function (r) { return '<b>' + esc(r.name) + '</b>' + (r.rid ? '<div class="muted mono text-nowrap">RID ' + esc(r.rid) + '</div>' : ''); }],
    ['Host', 'host', function (r) { return esc(r.host_name) + '<div class="muted mono">' + esc(r.ip) + '</div>'; }],
    ['Privilege', null, function (r) { return privilegeBadge(r.privileged); }], ['Status', null, function (r) { return statusText(r.disabled); }],
    ['Groups', null, function (r) { var g = r.groups || []; return '<div class="truncate" title="' + esc(g.join(', ')) + '">' + g.slice(0, 3).map(function (x) { return '<span class="chip">' + esc(x) + '</span>'; }).join('') + (g.length > 3 ? '<span class="muted">+' + (g.length - 3) + '</span>' : '') + '</div>'; }],
    ['Findings', null, function (r) { return flagChips(r.flags); }], ['Critical', 'critical', function (r) { return num(r.critical); }], ['Last seen', 'last_seen', function (r) { return '<span class="text-nowrap">' + esc(fmtDay(r.last_seen)) + '</span>'; }]];
  var LOCAL_COUNTS = [['Accounts', 'accounts', function (r) { return num(r.accounts); }], ['Hosts', 'hosts', function (r) { return num(r.hosts); }],
    ['Privileged', 'privileged', function (r) { return countCell(r.privileged); }], ['Disabled', null, function (r) { return countCell(r.disabled); }]];
  function localGroupCols(by) {
    var labels = { account: 'Account name', host: 'Host', group: 'Local group', os: 'Operating system', flag: 'Finding' };
    var first = [labels[by], 'name', function (r) {
      return by === 'flag' ? '<b>' + esc(FLAG_LABELS[r.name] || r.name) + '</b>'
        : '<div class="truncate" style="max-width:420px" title="' + esc(r.name) + '"><b>' + esc(r.name) + '</b></div>' + (by === 'host' ? '<div class="muted mono">' + esc(r.ip) + '</div>' : '');
    }];
    var cols = [first].concat(by === 'account' ? [LOCAL_COUNTS[1], LOCAL_COUNTS[2], LOCAL_COUNTS[3]] : by === 'host' ? [LOCAL_COUNTS[0], LOCAL_COUNTS[2], LOCAL_COUNTS[3]] : LOCAL_COUNTS);
    return cols.concat(by === 'os' ? [] : [['Top critical', 'critical', function (r) { return num(r.critical); }]]);
  }

  function initLocalUsers() {
    var q = query();
    var state = { group_by: 'account', type: q.get('type') === 'system' ? 'system' : 'user', privileged: ['0', '1'].indexOf(q.get('privileged')) >= 0 ? q.get('privileged') : '', status: '', q: q.get('q') || '', page: 1, size: 50, sort: '', dir: 'desc' };
    var $root = $('#localUsers');
    $('#luSearch').val(state.q);
    function params() { return { group_by: state.group_by === 'none' ? '' : state.group_by, type: state.type, privileged: state.privileged, status: state.status, q: state.q }; }
    function load() {
      $root.find('[data-f]').each(function () { $(this).toggleClass('active', state[this.dataset.f] === this.dataset.v); });
      var cols = state.group_by === 'none' ? LOCAL_ROW_COLS : localGroupCols(state.group_by);
      renderHead($('#luHead'), cols, state, false);
      $('#luBody').html(loadingRow(cols.length));
      api('local_users', $.extend({ page: state.page, size: state.size, sort: state.sort, dir: state.dir }, params())).done(function (d) {
        $('#luSummary').text(num(d.total) + (state.group_by === 'none' ? ' account and host pairs' : ' groups'));
        $('#luBody').html(d.rows.length ? d.rows.map(function (r) {
          var open = state.group_by === 'none' ? 'data-host="' + esc(r.host_asset_id) + '"' : 'data-key="' + esc(r.key) + '" data-name="' + esc(FLAG_LABELS[r.name] || r.name) + '"';
          return '<tr class="clickable" tabindex="0" ' + open + '>' + cols.map(function (c) { return '<td>' + c[2](r) + '</td>'; }).join('') + '</tr>';
        }).join('') : emptyRow(cols.length, state.q || state.privileged || state.status ? 'Nothing matches these filters.' : 'No local accounts yet. Run the CTEM pipeline under <a href="/settings/ctem">Settings</a>.'));
        renderPager($('#luPager'), state, d.total, load);
      }).fail(function (xhr) { $('#luBody').html(emptyRow(cols.length, esc(errText(xhr)))); });
    }
    $root.on('click', '[data-f]', function () {
      state[this.dataset.f] = this.dataset.v; state.page = 1;
      if (this.dataset.f === 'group_by') state.sort = '';
      load();
    });
    $('#luSearch').on('input', debounce(function () { state.q = this.value.trim(); state.page = 1; load(); }, 300));
    bindSort($('#luHead'), state, load);
    $('#luBody').on('click keydown', 'tr.clickable', function (e) {
      if (e.type === 'keydown' && e.key !== 'Enter') return;
      if (e.target.tagName === 'A') return;
      if (this.dataset.host) { openDevice(this.dataset.host); return; }
      var name = this.dataset.name;
      showDetail(name, 'Loading…');
      api('local_users', $.extend(params(), { group_by: '', in_group: state.group_by, in_key: this.dataset.key, size: 200 })).done(function (d) {
        showDetail(name, '<p class="muted">' + num(d.total) + ' account and host pairs' + (d.total > d.rows.length ? ', first ' + d.rows.length + ' shown' : '') + '</p><table class="table"><thead><tr><th>Account</th><th>Host</th><th></th><th>Groups</th></tr></thead><tbody>' + d.rows.map(function (r) {
          return '<tr><td><b>' + esc(r.name) + '</b></td><td>' + esc(r.host_name) + '<div class="muted mono">' + esc(r.ip) + '</div></td><td>' + privilegeBadge(r.privileged) + statusText(r.disabled) + flagChips(r.flags) + '</td><td class="muted">' + esc((r.groups || []).join(', ')) + '</td></tr>';
        }).join('') + '</tbody></table>', 'local accounts');
      }).fail(function (xhr) { showDetail(name, '<div class="alert alert-danger">' + esc(errText(xhr)) + '</div>'); });
    });
    load();
  }

  // ---- Software and browser extensions ----------------------------------------------------------------------
  function initSoftware() {
    var cols = [['Software', 'name', function (r) { return '<b>' + esc(r.name) + '</b>'; }], ['Latest version', null, function (r) { return '<span class="mono">' + esc(r.latest_version) + '</span>'; }],
      ['Versions', 'versions', function (r) { return num(r.versions); }], ['Hosts', 'hosts', function (r) { return num(r.hosts); }], ['Last installed', 'installed', function (r) { return esc(fmtDay(r.last_installed)); }]];
    var state = { q: query().get('q') || '', page: 1, size: 50, sort: 'hosts', dir: 'desc' };
    $('#swSearch').val(state.q);
    function load() {
      renderHead($('#swHead'), cols, state, false);
      $('#swBody').html(loadingRow(cols.length));
      api('software', { q: state.q, page: state.page, size: state.size, sort: state.sort, dir: state.dir, rare: $('#swRare').prop('checked') ? '1' : '', recent: $('#swRecent').prop('checked') ? '1' : '' }).done(function (d) {
        $('#swBody').html(d.rows.length ? d.rows.map(function (r) { return '<tr class="clickable" tabindex="0" data-key="' + esc(r.key) + '" data-name="' + esc(r.name) + '">' + cols.map(function (c) { return '<td>' + c[2](r) + '</td>'; }).join('') + '</tr>'; }).join('')
          : emptyRow(cols.length, 'No software found. Run the CTEM pipeline under <a href="/settings/ctem">Settings</a>.'));
        renderPager($('#swPager'), state, d.total, load);
      }).fail(function (xhr) { $('#swBody').html(emptyRow(cols.length, esc(errText(xhr)))); });
    }
    $('#swSearch').on('input', debounce(function () { state.q = this.value.trim(); state.page = 1; load(); }, 300));
    $('#swRare, #swRecent').on('change', function () { state.page = 1; load(); });
    bindSort($('#swHead'), state, load);
    $('#swBody').on('click keydown', 'tr.clickable', function (e) {
      if (e.type === 'keydown' && e.key !== 'Enter') return;
      var name = this.dataset.name;
      showDetail(name, 'Loading…');
      api('software_hosts', { key: this.dataset.key }).done(function (d) {
        showDetail(name, '<table class="table"><thead><tr><th>Host</th><th>Version</th><th>Installed</th><th>Critical</th></tr></thead><tbody>' + d.rows.map(function (r) {
          return '<tr><td><b>' + esc(r.host_name) + '</b><div class="muted mono">' + esc(r.ip) + '</div></td><td class="mono">' + esc(r.version) + '</td><td>' + esc(fmtDay(r.installed_at)) + '</td><td>' + num(r.critical) + '</td></tr>';
        }).join('') + '</tbody></table>', 'software');
      });
    });
    load();
  }

  function initExtensions() {
    var cols = [['Extension', 'name', function (r) { return '<b>' + esc(r.name || '(no name)') + '</b><div class="muted mono">' + esc(r.extension_id) + '</div>'; }], ['Browser', null, function (r) { return '<span class="chip">' + esc(r.browser) + '</span>'; }],
      ['Version', null, function (r) { return '<span class="mono">' + esc(r.version) + '</span>'; }], ['Hosts', 'hosts', function (r) { return num(r.hosts); }], ['Users', 'users', function (r) { return num(r.users); }], ['Updated', 'updated', function (r) { return esc(fmtDay(r.updated_at)); }]];
    var state = { q: query().get('q') || '', browser: '', page: 1, size: 50, sort: 'hosts', dir: 'desc' };
    $('#extSearch').val(state.q);
    function load() {
      renderHead($('#extHead'), cols, state, false);
      $('#extBody').html(loadingRow(cols.length));
      api('extensions', { q: state.q, browser: state.browser, page: state.page, size: state.size, sort: state.sort, dir: state.dir, rare: $('#extRare').prop('checked') ? '1' : '' }).done(function (d) {
        $('#extBrowsers').html('<button type="button" class="filter-option' + (state.browser ? '' : ' active') + '" data-b="">All browsers</button>' + d.browsers.map(function (b) {
          return '<button type="button" class="filter-option' + (state.browser === b.browser ? ' active' : '') + '" data-b="' + esc(b.browser) + '">' + esc(b.browser) + '<span class="count">' + num(b.n) + '</span></button>';
        }).join(''));
        $('#extBody').html(d.rows.length ? d.rows.map(function (r) { return '<tr class="clickable" tabindex="0" data-id="' + esc(r.id) + '" data-name="' + esc(r.name) + '">' + cols.map(function (c) { return '<td>' + c[2](r) + '</td>'; }).join('') + '</tr>'; }).join('')
          : emptyRow(cols.length, 'No extensions found. Run the CTEM pipeline under <a href="/settings/ctem">Settings</a>.'));
        renderPager($('#extPager'), state, d.total, load);
      }).fail(function (xhr) { $('#extBody').html(emptyRow(cols.length, esc(errText(xhr)))); });
    }
    $('#extBrowsers').on('click', '[data-b]', function () { state.browser = this.dataset.b; state.page = 1; load(); });
    $('#extSearch').on('input', debounce(function () { state.q = this.value.trim(); state.page = 1; load(); }, 300));
    $('#extRare').on('change', function () { state.page = 1; load(); });
    bindSort($('#extHead'), state, load);
    $('#extBody').on('click keydown', 'tr.clickable', function (e) {
      if (e.type === 'keydown' && e.key !== 'Enter') return;
      var name = this.dataset.name;
      showDetail(name, 'Loading…');
      api('extension_hosts', { id: this.dataset.id }).done(function (d) {
        showDetail(name, '<table class="table"><thead><tr><th>Host</th><th>User profile</th><th>Version</th><th>Updated</th></tr></thead><tbody>' + d.rows.map(function (r) {
          return '<tr><td><b>' + esc(r.host_name) + '</b></td><td>' + esc(r.profile_user) + '</td><td class="mono">' + esc(r.version) + '</td><td>' + esc(fmtDay(r.updated_at)) + '</td></tr>';
        }).join('') + '</tbody></table>', 'browser extension');
      });
    });
    load();
  }

  // ---- Settings: CTEM ---------------------------------------------------------------------------------------
  function initCtem() {
    var timer = null;
    var JOBS = [['plugin_collect', 'plugin_collect', 'Pulls the selected plugins and their attachments from Security Center', 'sc'],
      ['extract', 'ctem_extract', 'Parses plugin output that changed', 'ctem'], ['correlate', 'ctem_correlate', 'Matches identities to AD and rebuilds the Risk data', 'ctem'],
      ['rebuild', 'ctem_rebuild', 'Parses every stored output again', 'ctem']];
    function load() {
      clearTimeout(timer);
      api('ctem_overview').done(function (d) {
        var byKind = {}, busy = false;
        d.jobs.forEach(function (j) { byKind[j.kind] = j; if (j.status === 'queued' || j.status === 'running') busy = true; });
        $('#ctemJobs').html(JOBS.map(function (k) {
          var j = byKind[k[0]], active = j && (j.status === 'queued' || j.status === 'running'), p = (j && j.progress) || {};
          var extra = j && j.status === 'running' && p.total ? ' ' + num(p.done) + ' / ' + num(p.total) : (j && j.status === 'running' && p.plugin_count ? ' ' + num(p.plugin_index) + ' / ' + num(p.plugin_count) + ' plugins' : '');
          return '<tr><td class="mono">' + k[1] + '</td><td>' + k[2] + (j && j.status === 'failed' ? '<div>' + errSpan(j.error) + logLink(k[3], j) + '</div>' : '') + '</td><td>' + (j ? statusBadge(j.status) + esc(extra) : '<span class="muted">never run</span>') +
            '</td><td class="muted text-nowrap">' + (j ? esc(ago(j.finished_at || j.requested_at)) : '') + '</td><td class="text-nowrap">' + (j ? esc(duration(j.duration_seconds)) : '') +
            '</td><td class="text-end text-nowrap"><button type="button" class="btn btn-sm btn-primary" data-run="' + k[0] + '"' + (active ? ' disabled' : '') + '>Run now</button>' + (j ? ' <a class="btn btn-sm btn-outline-secondary" href="/logs?connector=' + k[3] + '&job_id=' + esc(j.id) + '">Log</a>' : '') + '</td></tr>';
        }).join(''));
        $('#ctemPipeline').html(d.pipeline.map(function (r) {
          function n(v, bad) { v = Number(v) || 0; return '<td class="text-end' + (v && bad ? ' text-danger fw-bold' : '') + (v ? '' : ' muted') + '">' + num(v) + '</td>'; }
          return '<tr><td><b>' + esc(r.plugin_id) + '</b> <span class="muted">' + esc(r.name) + '</span></td><td><span class="chip">' + esc(r.scenario) + '</span></td><td class="text-end">' + (r.sc_finding_count === null ? '' : num(r.sc_finding_count)) + '</td>' +
            n(r.collected) + n(r.pending) + n(r.parsed) + n(r.failed, true) + n(r.truncated) + n(r.refers_to_attachment) + n(r.attachments) + n(r.records) + '</tr>';
        }).join(''));
        $('#ctemFailedBox').prop('hidden', !d.failed.length);
        $('#ctemFailed').html(d.failed.map(function (f) { return '<tr><td class="mono">' + esc(f.id) + '</td><td>' + esc(f.plugin_id) + '</td><td>' + esc(f.dns_name || f.ip) + '</td><td>' + errSpan(f.extract_error) + '</td></tr>'; }).join(''));
        timer = setTimeout(load, busy ? 2500 : 20000);
      }).fail(function (xhr) { toast(errText(xhr), 'error'); });
    }
    $('#ctemJobs').on('click', '[data-run]', function () {
      $(this).prop('disabled', true);
      post('ctem_run', { kind: this.dataset.run }).done(function () { toast('Job queued. The worker checks for work every 30 seconds.'); load(); }).fail(function (xhr) { toast(errText(xhr), 'error'); load(); });
    });
    $('#ctemForm').on('submit', function (e) {
      e.preventDefault();
      post('ctem_settings_save', $(this).serialize()).done(function () { $('#ctemStatus').html('<span class="badge-soft ok">Saved</span>'); }).fail(function (xhr) { $('#ctemStatus').html(errSpan(errText(xhr))); });
    });
    load();
  }

  function initCatalog() {
    var state = { status: '', 'class': '', source: '', q: '', page: 1, size: 50 };
    function load() {
      $('#catBody').html(loadingRow(7));
      api('catalog', state).done(function (d) {
        $('#catBody').html(d.rows.length ? d.rows.map(function (r) {
          var options = ['selected', 'candidate', 'ignored'].map(function (s) { return '<option value="' + s + '"' + (r.review_status === s ? ' selected' : '') + '>' + s + '</option>'; }).join('');
          return '<tr><td class="mono">' + esc(r.plugin_id) + '</td><td>' + esc(r.name) + (r.handbook_category ? ' <span class="chip">handbook</span>' : '') + '</td><td class="muted">' + esc(r.family) + '</td><td>' + (r.scenario ? '<span class="chip">' + esc(r.scenario) + '</span>' : '') +
            '</td><td class="muted">' + esc(r.extractor) + '</td><td class="text-end">' + (r.sc_finding_count === null ? '' : num(r.sc_finding_count)) + '</td><td><select class="form-select form-select-sm" style="width:auto" data-plugin="' + esc(r.plugin_id) + '" aria-label="Status of plugin ' + esc(r.plugin_id) + '">' + options + '</select></td></tr>';
        }).join('') : emptyRow(7, 'Nothing matches these filters.'));
        renderPager($('#catPager'), state, d.total, load);
      }).fail(function (xhr) { $('#catBody').html(emptyRow(7, esc(errText(xhr)))); });
    }
    $('#catalog').on('click', '[data-f]', function () {
      state[this.dataset.f] = this.dataset.v; state.page = 1;
      $(this).siblings('[data-f]').removeClass('active'); $(this).addClass('active'); load();
    });
    $('#catSearch').on('input', debounce(function () { state.q = this.value.trim(); state.page = 1; load(); }, 300));
    $('#catBody').on('change', '[data-plugin]', function () {
      post('catalog_set', { plugin_id: this.dataset.plugin, status: this.value }).done(function () { toast('Plugin updated.'); }).fail(function (xhr) { toast(errText(xhr), 'error'); });
    });
    load();
  }

  // ---- Markdown (escape first, then a small subset: headings, lists, tables, code, bold, links) ----------------
  function mdInline(s) {
    s = esc(s);
    s = s.replace(/`([^`]+)`/g, function (m, c) { return '<code>' + c + '</code>'; });
    s = s.replace(/\*\*([^*]+)\*\*/g, '<b>$1</b>').replace(/(^|[\s(])\*([^*\n]+)\*(?=[\s).,;:!?]|$)/g, '$1<i>$2</i>').replace(/(^|[\s(])_([^_\n]+)_(?=[\s).,;:!?]|$)/g, '$1<i>$2</i>');
    s = s.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+|\/[^\s)]*)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>');
    return s;
  }
  function md(text) {
    var lines = String(text || '').replace(/\r/g, '').split('\n'), out = [], i = 0, para = [];
    function flush() { if (para.length) { out.push('<p>' + mdInline(para.join(' ')) + '</p>'); para = []; } }
    while (i < lines.length) {
      var line = lines[i];
      var fence = line.match(/^\s*```/);
      if (fence) {
        flush();
        var code = []; i++;
        while (i < lines.length && !/^\s*```/.test(lines[i])) code.push(lines[i++]);
        i++;
        out.push('<pre><code>' + esc(code.join('\n')) + '</code></pre>');
        continue;
      }
      var h = line.match(/^(#{1,4})\s+(.+)$/);
      if (h) { flush(); out.push('<h' + h[1].length + '>' + mdInline(h[2]) + '</h' + h[1].length + '>'); i++; continue; }
      if (/^\s*(-{3,}|\*{3,})\s*$/.test(line)) { flush(); out.push('<hr>'); i++; continue; }
      if (/^\s*\|.*\|\s*$/.test(line) && i + 1 < lines.length && /^\s*\|?\s*:?-{2,}/.test(lines[i + 1])) {
        flush();
        var cells = function (l) { return l.replace(/^\s*\|/, '').replace(/\|\s*$/, '').split('|').map(function (c) { return c.trim(); }); };
        var head = cells(line); i += 2; var rows = [];
        while (i < lines.length && /^\s*\|.*\|\s*$/.test(lines[i])) rows.push(cells(lines[i++]));
        out.push('<table><thead><tr>' + head.map(function (c) { return '<th>' + mdInline(c) + '</th>'; }).join('') + '</tr></thead><tbody>' +
          rows.map(function (r) { return '<tr>' + head.map(function (c, n) { return '<td>' + mdInline(r[n] || '') + '</td>'; }).join('') + '</tr>'; }).join('') + '</tbody></table>');
        continue;
      }
      var list = line.match(/^(\s*)([-*+]|\d+[.)])\s+(.+)$/);
      if (list) {
        flush();
        var ordered = /\d/.test(list[2]), items = [];
        while (i < lines.length) {
          var m = lines[i].match(/^(\s*)([-*+]|\d+[.)])\s+(.+)$/);
          if (m) { items.push(m[3]); i++; }
          else if (/^\s{2,}\S/.test(lines[i]) && items.length) { items[items.length - 1] += ' ' + lines[i].trim(); i++; }
          else break;
        }
        out.push('<' + (ordered ? 'ol' : 'ul') + '>' + items.map(function (t) { return '<li>' + mdInline(t) + '</li>'; }).join('') + '</' + (ordered ? 'ol' : 'ul') + '>');
        continue;
      }
      if (/^\s*>\s?/.test(line)) { flush(); var q = []; while (i < lines.length && /^\s*>\s?/.test(lines[i])) q.push(lines[i++].replace(/^\s*>\s?/, '')); out.push('<blockquote>' + mdInline(q.join(' ')) + '</blockquote>'); continue; }
      if (line.trim() === '') { flush(); i++; continue; }
      para.push(line.trim()); i++;
    }
    flush();
    return out.join('');
  }

  // ---- Agent Tenable ---------------------------------------------------------------------------------------
  var agent = (function () {
    var $panel = $('#agent'), $log = $('#agentLog'), $input = $('#agentInput'), $status = $('#agentStatus');
    if (!$panel.length) return { analyze: function () {}, open: function () {} };
    var state = { messages: [], skill: '', context: null, contextLabel: '', large: false, w: 0, h: 0 };
    var meta = null, busy = false, chartCount = 0;
    var SKILL_ICON = { shield: 'i-shield', people: 'b-people', crosshair: 'i-goal', lens: 'i-score', person: 'b-person', pc: 'b-pc', compass: 'i-scan' };

    try { var saved = JSON.parse(window.sessionStorage.getItem('el.agent') || 'null'); if (saved && saved.messages) state = $.extend(state, saved); } catch (e) { /* fresh */ }
    function persist() {
      try {
        var copy = $.extend({}, state, { messages: state.messages.slice(-30) });
        window.sessionStorage.setItem('el.agent', JSON.stringify(copy));
      } catch (e) { /* storage is optional */ }
    }
    function applySize() {
      $panel.toggleClass('large', !!state.large);
      $panel.css({ '--agent-w': state.w ? state.w + 'px' : '', '--agent-h': state.h ? state.h + 'px' : '' });
      $('#agentSize').attr('title', state.large ? 'Smaller' : 'Larger');
    }

    // -- open, close, resize, tabs
    function open(tab) {
      $panel.removeAttr('hidden'); $('#agentToggle').attr('aria-expanded', 'true');
      if (!meta) load();
      if (tab) showTab(tab);
      setTimeout(function () { $input.trigger('focus'); scrollLog(); }, 50);
    }
    function close() { $panel.attr('hidden', true); $('#agentToggle').attr('aria-expanded', 'false'); }
    $('#agentToggle').on('click', function () { $panel.attr('hidden') === undefined ? close() : open(); });
    $('#agentClose').on('click', close);
    $('#agentSize').on('click', function () { state.large = !state.large; applySize(); persist(); scrollLog(); });
    $(document).on('keydown', function (e) { if (e.key === 'Escape' && $panel.attr('hidden') === undefined && !$('.modal.show, .offcanvas.show').length) close(); });
    $('#agentResize').on('mousedown', function (e) {
      e.preventDefault();
      var startX = e.clientX, startY = e.clientY, w0 = $panel.outerWidth(), h0 = $panel.outerHeight();
      function move(ev) { state.w = Math.max(360, w0 + (startX - ev.clientX)); state.h = Math.max(420, h0 + (startY - ev.clientY)); applySize(); }
      function up() { $(document).off('mousemove', move).off('mouseup', up); persist(); }
      $(document).on('mousemove', move).on('mouseup', up);
    });
    function showTab(name) {
      $('.agent-tabs button').each(function () { var on = this.dataset.tab === name; $(this).toggleClass('active', on).attr('aria-selected', String(on)); });
      $('.agent-pane').each(function () { $(this).prop('hidden', this.dataset.pane !== name); });
      if (name === 'chat') setTimeout(function () { $input.trigger('focus'); scrollLog(); }, 20);
    }
    $('.agent-tabs button').on('click', function () { showTab(this.dataset.tab); });

    // -- skills, prompts, tools
    function load() {
      api('ai_bootstrap').done(function (d) {
        meta = d;
        $('#agentModel').text(d.model + (d.agent ? ' · ' + d.agent.description : ''));
        if (!state.skill || !d.skills.some(function (s) { return s.name === state.skill; })) {
          var def = d.skills.filter(function (s) { return s.default; })[0] || d.skills[0];
          state.skill = def ? def.name : '';
        }
        $('#agentSkill').html(d.skills.map(function (s) { return '<option value="' + esc(s.name) + '"' + (s.name === state.skill ? ' selected' : '') + '>' + esc(s.title) + '</option>'; }).join(''));
        renderSkills(); renderPrompts(d.prompts); renderToolList(d.tools);
        if (!state.messages.length) renderLog();
      }).fail(function (xhr) { $('#agentModel').text('Not available'); addMessage('error', errText(xhr)); });
    }
    function renderSkills() {
      $('#agentSkills').html((meta.skills || []).map(function (s) {
        return '<button type="button" class="skill-card' + (s.name === state.skill ? ' active' : '') + '" data-skill="' + esc(s.name) + '"><span class="icon ' + (SKILL_ICON[s.icon] || 'i-lightbulb') + '"></span><span><h3>' + esc(s.title) + '</h3><p>' + esc(s.description) + '</p><div class="src">' + esc(s.source) + '</div></span></button>';
      }).join(''));
    }
    $('#agentSkills').on('click', '[data-skill]', function () { setSkill(this.dataset.skill); showTab('chat'); });
    $('#agentSkill').on('change', function () { setSkill(this.value); });
    function setSkill(name) {
      state.skill = name; $('#agentSkill').val(name); renderSkills(); persist();
      var s = (meta.skills || []).filter(function (x) { return x.name === name; })[0];
      if (s && state.messages.length) addMessage('system', 'Skill changed to ' + s.title + '. The next answer uses it.', true);
    }
    function renderPrompts(sets) {
      $('#agentPrompts').html(sets.map(function (set) {
        return '<div class="prompt-set"><h3>' + esc(set.title) + '</h3><div class="desc">' + esc(set.description) + '</div>' +
          set.prompts.map(function (p) { return '<button type="button" class="prompt-btn" data-prompt="' + esc(p) + '">' + esc(p).replace(/\{([^}]+)\}/g, '<b>{$1}</b>') + '</button>'; }).join('') + '</div>';
      }).join('') || '<p class="muted">No prompt sets.</p>');
    }
    $('#agentPrompts').on('click', '[data-prompt]', function () {
      var text = this.dataset.prompt, m = text.match(/\{([^}]+)\}/);
      $input.val(text); showTab('chat'); autosize();
      if (m) { var at = text.indexOf(m[0]); $input[0].setSelectionRange(at, at + m[0].length); }
    });
    function renderToolList(tools) {
      $('#agentToolList').html('<p class="muted" style="font-size:12px">Read-only database queries the model can call. Results appear in the chat as tables.</p>' + tools.map(function (t) {
        return '<div class="tool-row"><div class="name">' + esc(t.name) + '</div><div>' + esc(t.description) + '</div><div class="params">' + esc(t.parameters.join(', ')) + '</div></div>';
      }).join(''));
    }

    // -- conversation
    function renderLog() {
      $log.empty();
      if (!state.messages.length) {
        $log.append('<div class="msg assistant"><div class="bubble md"><p><b>Hello.</b> I am Agent Tenable. I answer from the data Exposure Lens has collected: identities, hosts, vulnerabilities and the links between them.</p>' +
          '<p>Pick a <b>skill</b> for the point of view you want, or start from a ready-made question in <b>Prompts</b>. Try: <i>which systems does the sccm account reach?</i></p></div></div>');
        return;
      }
      state.messages.forEach(function (m) { $log.append(messageHtml(m)); });
      $log.find('.chart-card').each(function () { drawChart(this); });
      scrollLog();
    }
    function scrollLog() { $log.stop().animate({ scrollTop: $log[0].scrollHeight }, 120); }
    function messageHtml(m) {
      if (m.role === 'user') {
        return '<div class="msg user"><div class="bubble">' + esc(m.content) + '</div>' + (m.contextLabel ? '<div class="meta"><span>' + esc(m.contextLabel) + ' attached</span></div>' : '') + '</div>';
      }
      if (m.role === 'system' || m.role === 'error') return '<div class="msg ' + m.role + '"><div class="bubble">' + esc(m.content) + '</div></div>';
      var $m = $('<div class="msg assistant"></div>');
      (m.tools || []).forEach(function (t) { $m.append(toolCard(t)); });
      var $b = $('<div class="bubble md"></div>').html(md(m.content || ''));
      linkify($b, linkMap(m.tools));
      (m.charts || []).forEach(function (c) { $b.append(chartCard(c)); });
      $m.append($b);
      var meta = [];
      if (m.skill) meta.push(m.skill);
      if (m.stats && m.stats.total_ms) meta.push((m.stats.total_ms / 1000).toFixed(1) + ' s');
      if (m.stats && m.stats.completion_tokens) meta.push(num(m.stats.completion_tokens) + ' tokens');
      if (meta.length) $m.append('<div class="meta">' + meta.map(esc).join('<span>·</span>') + '</div>');
      return $m;
    }
    function addMessage(role, content, transient) {
      var m = { role: role, content: content };
      if (!transient) { state.messages.push(m); persist(); }
      $log.append(messageHtml(m)); scrollLog();
      return m;
    }

    // -- links to the detail screens (open in a new tab)
    var AD_TYPE_OF = { user: 'users', service_account: 'service_accounts', group: 'groups', computer: 'computers', group_policy: 'group_policies' };
    function hostUrl(id, plugin) { return '/explore/assets?host=' + encodeURIComponent(id) + (plugin ? '&plugin=' + encodeURIComponent(plugin) : ''); }
    function adUrl(type, id) { return '/explore/ad-objects?type=' + encodeURIComponent(type) + '&open=' + encodeURIComponent(id); }
    function evidenceUrl(key, name) { return '/risk?evidence=' + encodeURIComponent(key) + '&name=' + encodeURIComponent(name || key); }
    function findingsUrl(filters) { return '/explore/findings?' + $.param(filters); }
    /** The findings filters that reproduce a vulnerability tool's scope (account or target, severity, exploit). */
    function scopeFilters(ctx) {
      var f = {}, a = (ctx && ctx.args) || {}, sc = (ctx && ctx.result && ctx.result.scope) || {};
      if (a.account) f.account = a.account;
      if (a.target) f.target = a.target;
      if (sc.severity_min) f.severity_min = sc.severity_min;
      if (sc.exploitable_only) f.exploitable = 1;
      return f;
    }
    var RISK_VIEW_OF = { users_reaching_critical: 'critical_users', users_reaching_high_acr: 'acr_users', hosts_with_tier0_usage: 'tier0_hosts', tier0_usage_outside_tier0: 'tier0_hosts', service_accounts_on_critical: 'service_accounts' };
    var AD_COUNT_OF = { users: 'users', users_disabled: 'users&enabled=0', service_accounts: 'service_accounts', groups: 'groups', computers: 'computers', group_policies: 'group_policies', tier_0: 'users&tier=0', tier_1: 'users&tier=1', tier_2: 'users&tier=2' };
    /** The evidence URL a cell should point at, or null. ctx = { tool, args, result } of the tool call. */
    function linkFor(key, row, ctx) {
      if (!row) return null;
      var group = ctx && ctx.result && ctx.result.scope && ctx.result.scope.group_by;
      // grouped vulnerability rows: the page that lists the findings behind the number
      if (group && group !== 'none' && row.key !== undefined && (key === 'key' || key === 'name' || key === 'findings')) {
        var f = scopeFilters(ctx);
        if (group === 'host') f.host = row.key; else if (group === 'ip_block') f.cidr = row.key; else if (group === 'port') f.port = row.key;
        else if (group === 'plugin') f.plugin = row.key; else if (group === 'cve') f.cve = row.key;
        return findingsUrl(f);
      }
      if ((key === 'host_name' || key === 'ip') && row.host_asset_id) return hostUrl(row.host_asset_id);
      if ((key === 'plugin_name' || key === 'plugin_id') && row.plugin_id) return row.host_asset_id ? hostUrl(row.host_asset_id, row.plugin_id) : findingsUrl({ plugin: row.plugin_id });
      if (key === 'cve' && typeof row.cve === 'string' && /^CVE-/i.test(row.cve)) return findingsUrl({ cve: row.cve.split(',')[0].trim() });
      if (key === 'critical' && row.host_asset_id) return findingsUrl({ host: row.host_asset_id, severity: 4 });
      if (key === 'high' && row.host_asset_id) return findingsUrl({ host: row.host_asset_id, severity: 3 });
      if (key === 'exploitable' && row.host_asset_id && Number(row.exploitable)) return findingsUrl({ host: row.host_asset_id, exploitable: 1 });
      if (key === 'sam_account_name' || key === 'display_name' || key === 'name' || key === 'identity_name' || key === 'user_principal_name') {
        if (row.ad_user_id) return adUrl(row.account_type && row.account_type !== 'user' ? 'service_accounts' : 'users', row.ad_user_id);
        if (row.ad_object_id && AD_TYPE_OF[row.object_type]) return adUrl(AD_TYPE_OF[row.object_type], row.ad_object_id);
        if (row.ad_group_id) return adUrl('groups', row.ad_group_id);
        if (row.identity_key) return evidenceUrl(row.identity_key, row.identity_name);
        if (key === 'name' && row.host_asset_id) return hostUrl(row.host_asset_id);
        if (key === 'name' && row.rid !== undefined) return '/explore/local-users?q=' + encodeURIComponent(row.name) + (row.account_type === 'system' ? '&type=system' : '');
        if (key === 'name' && row.versions !== undefined) return '/explore/software?q=' + encodeURIComponent(row.name);
      }
      if ((key === 'access_type' || key === 'evidence' || key === 'hosts') && (row.identity_key || (ctx && ctx.result && ctx.result.identity_key))) return evidenceUrl(row.identity_key || ctx.result.identity_key, row.identity_name || (ctx && ctx.args && ctx.args.account));
      if (key === 'product' && row.product) return '/explore/software?q=' + encodeURIComponent(row.product);
      if (key === 'service_name' && row.host_asset_id) return hostUrl(row.host_asset_id);
      if (ctx && ctx.tool === 'risk_overview') {
        if (RISK_VIEW_OF[key]) return '/risk?view=' + RISK_VIEW_OF[key];
        if (AD_COUNT_OF[key] && row.users !== undefined) return '/explore/ad-objects?type=' + AD_COUNT_OF[key];
        if (key === 'connector' && row.connector) return '/logs?connector=' + encodeURIComponent(row.connector);
        if (key === 'hosts_with_critical') return findingsUrl({ severity: 4 });
        if (key === 'critical' || key === 'high' || key === 'medium' || key === 'low') return findingsUrl({ severity: { critical: 4, high: 3, medium: 2, low: 1 }[key] });
        if (key === 'exploitable') return findingsUrl({ exploitable: 1 });
        if (key === 'credentialed_hosts' || key === 'uncredentialed_hosts' || key === 'hosts') return '/explore/assets';
        if (key === 'identities_seen' || key === 'identities_matched_to_ad') return '/risk';
        if (key === 'tier0_hosts' || key === 'high_acr_hosts') return '/risk?view=tier0_hosts';
      }
      return null;
    }
    function link(url, html) { return '<a href="' + esc(url) + '" target="_blank" rel="noopener" title="Open the details in a new tab">' + html + '</a>'; }
    /** name -> url for everything the tool results can point at; used to link the model's own text. */
    function linkMap(tools) {
      var map = {};
      function add(name, url) { if (name && String(name).length >= 3 && !map[String(name).toLowerCase()]) map[String(name).toLowerCase()] = url; }
      function walk(v) {
        if (Array.isArray(v)) { v.forEach(walk); return; }
        if (!v || typeof v !== 'object') return;
        if (v.host_asset_id) { add(v.host_name, hostUrl(v.host_asset_id)); add(v.ip, hostUrl(v.host_asset_id)); }
        var ad = linkFor('sam_account_name', v);
        if (ad) { add(v.sam_account_name, ad); add(v.display_name, ad); add(v.user_principal_name, ad); add(v.identity_name, ad); if (v.ad_group_id || v.ad_object_id) add(v.name, ad); }
        if (v.identity_name && v.identity_key && !ad) add(v.identity_name, evidenceUrl(v.identity_key, v.identity_name));
        if (v.plugin_id && v.plugin_name) add(v.plugin_name, v.host_asset_id ? hostUrl(v.host_asset_id, v.plugin_id) : findingsUrl({ plugin: v.plugin_id }));
        if (v.plugin_id && !v.host_asset_id) add('plugin ' + v.plugin_id, findingsUrl({ plugin: v.plugin_id }));
        [].concat(v.cve || [], v.cve_sample || []).forEach(function (c) { String(c).split(',').forEach(function (one) { one = one.trim(); if (/^CVE-\d{4}-\d+$/i.test(one)) add(one, findingsUrl({ cve: one })); }); });
        Object.keys(v).forEach(function (k) { if (v[k] && typeof v[k] === 'object') walk(v[k]); });
      }
      (tools || []).forEach(function (t) { walk(t.result); });
      return map;
    }
    /** Turn exact mentions of known hosts and accounts inside the rendered text into links. */
    function linkify($el, map) {
      var names = Object.keys(map);
      if (!names.length) return;
      names.sort(function (a, b) { return b.length - a.length; });
      var re = new RegExp('(^|[^\\w.@\\\\-])(' + names.map(function (n) { return n.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }).join('|') + ')(?![\\w.@-])', 'gi');
      var walker = document.createTreeWalker($el[0], NodeFilter.SHOW_TEXT, { acceptNode: function (n) { return $(n).closest('a, pre, .tool-card').length ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT; } });
      var nodes = [], n;
      while ((n = walker.nextNode())) nodes.push(n);
      nodes.forEach(function (node) {
        if (!re.test(node.nodeValue)) { re.lastIndex = 0; return; }
        re.lastIndex = 0;
        var html = esc(node.nodeValue).replace(re, function (m, pre, name) { return pre + link(map[name.toLowerCase()], name); });
        $(node).replaceWith(html);
      });
    }

    // -- tool results as tables
    var SEV_KEYS = { severity: 1, max_severity: 1 };
    var HIDDEN_COLS = { host_asset_id: 1, key: 1, ad_user_id: 1, ad_object_id: 1, ad_group_id: 1, object_key: 1 };
    function cell(key, v, row, ctx) {
      if (v === null || v === undefined || v === '') return '<td class="muted">–</td>';
      if (SEV_KEYS[key]) return '<td>' + sevBadge(v) + '</td>';
      if (key === 'tier' || key === 'host_tier') return '<td>' + (tierBadge(v) || '–') + '</td>';
      if (typeof v === 'boolean' || v === 't' || v === 'f') return '<td>' + ((v === true || v === 't') ? '<span class="badge-soft ok">yes</span>' : '<span class="muted">no</span>') + '</td>';
      if (Array.isArray(v)) return '<td class="wrap" title="' + esc(v.join(', ')) + '">' + v.slice(0, 6).map(function (x) { return /^CVE-\d{4}-\d+$/i.test(String(x)) ? link(findingsUrl({ cve: x }), esc(x)) : esc(x); }).join(', ') + (v.length > 6 ? ' +' + (v.length - 6) : '') + '</td>';
      if (typeof v === 'object') return '<td class="wrap mono">' + esc(JSON.stringify(v)) + '</td>';
      if (typeof v === 'number' || (/^-?\d+(\.\d+)?$/.test(String(v)) && !/^0\d/.test(String(v)))) {
        var n = Number(v), nurl = linkFor(key, row, ctx), ntext = esc(Number.isInteger(n) ? num(n) : n.toFixed(1));
        return '<td class="text-end">' + (nurl && n ? link(nurl, ntext) : ntext) + '</td>';
      }
      var s = String(v), url = linkFor(key, row, ctx);
      var text = esc(s.length > 120 ? s.slice(0, 120) + '…' : s);
      return '<td title="' + esc(s) + '">' + (url ? link(url, text) : text) + '</td>';
    }
    function table(rows, ctx) {
      if (!rows.length) return '<div class="note">No rows.</div>';
      var cols = [];
      rows.slice(0, 5).forEach(function (r) { Object.keys(r).forEach(function (k) { if (cols.indexOf(k) < 0 && !HIDDEN_COLS[k]) cols.push(k); }); });
      return '<table class="table table-hover"><thead><tr>' + cols.map(function (c) { return '<th>' + esc(c.replace(/_/g, ' ')) + '</th>'; }).join('') + '</tr></thead><tbody>' +
        rows.map(function (r) { return '<tr>' + cols.map(function (c) { return cell(c, r[c], r, ctx); }).join('') + '</tr>'; }).join('') + '</tbody></table>';
    }
    function kvBlock(obj, ctx) {
      var pairs = Object.keys(obj).filter(function (k) { var v = obj[k]; return v !== null && v !== undefined && typeof v !== 'object' && !HIDDEN_COLS[k]; }).map(function (k) {
        var v = obj[k], url = linkFor(k, obj, ctx), text = typeof v === 'boolean' ? (v ? 'yes' : 'no') : String(v);
        return url ? [k.replace(/_/g, ' '), link(url, esc(text)), true] : [k.replace(/_/g, ' '), text];
      });
      var nested = Object.keys(obj).filter(function (k) { return obj[k] && typeof obj[k] === 'object'; });
      return (pairs.length ? kv(pairs) : '') + nested.map(function (k) {
        var v = obj[k];
        if (Array.isArray(v)) return v.length && typeof v[0] === 'object' ? '<div class="section">' + esc(k.replace(/_/g, ' ')) + '</div>' + table(v, ctx) : kv([[k.replace(/_/g, ' '), v.join(', ') || '–']]);
        return '<div class="section">' + esc(k.replace(/_/g, ' ')) + '</div>' + kvBlock(v, ctx);
      }).join('');
    }
    function resultHtml(result, ctx) {
      if (!result || typeof result !== 'object') return '<div class="note">' + esc(String(result)) + '</div>';
      if (result.error) return '<div class="note text-danger">' + esc(result.error) + '</div>';
      if (result.chart) return '<div class="note">Chart drawn below.</div>';
      var html = '', scalars = {};
      Object.keys(result).forEach(function (k) {
        var v = result[k];
        if (k === 'note' || v === null || v === undefined || HIDDEN_COLS[k] || k === 'identity_key') return;
        if (Array.isArray(v)) { if (v.length && typeof v[0] === 'object') html += '<div class="section">' + esc(k.replace(/_/g, ' ')) + ' (' + v.length + ')</div>' + table(v, ctx); else if (v.length) scalars[k] = v.join(', '); }
        else if (typeof v === 'object') html += '<div class="section">' + esc(k.replace(/_/g, ' ')) + '</div>' + kvBlock(v, ctx);
        else scalars[k] = v;
      });
      var keys = Object.keys(scalars);
      if (keys.length) html = kv(keys.map(function (k) { var url = linkFor(k, result, ctx), text = typeof scalars[k] === 'boolean' ? (scalars[k] ? 'yes' : 'no') : String(scalars[k]); return url ? [k.replace(/_/g, ' '), link(url, esc(text)), true] : [k.replace(/_/g, ' '), text]; })) + html;
      // every tool result has a page that shows the same data
      var where = evidencePage(ctx);
      if (where) html += '<div class="note">' + link(where.url, esc(where.label) + ' ↗') + '</div>';
      if (result.note) html += '<div class="note">' + esc(result.note) + '</div>';
      return html || '<div class="note">Empty result.</div>';
    }
    /** The screen that shows a whole tool result, for the footer link of its card. */
    function evidencePage(ctx) {
      if (!ctx) return null;
      var a = ctx.args || {}, r = ctx.result || {};
      switch (ctx.tool) {
        case 'find_identity': return { url: '/explore/ad-objects?type=users&q=' + encodeURIComponent(a.query || ''), label: 'Open in AD Objects' };
        case 'identity_profile': return r.account && r.account.ad_user_id ? { url: adUrl(r.account.account_type && r.account.account_type !== 'user' ? 'service_accounts' : 'users', r.account.ad_user_id), label: 'Open the AD object' } : null;
        case 'identity_access': return r.identity_key ? { url: evidenceUrl(r.identity_key, r.account), label: 'Open the access evidence in Risk' } : null;
        case 'identity_vulnerabilities': return { url: findingsUrl($.extend(scopeFilters(ctx), { account: a.account })), label: 'Open these findings' };
        case 'host_vulnerabilities': return { url: findingsUrl($.extend(scopeFilters(ctx), { target: a.target })), label: 'Open these findings' };
        case 'find_host': return { url: '/explore/assets?q=' + encodeURIComponent(a.query || ''), label: 'Open in Asset Inventory' };
        case 'host_identities': return r.host && r.host.host_asset_id ? { url: evidenceUrl(String(r.host.host_asset_id), r.host.host_name) + '&shape=host', label: 'Open the access evidence in Risk' } : null;
        case 'vulnerability_lookup': return { url: findingsUrl(/^\d+$/.test(a.query || '') ? { plugin: a.query } : /^cve-/i.test(a.query || '') ? { cve: a.query } : { q: a.query }), label: 'Open these findings' };
        case 'group_members': return r.group && r.group.ad_group_id ? { url: adUrl('groups', r.group.ad_group_id), label: 'Open the group' } : null;
        case 'search_software': return { url: '/explore/software?q=' + encodeURIComponent(a.name || ''), label: 'Open in Software Inventory' };
        case 'risk_overview': return { url: '/risk', label: 'Open the Risk screen' };
      }
      return null;
    }
    function toolCard(t) {
      var args = Object.keys(t.arguments || {}).map(function (k) { return k + '=' + JSON.stringify(t.arguments[k]); }).join(' ');
      var $c = $('<details class="tool-card"><summary><span class="name"></span><span class="args"></span>' +
        (t.ok === false ? '<span class="badge-soft bad">failed</span>' : '<span class="badge-soft ok">' + rowCount(t.result) + '</span>') +
        '<span class="ms"></span></summary><div class="body"></div></details>');
      $c.find('.name').text(t.name); $c.find('.args').text(args).attr('title', args); $c.find('.ms').text(t.ms !== undefined ? t.ms + ' ms' : '');
      $c.find('.body').html(t.result ? resultHtml(t.result, { tool: t.name, args: t.arguments || {}, result: t.result }) : '<div class="note"><span class="thinking"><i></i><i></i><i></i></span> running</div>');
      return $c;
    }
    function rowCount(result) {
      if (!result || typeof result !== 'object') return 'done';
      var n = 0;
      Object.keys(result).forEach(function (k) { if (Array.isArray(result[k])) n += result[k].length; });
      return n ? num(n) + ' rows' : 'done';
    }

    // -- charts
    var PALETTES = { severity: ['#91243e', '#dd4b50', '#f18c43', '#f8c851', '#b8bcc6'], tier: ['#91243e', '#b0541a', '#026edd', '#8b92a1'], accent: ['#026edd'] };
    function chartCard(c) {
      var id = 'agentChart' + (++chartCount);
      var $card = $('<div class="chart-card"><h4></h4><div class="box"><canvas></canvas></div></div>');
      $card.find('h4').text(c.title || c.series_label || 'Chart').toggle(!!(c.title || c.series_label));
      $card.find('canvas').attr('id', id);
      $card.data('chart', c);
      return $card;
    }
    function ensureChart(cb) {
      if (window.Chart) return cb();
      var src = ($('script[data-chart]').attr('data-chart') || '');
      if (!src) return;
      var s = document.createElement('script'); s.src = src; s.onload = cb; document.body.appendChild(s);
    }
    function drawChart(el) {
      var c = $(el).data('chart'), canvas = $(el).find('canvas')[0];
      if (!c || !canvas || canvas.dataset.drawn) return;
      ensureChart(function () {
        canvas.dataset.drawn = '1';
        var type = c.type === 'horizontalbar' ? 'bar' : c.type, pal = PALETTES[c.palette] || PALETTES.accent;
        var colors = c.labels.map(function (l, i) { return pal.length === 1 ? pal[0] : pal[i % pal.length]; });
        if (c.palette === 'severity') colors = c.labels.map(function (l, i) { var k = String(l).toLowerCase(); var n = ['critical', 'high', 'medium', 'low', 'info'].indexOf(k); return pal[n >= 0 ? n : i % pal.length]; });
        new Chart(canvas, { type: type, data: { labels: c.labels, datasets: [{ label: c.series_label || '', data: c.values, backgroundColor: type === 'line' ? 'rgba(2,110,221,0.15)' : colors, borderColor: type === 'line' ? '#026edd' : colors, fill: type === 'line', tension: 0.3, borderRadius: 4, maxBarThickness: 26 }] },
          options: { indexAxis: c.type === 'horizontalbar' ? 'y' : 'x', responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: type === 'doughnut' || !!c.series_label, position: type === 'doughnut' ? 'right' : 'top' } },
            scales: type === 'doughnut' ? {} : { x: { grid: { display: c.type === 'horizontalbar' } }, y: { grid: { display: c.type !== 'horizontalbar' }, beginAtZero: true } } } });
      });
    }

    // -- sending
    function autosize() { $input.css('height', 'auto').css('height', Math.min(160, $input[0].scrollHeight) + 'px'); }
    $input.on('input', autosize).on('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); } });
    $('#agentSend').on('click', send);
    $('#agentNew').on('click', function () { if (busy) return; state.messages = []; clearContext(); persist(); renderLog(); $input.val('').trigger('focus'); });
    function setContext(label, text) {
      state.context = text; state.contextLabel = label; persist();
      $('#agentContextLabel').text(label); $('#agentContextText').val(text).prop('hidden', true); $('#agentContext').prop('hidden', false);
    }
    function clearContext() { state.context = null; state.contextLabel = ''; $('#agentContext').prop('hidden', true); $('#agentContextText').prop('hidden', true); persist(); }
    $('#agentContextClear').on('click', clearContext);
    // The attached record can be read and trimmed before it is sent.
    $('#agentContextLabel').on('click', function () { $('#agentContextText').prop('hidden', !$('#agentContextText').prop('hidden')); });
    $('#agentContextText').on('input', function () { state.context = this.value; persist(); });
    if (state.context) { $('#agentContextLabel').text(state.contextLabel); $('#agentContextText').val(state.context); $('#agentContext').prop('hidden', false); }

    function send() {
      var text = $input.val().trim();
      if (!text || busy) return;
      busy = true; $('#agentSend').prop('disabled', true); $input.val(''); autosize();
      var userMsg = { role: 'user', content: text, contextLabel: state.contextLabel || '' };
      state.messages.push(userMsg); persist();
      $log.append(messageHtml(userMsg));
      var reply = { role: 'assistant', content: '', tools: [], charts: [], skill: $('#agentSkill option:selected').text() };
      var $m = $('<div class="msg assistant"></div>'), $bubble = $('<div class="bubble md"><span class="thinking"><i></i><i></i><i></i></span></div>');
      $m.append($bubble); $log.append($m); scrollLog();
      var cards = {}, streamed = '';
      function setBubble() { $bubble.html(md(streamed || reply.content) || '<span class="thinking"><i></i><i></i><i></i></span>'); }

      var form = new FormData();
      form.append('messages', JSON.stringify(state.messages.filter(function (m) { return m.role === 'user' || m.role === 'assistant'; }).map(function (m) { return { role: m.role, content: m.content }; })));
      form.append('skill', state.skill || '');
      form.append('context', state.context || '');
      var ctrl = new AbortController();
      fetch('/api.php?action=ai_chat', { method: 'POST', body: form, headers: { 'X-CSRF-Token': CSRF }, signal: ctrl.signal }).then(function (res) {
        if (!res.ok || !res.body) return res.json().then(function (j) { throw new Error(j.error || ('HTTP ' + res.status)); }, function () { throw new Error('HTTP ' + res.status); });
        var reader = res.body.getReader(), dec = new TextDecoder(), buf = '';
        function handle(ev, data) {
          if (ev === 'status') $status.text(data.text || '');
          else if (ev === 'delta') { streamed += data.text; setBubble(); scrollLog(); }
          else if (ev === 'thinking') $status.text('Thinking… ' + String(data.text || '').slice(-60).replace(/\s+/g, ' '));
          else if (ev === 'tool_call') { var t = { id: data.id, name: data.name, arguments: data.arguments }; reply.tools.push(t); var $c = toolCard(t); cards[data.id] = { t: t, $c: $c }; $bubble.before($c); $status.text('Running ' + data.name + '…'); scrollLog(); }
          else if (ev === 'tool_result') {
            var c = cards[data.id]; if (!c) return;
            c.t.ok = data.ok; c.t.ms = data.ms; c.t.result = data.result;
            var $n = toolCard(c.t); c.$c.replaceWith($n); c.$c = $n;
            if (data.result && data.result.chart) { reply.charts.push(data.result.chart); }
          }
          else if (ev === 'done') { reply.content = data.content || streamed; reply.stats = data.stats; }
          else if (ev === 'error') { throw new Error(data.message || 'The assistant failed.'); }
        }
        function pump() {
          return reader.read().then(function (r) {
            if (r.done) return;
            buf += dec.decode(r.value, { stream: true });
            var idx;
            while ((idx = buf.indexOf('\n\n')) >= 0) {
              var chunk = buf.slice(0, idx); buf = buf.slice(idx + 2);
              var ev = (chunk.match(/^event: (.+)$/m) || [])[1], dataLine = (chunk.match(/^data: (.*)$/m) || [])[1];
              if (ev && dataLine !== undefined) { try { handle(ev, JSON.parse(dataLine)); } catch (e) { if (e instanceof SyntaxError) continue; throw e; } }
            }
            return pump();
          });
        }
        return pump();
      }).then(function () {
        if (!reply.content && !reply.tools.length) throw new Error('The model returned an empty answer.');
        finish();
      }).catch(function (e) {
        reply.content = streamed; reply.error = e.message;
        finish();
        addMessage('error', e.message || 'The assistant failed.');
      });
      function finish() {
        busy = false; $('#agentSend').prop('disabled', false); $status.text('');
        if (reply.content || reply.tools.length) {
          state.messages.push(reply); persist();
          $m.replaceWith(messageHtml(reply));
          $log.find('.chart-card').each(function () { drawChart(this); });
        } else $m.remove();
        if (state.context) clearContext();
        scrollLog(); $input.trigger('focus');
      }
    }

    // -- "AI Analyze" from the detail panel
    function analyze(label, text, question) {
      setContext(label, text);
      open('chat');
      $input.val(question || '').trigger('focus');
      autosize();
      $input[0].setSelectionRange(0, $input.val().length);   // typing replaces the suggested question
    }

    applySize(); renderLog();
    if (state.messages.length) load();
    return { open: open, analyze: analyze };
  })();

  /** The detail panel as plain text: "key: value" lines and table rows, for the assistant. */
  function detailAsText() {
    var lines = [$('#detailTitle').text()];
    $('#detailBody').children().each(function () {
      var $el = $(this);
      if ($el.is('.kv')) { var cells = $el.children(); for (var i = 0; i + 1 < cells.length; i += 2) lines.push($(cells[i]).text().trim() + ': ' + $(cells[i + 1]).text().trim()); }
      else if ($el.is('table')) {
        var head = $el.find('thead th').map(function () { return $(this).text().trim(); }).get();
        $el.find('tbody tr').slice(0, 60).each(function () { lines.push($(this).find('td').map(function (i) { return (head[i] ? head[i] + ': ' : '') + $(this).text().trim().replace(/\s+/g, ' '); }).get().filter(Boolean).join(' | ')); });
        if ($el.find('tbody tr').length > 60) lines.push('… ' + ($el.find('tbody tr').length - 60) + ' more rows');
      }
      else if ($el.is('h3')) lines.push('', '## ' + $el.text().trim().replace(/\s+/g, ' '));
      else if ($el.is('ol, ul')) $el.children().each(function () { lines.push('- ' + $(this).text().trim().replace(/\s+/g, ' ')); });
      else if ($el.text().trim()) lines.push($el.text().trim());
    });
    return lines.join('\n').slice(0, 16000);
  }
  $('#detailAnalyze').on('click', function () {
    var kind = this.dataset.kind || 'record', title = $('#detailTitle').text();
    bootstrap.Offcanvas.getInstance('#detailPanel') && bootstrap.Offcanvas.getInstance('#detailPanel').hide();
    agent.analyze(title + ' (' + kind + ')', detailAsText(), 'Analyze this ' + kind + ': what stands out, how risky is it, and what should be done first?');
  });

  function initFindings() {
    var q = query();
    var FILTERS = ['host', 'target', 'cidr', 'account', 'plugin', 'cve', 'port', 'severity_min'];
    var state = { severity: /^[0-4]$/.test(q.get('severity') || '') ? q.get('severity') : '', exploitable: q.get('exploitable') === '1' ? '1' : '', q: q.get('q') || '', page: 1, size: 50, sort: 'vpr', dir: 'desc' };
    var scope = {};
    FILTERS.forEach(function (k) { if (q.get(k)) scope[k] = q.get(k); });
    var $root = $('#findings');
    $('#findingSearch').val(state.q);
    var cols = [['', null, function (r) { return sevBadge(r.severity); }],
      ['Host', 'host', function (r) { return '<a href="' + esc('/explore/assets?host=' + r.host_asset_id + '&plugin=' + r.plugin_id) + '"><b>' + esc(r.host_name) + '</b></a><div class="muted mono">' + esc(r.ip) + (r.host_tier !== null && r.host_tier !== undefined ? ' · ' + tierBadge(r.host_tier) : '') + '</div>'; }],
      ['Plugin', 'plugin', function (r) { return esc(r.plugin_name) + '<div class="muted mono">' + esc(r.plugin_id) + (r.family_name ? ' · ' + esc(r.family_name) : '') + '</div>'; }],
      ['VPR', 'vpr', function (r) { return r.vpr_score === null ? '' : '<b>' + esc(Number(r.vpr_score).toFixed(1)) + '</b>'; }],
      ['CVSS', 'cvss', function (r) { return r.cvss_v3 === null ? '' : esc(Number(r.cvss_v3).toFixed(1)); }],
      ['Port', 'port', function (r) { return Number(r.port) ? '<span class="mono">' + esc(r.port) + '/' + esc(r.protocol) + '</span>' : ''; }],
      ['CVE', null, function (r) { return r.cve.slice(0, 3).map(function (c) { return '<a class="chip" href="' + esc('/explore/findings?cve=' + c) + '">' + esc(c) + '</a>'; }).join('') + (r.cve.length > 3 ? '<span class="chip">+' + (r.cve.length - 3) + '</span>' : ''); }],
      ['Exploit', null, function (r) { return r.exploit_available ? '<span class="badge-soft bad">yes</span>' : ''; }],
      ['ACR', 'acr', function (r) { return esc(r.acr_score || ''); }],
      ['First seen', 'first_seen', function (r) { return esc(fmtDay(r.first_seen)); }],
      ['Last seen', 'last_seen', function (r) { return esc(fmtDay(r.last_seen)); }]];
    var LABELS = { host: 'Host', target: 'Host or network', cidr: 'Network', account: 'Reached by account', plugin: 'Plugin', cve: 'CVE', port: 'Port', severity_min: 'Severity at least' };
    function load() {
      $root.find('[data-f]').each(function () { $(this).toggleClass('active', state[this.dataset.f] === this.dataset.v); });
      renderHead($('#findingHead'), cols, state, false);
      $('#findingBody').html(loadingRow(cols.length));
      api('findings', $.extend({}, scope, { severity: state.severity, exploitable: state.exploitable, q: state.q, page: state.page, size: state.size, sort: state.sort, dir: state.dir })).done(function (d) {
        Object.keys(d.summary).forEach(function (k) { $('[data-count="' + k + '"]').text(num(d.summary[k])); });
        var chips = Object.keys(d.scope).map(function (k) {
          var v = d.scope[k], label = k === 'host' ? (v || scope.host) : v;
          return '<div class="scope-chip"><span class="muted">' + esc(LABELS[k] || k) + '</span><b>' + esc(label === true ? 'yes' : label) + '</b><a href="' + esc(removeFilter(k)) + '" title="Remove this filter" aria-label="Remove">×</a></div>';
        });
        $('#findingScopeList').html(chips.length ? chips.join('') : 'All findings');
        $('#findingHint').text(num(d.total) + ' findings on ' + num(d.summary.hosts) + ' hosts' + (d.summary.max_vpr ? ' · max VPR ' + Number(d.summary.max_vpr).toFixed(1) : ''));
        $('#findingBody').html(d.rows.length ? d.rows.map(function (r) {
          return '<tr>' + cols.map(function (c) { return '<td>' + c[2](r) + '</td>'; }).join('') + '</tr>';
        }).join('') : emptyRow(cols.length, 'No finding matches these filters.'));
        renderPager($('#findingPager'), state, d.total, load);
      }).fail(function (xhr) { $('#findingBody').html(emptyRow(cols.length, esc(errText(xhr)))); });
    }
    function removeFilter(k) { var p = new URLSearchParams(window.location.search); p.delete(k); if (k === 'host') p.delete('plugin'); return '/explore/findings' + (p.toString() ? '?' + p.toString() : ''); }
    $root.on('click', '[data-f]', function () { state[this.dataset.f] = this.dataset.v; state.page = 1; load(); });
    $('#findingSearch').on('input', debounce(function () { state.q = this.value.trim(); state.page = 1; load(); }, 300));
    bindSort($('#findingHead'), state, load);
    load();
  }

  function initSettingsAi() {
    var current = null;
    $('#aiForm').on('submit', function (e) {
      e.preventDefault();
      var data = $(this).serializeArray().reduce(function (o, f) { o[f.name] = f.value; return o; }, {});
      data.think = $('#ai_think').is(':checked') ? 1 : 0;
      post('ai_settings_save', data).done(function () { $('#aiStatus').text('Saved.'); toast('Settings saved.'); }).fail(function (xhr) { $('#aiStatus').text(errText(xhr)).addClass('text-danger'); });
    });
    $('#aiProbe').on('click', function () {
      $('#aiProbeStatus').text('Testing…');
      post('ai_probe', { url: $('#ai_url').val() }).done(function (m) {
        if (m.ok) {
          $('#aiProbeStatus').text('Reached Ollama ' + m.version + ' · ' + m.models.length + ' model' + (m.models.length === 1 ? '' : 's') + ': ' + m.models.map(function (x) { return x.name + (x.capabilities.indexOf('tools') >= 0 ? '' : ' (no tools)'); }).join(', '));
          $('#aiModels').html(m.models.map(function (x) { return '<option value="' + esc(x.name) + '">' + esc((x.parameter_size || '') + ' ' + x.capabilities.join(', ')) + '</option>'; }).join(''));
        } else $('#aiProbeStatus').text('Failed: ' + m.error);
      }).fail(function (xhr) { $('#aiProbeStatus').text(errText(xhr)); });
    });
    $('#aiOpenChat').on('click', function () { agent.open('chat'); });

    function loadKnowledge() {
      api('ai_knowledge').done(function (d) {
        $('#aiTree').html(['agents', 'skills', 'tools', 'prompts'].map(function (kind) {
          var files = d.tree[kind] || [];
          return '<div class="kind">' + kind + ' (' + files.length + ')</div>' + files.map(function (f) {
            return '<button type="button" data-kind="' + kind + '" data-name="' + esc(f.name) + '"' + (current && current.kind === kind && current.name === f.name ? ' class="active"' : '') + '>' + esc(f.title) + (f.source !== 'built-in' ? '<span class="src" title="' + esc(f.source) + '">pack</span>' : '') + '</button>';
          }).join('');
        }).join(''));
        renderPacks(d.packs);
        $('#aiToolName').html((d.tree.tools || []).filter(function (t) { return t.has_parameters; }).map(function (t) { return '<option>' + esc(t.name) + '</option>'; }).join(''));
      }).fail(function (xhr) { toast(errText(xhr), 'error'); });
    }
    $('#aiTree').on('click', 'button', function () {
      current = { kind: this.dataset.kind, name: this.dataset.name };
      $('#aiTree button').removeClass('active'); $(this).addClass('active');
      api('ai_knowledge_file', current).done(function (f) {
        $('#aiFileHead').html('<b>' + esc(f.title) + '</b><span class="muted mono">' + esc(f.kind + '/' + f.name + '.md') + '</span><span class="badge-soft">' + esc(f.source) + '</span>');
        var m = f.markdown.match(/^---\r?\n([\s\S]*?)\r?\n---\r?\n?([\s\S]*)$/);
        $('#aiFileBody').html((m ? '<pre class="front">' + esc(m[1]) + '</pre>' : '') + md(m ? m[2] : f.markdown));
      }).fail(function (xhr) { $('#aiFileBody').html('<div class="alert alert-danger">' + esc(errText(xhr)) + '</div>'); });
    });
    function renderPacks(packs) {
      $('#aiPacks').html(packs.length ? packs.map(function (p) {
        var counts = (p.manifest && p.manifest.counts) || {};
        return '<tr><td><b>' + esc(p.name) + '</b>' + (p.version ? ' <span class="chip">' + esc(p.version) + '</span>' : '') + '<div class="muted small-text">' + esc(p.description || p.file_name) + '</div></td>' +
          '<td>' + Object.keys(counts).map(function (k) { return '<span class="chip">' + esc(k) + ' ' + counts[k] + '</span>'; }).join('') + '</td>' +
          '<td class="muted text-nowrap">' + esc(fmtDate(p.uploaded_at)) + '</td><td class="text-nowrap">' + (p.size_bytes / 1024).toFixed(0) + ' KB</td>' +
          '<td><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" data-pack="' + esc(p.id) + '"' + (p.active ? ' checked' : '') + ' aria-label="Active"></div></td>' +
          '<td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger" data-del="' + esc(p.id) + '" data-name="' + esc(p.name) + '">Delete</button></td></tr>';
      }).join('') : emptyRow(6, 'No knowledge packs uploaded.'));
    }
    $('#aiPackForm').on('submit', function (e) {
      e.preventDefault();
      var file = $('#aiPackFile')[0].files[0];
      if (!file) { $('#aiPackStatus').text('Choose a zip file first.'); return; }
      var form = new FormData(); form.append('pack', file);
      $('#aiPackStatus').text('Uploading…'); $('#aiPackUpload').prop('disabled', true);
      fetch('/api.php?action=ai_pack_upload', { method: 'POST', body: form, headers: { 'X-CSRF-Token': CSRF } }).then(function (r) { return r.json().then(function (j) { if (!r.ok) throw new Error(j.error || 'Upload failed.'); return j; }); })
        .then(function (j) { $('#aiPackStatus').text('Uploaded ' + j.name + '. Turn it on with the Active switch.'); $('#aiPackFile').val(''); loadKnowledge(); })
        .catch(function (err) { $('#aiPackStatus').text(err.message); })
        .then(function () { $('#aiPackUpload').prop('disabled', false); });
    });
    $('#aiPacks').on('change', '[data-pack]', function () {
      post('ai_pack_set', { id: this.dataset.pack, active: this.checked ? 1 : 0 }).done(function () { toast('Pack updated.'); loadKnowledge(); }).fail(function (xhr) { toast(errText(xhr), 'error'); loadKnowledge(); });
    }).on('click', '[data-del]', function () {
      var id = this.dataset.del, name = this.dataset.name;
      confirmAction('Delete pack', 'Delete the knowledge pack "' + name + '"? Its files are removed from the server.', 'Delete', function () {
        post('ai_pack_delete', { id: id }).done(function () { toast('Pack deleted.'); loadKnowledge(); }).fail(function (xhr) { toast(errText(xhr), 'error'); });
      });
    });
    $('#aiToolRun').on('click', function () {
      $('#aiToolStatus').text('Running…'); $('#aiToolOut').prop('hidden', true);
      post('ai_tool_run', { name: $('#aiToolName').val(), arguments: $('#aiToolArgs').val() }).done(function (r) {
        $('#aiToolStatus').text(r.ms + ' ms'); $('#aiToolOut').prop('hidden', false).text(JSON.stringify(r.result, null, 2));
      }).fail(function (xhr) { $('#aiToolStatus').text(errText(xhr)); });
    });
    loadKnowledge();
  }

  // ---- start ----------------------------------------------------------------------------------------------
  var pages = { 'dashboard': initDashboard, 'explore-assets': initAssets, 'explore-ad': initAd, 'settings-sc': initSettingsSc,
    'settings-tie': initSettingsTie, 'logs': initLogs, 'risk': initRisk, 'explore-software': initSoftware, 'explore-local-users': initLocalUsers,
    'explore-extensions': initExtensions, 'settings-ctem': initCtem, 'settings-catalog': initCatalog, 'settings-ai': initSettingsAi, 'explore-findings': initFindings };
  if (pages[PAGE]) pages[PAGE]();
})(jQuery);
