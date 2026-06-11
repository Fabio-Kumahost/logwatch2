/* Logwatch2 UI — vanilla JS, no dependencies, CSP-strict.
 * All dynamic text goes through textContent (never innerHTML): log content
 * is hostile input by definition. Pages are dispatched via body[data-page]. */
'use strict';

const CSRF = document.body.dataset.csrf || '';

async function api(path, options = {}) {
  const opts = Object.assign({ headers: {} }, options);
  opts.headers['Accept'] = 'application/json';
  if (opts.body !== undefined) {
    opts.headers['Content-Type'] = 'application/json';
    opts.body = JSON.stringify(opts.body);
  }
  if (opts.method && opts.method !== 'GET') opts.headers['X-CSRF-Token'] = CSRF;
  const res = await fetch(path, opts);
  if (res.status === 401 && document.body.dataset.page !== 'login') {
    location.href = '/login';
    throw new Error('unauthorized');
  }
  const json = await res.json().catch(() => ({}));
  if (!res.ok) {
    const err = new Error((json.error && json.error.message) || res.statusText);
    err.code = json.error && json.error.code;
    throw err;
  }
  return json;
}

/* DOM builder: el('td', {class:'msg'}, 'text', el('b', {}, '!')) */
function el(tag, attrs = {}, ...children) {
  const node = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs)) {
    if (k === 'class') node.className = v;
    else if (k.startsWith('on')) node.addEventListener(k.slice(2), v);
    else node.setAttribute(k, v);
  }
  for (const c of children) {
    node.append(c instanceof Node ? c : document.createTextNode(String(c)));
  }
  return node;
}
const clear = (node) => { while (node.firstChild) node.removeChild(node.firstChild); };
const badge = (text) => el('span', { class: 'badge ' + text }, text);
const $ = (sel) => document.querySelector(sel);

document.addEventListener('DOMContentLoaded', () => {
  const logoutBtn = $('#logout-btn');
  if (logoutBtn) logoutBtn.addEventListener('click', async () => {
    await api('/api/v1/auth/logout', { method: 'POST' }).catch(() => {});
    location.href = '/login';
  });
  const pages = { login, dashboard, logs, 'error-detail': errorDetail, settings };
  const page = pages[document.body.dataset.page];
  if (page) page();
});

/* ---------- login ---------- */
function login() {
  const form = $('#login-form'), errBox = $('#login-error');
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    errBox.classList.add('hidden');
    const data = Object.fromEntries(new FormData(form));
    try {
      await api('/api/v1/auth/login', { method: 'POST', body: data });
      location.href = '/';
    } catch (err) {
      if (err.code === 'totp_required') {
        $('#totp-row').classList.remove('hidden');
        form.totp_code.focus();
        return;
      }
      errBox.textContent = err.message;
      errBox.classList.remove('hidden');
    }
  });
}

/* ---------- dashboard ---------- */
function dashboard() {
  async function load() {
    const { data } = await api('/api/v1/stats/dashboard');
    $('#stat-online').textContent = `${data.counts.online}/${data.counts.total}`;
    $('#stat-critical').textContent = data.counts.critical_24h;
    $('#stat-errors').textContent = data.counts.errors_24h;
    $('#stat-ai').textContent = `${data.counts.ai_used}/${data.counts.ai_budget}`;

    const list = $('#server-list');
    clear(list);
    for (const s of data.servers) {
      list.append(el('li', {},
        el('div', {}, el('strong', {}, s.name),
          el('div', { class: 'muted' }, s.last_seen_human ? 'seen ' + s.last_seen_human : 'never seen')),
        badge(s.status)));
    }
    if (!data.servers.length) list.append(el('li', { class: 'muted' }, 'no servers yet — add one in Settings'));

    const tbody = $('#errors-table tbody');
    clear(tbody);
    for (const g of data.recent_errors) {
      tbody.append(el('tr', {},
        el('td', {}, badge(g.level)),
        el('td', { class: 'msg' },
          el('a', { href: '/errors/' + g.id }, g.ai_summary || g.title),
          g.recurring ? ' 🔁' : ''),
        el('td', {}, g.service),
        el('td', {}, g.server_count),
        el('td', {}, g.occurrence_count),
        el('td', { class: 'muted' }, g.last_seen_human)));
    }

    const anomalies = $('#anomaly-list');
    clear(anomalies);
    if (!data.anomalies.length) anomalies.append(el('li', { class: 'muted' }, 'none detected'));
    for (const a of data.anomalies) {
      anomalies.append(el('li', {},
        el('span', {}, (a.kind === 'auth_attack' ? '🛡️ ' : '📈 ') +
          (a.server_name || '?') + ': ' + a.details),
        el('span', { class: 'muted' }, a.at_human)));
    }
  }
  load();
  setInterval(load, 15000);
}

/* ---------- logs ---------- */
function logs() {
  let page = 1;
  const form = $('#log-filters');

  api('/api/v1/servers').then(({ data }) => {
    for (const s of data) $('#filter-server').append(el('option', { value: s.uuid }, s.name));
  });

  async function load() {
    const params = new URLSearchParams({ page });
    for (const [k, v] of new FormData(form)) if (v) params.set(k, v);
    const res = await api('/api/v1/logs?' + params);
    const tbody = $('#logs-table tbody');
    clear(tbody);
    for (const e of res.data) {
      tbody.append(el('tr', {},
        el('td', { class: 'muted' }, e.ts.replace('T', ' ').slice(0, 19)),
        el('td', {}, e.server_name),
        el('td', {}, e.service),
        el('td', {}, badge(e.level)),
        el('td', { class: 'msg' }, e.error_group_id
          ? el('a', { href: '/errors/' + e.error_group_id }, e.message)
          : e.message)));
    }
    if (!res.data.length) tbody.append(el('tr', {}, el('td', { colspan: 5, class: 'muted' }, 'no entries match')));
    const pages = Math.max(1, Math.ceil(res.meta.total / res.meta.per_page));
    $('#page-info').textContent = `page ${page}/${pages} · ${res.meta.total} entries`;
    $('#prev-page').disabled = page <= 1;
    $('#next-page').disabled = page >= pages;
  }
  form.addEventListener('submit', (e) => { e.preventDefault(); page = 1; load(); });
  $('#prev-page').addEventListener('click', () => { page = Math.max(1, page - 1); load(); });
  $('#next-page').addEventListener('click', () => { page += 1; load(); });
  load();
}

/* ---------- error detail ---------- */
function errorDetail() {
  const id = document.body.dataset.groupId;

  async function load() {
    const { data } = await api('/api/v1/errors/' + id);
    const g = data.group;
    $('#err-title').textContent = `#${id} · ${g.title}`;
    const meta = $('#err-meta');
    clear(meta);
    meta.append(
      badge(g.level), el('span', {}, 'status: ' + g.status),
      el('span', {}, 'service: ' + g.service), el('span', {}, 'file: ' + g.source_class),
      el('span', {}, `count: ${g.occurrence_count}`),
      el('span', {}, 'first: ' + g.first_seen), el('span', {}, 'last: ' + g.last_seen));

    const ai = $('#ai-body');
    clear(ai);
    if (!g.summary) {
      ai.append(el('p', { class: 'muted' },
        'No AI analysis yet. It runs automatically for new errors when AI is enabled — or click Re-analyze.'));
    } else {
      $('#ai-meta').textContent = `${g.provider}/${g.model} · ${g.analyzed_at}`;
      const sevBar = el('span', { class: 'severity-bar s' + g.ai_severity },
        ...[1, 2, 3, 4, 5].map(() => el('span')));
      ai.append(
        el('p', {}, el('strong', {}, g.summary)),
        el('p', {}, sevBar, ` severity ${g.ai_severity}/5 · urgency: ${g.urgency}`),
        el('h3', {}, 'What happened'), el('p', {}, g.explanation),
        el('h3', {}, 'Probable causes'),
        el('ol', {}, ...JSON.parse(g.probable_causes || '[]').map((c) => el('li', {}, c))),
        el('h3', {}, 'Impact'), el('p', {}, g.impact),
        el('h3', {}, 'Fix steps'),
        el('ol', {}, ...JSON.parse(g.solution_steps || '[]').map((s) => el('li', {}, s))),
        el('h3', {}, 'Commands'),
        ...JSON.parse(g.commands || '[]').flatMap((c) => [
          el('div', { class: 'muted' }, c.description), el('pre', { class: 'cmd' }, c.command)]),
        el('h3', {}, 'Also check'),
        el('ul', {}, ...JSON.parse(g.related_checks || '[]').map((c) => el('li', {}, c))));
    }

    const servers = $('#err-servers');
    clear(servers);
    for (const s of data.servers) servers.append(el('li', {}, el('span', {}, s.name), badge(s.status)));

    const occ = $('#err-occurrences');
    clear(occ);
    for (const o of data.occurrences) {
      occ.append(el('div', { class: 'muted' }, `${o.ts} · ${o.server_name} · ${o.source_file}`),
        el('pre', {}, o.raw));
    }
  }

  for (const btn of document.querySelectorAll('[data-status]')) {
    btn.addEventListener('click', async () => {
      await api('/api/v1/errors/' + id, { method: 'PATCH', body: { status: btn.dataset.status } });
      load();
    });
  }
  $('#reanalyze-btn').addEventListener('click', async () => {
    try {
      await api(`/api/v1/errors/${id}/analyze`, { method: 'POST' });
      $('#ai-meta').textContent = 'queued — refresh in a few seconds';
    } catch (err) { alert(err.message); }
  });
  load();
}

/* ---------- settings ---------- */
function settings() {
  loadAi(); loadChannels(); loadRules(); loadServers(); loadUsers(); loadTotp();

  async function loadAi() {
    const { data } = await api('/api/v1/settings/ai');
    const f = $('#ai-form');
    f.enabled.checked = data.enabled;
    f.provider.value = data.provider;
    f.model.value = data.model;
    f.base_url.value = data.base_url;
    $('#ai-key-state').textContent = data.key_set ? 'API key: stored ✓' : 'API key: not set';
    f.onsubmit = async (e) => {
      e.preventDefault();
      await api('/api/v1/settings/ai', { method: 'PUT', body: {
        enabled: f.enabled.checked, provider: f.provider.value, model: f.model.value,
        base_url: f.base_url.value, api_key: f.api_key.value } });
      f.api_key.value = '';
      loadAi();
    };
  }

  $('#mask-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const { data } = await api('/api/v1/settings/mask-preview',
      { method: 'POST', body: { sample: e.target.sample.value } });
    const out = $('#mask-result');
    out.textContent = data.masked;
    out.classList.remove('hidden');
  });

  async function loadChannels() {
    const { data } = await api('/api/v1/notify/channels');
    const list = $('#channel-list'), sel = $('#rule-channel');
    clear(list); clear(sel);
    for (const c of data) {
      sel.append(el('option', { value: c.id }, c.name));
      list.append(el('li', {}, el('span', {}, `${c.type === 'discord' ? '💬' : '📨'} ${c.name}`),
        el('span', { class: 'actions' },
          el('button', { class: 'btn', onclick: () => api(`/api/v1/notify/channels/${c.id}/test`,
            { method: 'POST' }).then(() => alert('test sent')).catch((e2) => alert(e2.message)) }, 'Test'),
          el('button', { class: 'btn btn-danger', onclick: () => api('/api/v1/notify/channels/' + c.id,
            { method: 'DELETE' }).then(loadChannels) }, '✕'))));
    }
  }
  $('#channel-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const f = e.target;
    const config = f.type.value === 'discord'
      ? { webhook_url: f.webhook_url.value }
      : { server_url: f.server_url.value, app_token: f.app_token.value };
    await api('/api/v1/notify/channels', { method: 'POST',
      body: { type: f.type.value, name: f.name.value, config } }).catch((err) => alert(err.message));
    f.reset(); loadChannels();
  });

  async function loadRules() {
    const { data } = await api('/api/v1/notify/rules');
    const list = $('#rule-list');
    clear(list);
    for (const r of data) {
      list.append(el('li', {},
        el('span', {}, `${r.trigger} → ${r.channel_name} (cooldown ${r.cooldown_seconds}s)`),
        el('button', { class: 'btn btn-danger', onclick: () => api('/api/v1/notify/rules/' + r.id,
          { method: 'DELETE' }).then(loadRules) }, '✕')));
    }
  }
  $('#rule-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const f = e.target;
    await api('/api/v1/notify/rules', { method: 'POST', body: {
      trigger: f.trigger.value, channel_id: Number(f.channel_id.value),
      cooldown_seconds: Number(f.cooldown_seconds.value) } }).catch((err) => alert(err.message));
    loadRules();
  });

  async function loadServers() {
    const { data } = await api('/api/v1/servers');
    const list = $('#srv-admin-list');
    clear(list);
    for (const s of data) {
      list.append(el('li', {}, el('span', {}, s.name + ' '), el('span', { class: 'actions' },
        badge(s.status),
        el('button', { class: 'btn', onclick: async () => {
          if (!confirm('Rotate token? The old one stops working immediately.')) return;
          const res = await api(`/api/v1/servers/${s.uuid}/token/rotate`, { method: 'POST' });
          reveal(`${s.name} new token:\n${res.data.token}`);
        } }, 'Rotate token'),
        el('button', { class: 'btn btn-danger', onclick: () => {
          if (confirm(`Delete ${s.name} and ALL its log data?`))
            api('/api/v1/servers/' + s.uuid, { method: 'DELETE' }).then(loadServers);
        } }, '✕'))));
    }
  }
  function reveal(text) {
    const pre = $('#token-reveal');
    pre.textContent = text + '\n\n(shown once — copy it now)';
    pre.classList.remove('hidden');
  }
  $('#server-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    try {
      const res = await api('/api/v1/servers', { method: 'POST', body: { name: e.target.name.value } });
      reveal(`${res.data.server.name} agent token:\n${res.data.token}`);
      e.target.reset(); loadServers();
    } catch (err) { alert(err.message); }
  });

  async function loadUsers() {
    const { data } = await api('/api/v1/users');
    const list = $('#user-list');
    clear(list);
    for (const u of data) {
      list.append(el('li', {},
        el('span', {}, `${u.username} · ${u.role}${u.totp_enabled ? ' · 2FA ✓' : ''}${u.is_active ? '' : ' · disabled'}`),
        el('button', { class: 'btn btn-danger', onclick: () => api('/api/v1/users/' + u.id,
          { method: 'DELETE' }).then(loadUsers).catch((e2) => alert(e2.message)) }, '✕')));
    }
  }
  $('#user-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const f = e.target;
    await api('/api/v1/users', { method: 'POST', body: {
      username: f.username.value, password: f.password.value, role: f.role.value } })
      .catch((err) => alert(err.message));
    f.reset(); loadUsers();
  });

  let totpEnabled = false;
  async function loadTotp() {
    const { data } = await api('/api/v1/auth/me');
    totpEnabled = data.totp_enabled;
    $('#totp-state').textContent = totpEnabled
      ? '2FA is enabled. Enter a current code to disable it.'
      : '2FA is disabled. Click the button to start setup.';
    $('#totp-action').textContent = totpEnabled ? 'Disable 2FA' : 'Enable 2FA';
  }
  $('#totp-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const code = e.target.code.value;
    try {
      if (totpEnabled) {
        await api('/api/v1/auth/totp/disable', { method: 'POST', body: { code } });
        $('#totp-setup').classList.add('hidden');
      } else if ($('#totp-setup').classList.contains('hidden')) {
        const res = await api('/api/v1/auth/totp/setup', { method: 'POST' });
        $('#totp-secret').textContent = `secret: ${res.data.secret}\n${res.data.otpauth_uri}`;
        $('#totp-setup').classList.remove('hidden');
        return;
      } else {
        await api('/api/v1/auth/totp/confirm', { method: 'POST', body: { code } });
        $('#totp-setup').classList.add('hidden');
      }
      e.target.reset(); loadTotp();
    } catch (err) { alert(err.message); }
  });
}
