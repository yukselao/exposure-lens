/* Exposure Lens UI. jQuery + Bootstrap 5 + Chart.js, no build step.
   Every value that comes from the server is passed through esc() before it
   becomes HTML. */
(function ($) {
  'use strict';

  var CSRF = $('meta[name="csrf-token"]').attr('content');
  var PAGE = document.body.dataset.page;
  var SEV = { 4: ['C', 'Critical'], 3: ['H', 'High'], 2: ['M', 'Medium'], 1: ['L', 'Low'], 0: ['I', 'Info'] };
  var SEV_COLOR = { 4: '#d92d20', 3: '#f2711c', 2: '#e0a100', 1: '#5b7fa6', 0: '#98a2b3' };

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

  // ---- shell: flyout menus -----------------------------------------------------
  function closeFlyouts() {
    $('.flyout').attr('hidden', true);
    $('.rail-item[data-flyout]').attr('aria-expanded', 'false');
  }
  $('.rail-item[data-flyout]').on('click', function (e) {
    e.stopPropagation();
    var $panel = $('#flyout-' + this.dataset.flyout), open = $panel.attr('hidden') === undefined;
    closeFlyouts();
    if (!open) { $panel.removeAttr('hidden'); $(this).attr('aria-expanded', 'true'); $panel.find('a').first().trigger('focus'); }
  });
  $(document).on('click', function (e) { if (!$(e.target).closest('.flyout').length) closeFlyouts(); });
  $(document).on('keydown', function (e) { if (e.key === 'Escape') closeFlyouts(); });

  // ---- dashboard ------------------------------------------------------------------
  function initDashboard() {
    api('dashboard').done(function (d) {
      var ad = d.ad || {};
      var stats = [
        ['Users', ad.users, '/explore/ad-objects?type=users'], ['Service accounts', ad.service_accounts, '/explore/ad-objects?type=service_accounts'],
        ['Groups', ad.groups, '/explore/ad-objects?type=groups'], ['Computers', ad.computers, '/explore/ad-objects?type=computers'],
        ['Group policies', ad.group_policies, '/explore/ad-objects?type=group_policies'], ['Disabled users', ad.users_disabled, '/explore/ad-objects?type=users&enabled=0']
      ];
      $('#dashAd').html(stats.map(function (s) {
        return '<div class="stat"><a href="' + s[2] + '"><div class="n">' + num(s[1]) + '</div><div class="l">' + s[0] + '</div></a></div>';
      }).join(''));

      // Tiers: one bar per tier, split by object type.
      var kinds = [['user', 'Users', '#0a6ad6'], ['service_account', 'Service accounts', '#7a5af8'], ['group', 'Groups', '#12a594'], ['computer', 'Computers', '#f2711c']];
      var byTier = { 0: {}, 1: {}, 2: {} }, max = 1;
      (d.tiers || []).forEach(function (r) { if (byTier[r.tier]) byTier[r.tier][r.object_type] = Number(r.n); });
      var totals = [0, 1, 2].map(function (t) { return kinds.reduce(function (a, k) { return a + (byTier[t][k[0]] || 0); }, 0); });
      max = Math.max.apply(null, totals.concat([1]));
      var html = [0, 1, 2].map(function (t) {
        var bar = kinds.map(function (k) {
          var n = byTier[t][k[0]] || 0;
          return n ? '<i style="width:' + (n / max * 100) + '%;background:' + k[2] + '" title="' + k[1] + ': ' + num(n) + '"></i>' : '';
        }).join('');
        return '<a class="tier-row text-decoration-none text-reset" href="/explore/ad-objects?type=users&tier=' + t + '"><span class="badge-soft tier-' + t + '">Tier ' + t +
          '</span><span class="bar">' + bar + '</span><span class="n">' + num(totals[t]) + '</span></a>';
      }).join('');
      html += '<div class="legend">' + kinds.map(function (k) { return '<span><i style="background:' + k[2] + '"></i>' + k[1] + '</span>'; }).join('') + '</div>';
      if (!totals[1] && !totals[2]) html += '<p class="muted mt-3 mb-0">Tier 0 comes from Identity Exposure. Assign Tier 1 and Tier 2 in <a href="/explore/ad-objects">AD Objects</a>.</p>';
      $('#dashTiers').html(html);

      var sevRows = {};
      (d.severity || []).forEach(function (r) { sevRows[r.severity] = r; });
      $('#dashSeverity').html([4, 3, 2, 1].map(function (s) {
        var r = sevRows[s] || {};
        return '<div class="sev-line">' + sevBadge(s) + '<span class="label">' + SEV[s][1] + '</span><span class="n">' + compact(r.n) + '</span><span class="age">' +
          (r.n ? 'average age ' + num(r.avg_age_days) + ' days' : 'none') + '</span></div>';
      }).join(''));

      $('#dashTopVpr').html((d.top_vpr || []).length ? d.top_vpr.map(function (v) {
        return '<tr><td><b>' + esc(Number(v.vpr_score).toFixed(1)) + '</b></td><td>' + sevBadge(v.severity) + '</td><td><div class="truncate" style="max-width:560px" title="' + esc(v.plugin_name) +
          '">' + esc(v.plugin_name) + '</div><span class="muted mono">Plugin ' + esc(v.plugin_id) + '</span></td><td class="text-end">' + num(v.hosts) + '</td><td>' +
          (v.exploit_available ? '<span class="badge-soft bad">Exploitable</span>' : '') + '</td></tr>';
      }).join('') : emptyRow(5, 'No finding with VPR 9 or higher. Sync a Security Center pool under Settings.'));

      var sync = {};
      (d.last_sync || []).forEach(function (r) { sync[r.connector] = r; });
      $('#dashTieSync').text(sync.tie_postgres ? 'Synced ' + ago(sync.tie_postgres.finished_at || sync.tie_postgres.requested_at) : 'Not synced yet');
      $('#dashScSync').text(sync.sc ? 'Synced ' + ago(sync.sc.finished_at || sync.sc.requested_at) : 'Not synced yet');

      var a = d.assets || {};
      $('#dashAssets').html(
        '<div class="sev-line"><span>Computers joined to the domain</span><span class="n ms-auto text-end">' + num(a.ad_computers) + '</span></div>' +
        '<div class="sev-line"><span>Computers not joined</span><span class="n ms-auto text-end">' + num(a.not_joined) + '</span></div>' +
        '<div class="sev-line"><span>IOT devices</span><span class="n ms-auto text-end">' + num(a.iot) + '</span></div>' +
        '<p class="muted mt-2 mb-0">' + num(a.matched) + ' of ' + num(a.sc_hosts) + ' Security Center hosts match an AD computer by name.</p>');

      if (typeof Chart === 'undefined') return;
      Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
      Chart.defaults.color = '#66758a';
      new Chart(document.getElementById('chartAssets'), {
        type: 'doughnut',
        data: { labels: ['Joined to the domain', 'Not joined', 'IOT devices'],
          datasets: [{ data: [a.ad_computers, a.not_joined, a.iot], backgroundColor: ['#0a6ad6', '#f2711c', '#7a5af8'], borderWidth: 2, borderColor: '#fff' }] },
        options: { maintainAspectRatio: false, cutout: '64%', plugins: { legend: { display: false } } }
      });

      var rows = (d.by_query || []).filter(function (r) { return Number(r.critical) + Number(r.high) + Number(r.medium) + Number(r.low) + Number(r.info) > 0; });
      if (!rows.length) {
        $('#chartByQuery').parent().addClass('d-none');
        $('#dashByQueryEmpty').removeClass('d-none').html('<h2>No findings yet</h2><p>Create a pool with a query under <a href="/settings/security-center">Settings</a> and sync it.</p>');
        return;
      }
      new Chart(document.getElementById('chartByQuery'), {
        type: 'bar',
        data: {
          labels: rows.map(function (r) { return r.pool + ' / ' + r.query; }),
          datasets: [[4, 'critical'], [3, 'high'], [2, 'medium'], [1, 'low'], [0, 'info']].map(function (s) {
            return { label: SEV[s[0]][1], data: rows.map(function (r) { return Number(r[s[1]]); }), backgroundColor: SEV_COLOR[s[0]], borderRadius: 3, maxBarThickness: 26 };
          })
        },
        options: { indexAxis: 'y', maintainAspectRatio: false,
          scales: { x: { stacked: true, grid: { color: '#eef1f5' }, ticks: { callback: function (v) { return compact(v); } } }, y: { stacked: true, grid: { display: false } } },
          plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, boxHeight: 10 } } } }
      });
    }).fail(function (xhr) { $('#dash').prepend('<div class="alert alert-danger" style="grid-column:1/-1">' + esc(errText(xhr)) + '</div>'); });
  }

  // ---- detail panel (AD object or device) -------------------------------------------
  function showDetail(title, html) {
    $('#detailTitle').text(title);
    $('#detailBody').html(html);
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
      showDetail(o.name || o.sam_account_name || 'AD object', html);
    }).fail(function (xhr) { showDetail('Not available', '<div class="alert alert-danger">' + esc(errText(xhr)) + '</div>'); });
  }
  function openDevice(id) {
    showDetail('Loading…', '');
    api('device', { id: id }).done(function (h) {
      var html = kv([['IP address', h.ip], ['DNS name', h.dns_name], ['NetBIOS name', h.netbios_name], ['MAC address', h.mac_address], ['Operating system', h.os],
        ['Device type', h.device_type], ['Repository', h.repository_name],
        ['Domain', h.domain_joined ? 'Joined (' + esc(h.ad_sam_account_name) + ')' : 'No matching AD computer', true],
        ['Asset criticality (ACR)', h.acr_score], ['Asset exposure (AES)', h.aes_score], ['First seen', fmtDate(h.first_seen)], ['Last seen', fmtDate(h.last_seen)]]);
      html += '<h3 class="fs-6 mt-4">Vulnerabilities <span class="muted fw-normal">(' + num(h.vulnerability_count) + (h.vulnerability_count > h.vulnerabilities.length ? ', top ' + h.vulnerabilities.length + ' shown' : '') +
        ')</span></h3><table class="table"><thead><tr><th></th><th>Plugin</th><th>VPR</th><th>Port</th></tr></thead><tbody>' +
        h.vulnerabilities.map(function (v) {
          return '<tr><td>' + sevBadge(v.severity) + '</td><td>' + esc(v.plugin_name) + '<div class="muted mono">' + esc(v.plugin_id) + (v.cve.length ? ' · ' + esc(v.cve.slice(0, 3).join(', ')) + (v.cve.length > 3 ? ' +' + (v.cve.length - 3) : '') : '') +
            '</div></td><td>' + (v.vpr_score === null ? '' : esc(Number(v.vpr_score).toFixed(1))) + '</td><td class="mono">' + (Number(v.port) ? esc(v.port) + '/' + esc(v.protocol) : '') + '</td></tr>';
        }).join('') + '</tbody></table>';
      showDetail(h.dns_name || h.ip || 'Host', html);
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
    var state = { type: ['users', 'devices', 'iot'].indexOf(q.get('type')) >= 0 ? q.get('type') : 'devices', source: '', q: '', page: 1, size: 50, sort: '', dir: 'asc' };
    var $root = $('#assets');

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
      q: '', page: 1, size: 50, sort: 'name', dir: 'asc' };
    var selected = {};
    var $root = $('#ad');

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

  // ---- start ----------------------------------------------------------------------------------------------
  var pages = { 'dashboard': initDashboard, 'explore-assets': initAssets, 'explore-ad': initAd, 'settings-sc': initSettingsSc,
    'settings-tie': initSettingsTie, 'logs': initLogs };
  if (pages[PAGE]) pages[PAGE]();
})(jQuery);
