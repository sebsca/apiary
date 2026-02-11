// app.js - vanilla SPA
const API_URL = './api.php';

// ============================================================================
// CONFIGURATION
// ============================================================================

const CONFIG = {
  BREAKPOINT_MOBILE: 720,
  TIMEOUTS: {
    SHORT: 200,
    MEDIUM: 250,
    LONG: 350
  },
  DEFAULTS: {
    RESET_PASSWORD: '12345678',
    UNKNOWN_VALUE: 'k.A.'
  },
  VALIDATION: {
    MIN_PASSWORD_LENGTH: 7
  }
};

// ============================================================================
// UTILITIES
// ============================================================================

// Normalize form data: convert empty strings to null
const normalizeFormData = (data) => {
  Object.keys(data).forEach(k => {
    if (data[k] === '') data[k] = null;
  });
  return data;
};

// Handle errors with logging and optional UI feedback
const handleError = (err, msgElement, fallback = 'An error occurred') => {
  console.error(err);
  if (msgElement) msgElement.textContent = `Error: ${err?.message || fallback}`;
};

// Handle API response errors (consolidates apiGet/apiPost error handling)
const handleApiResponse = async (res, opts = {}) => {
  const data = await res.json().catch(() => ({}));
  if (res.status === 401) {
    setAuth(null);
    if (!opts.suppressAuthRedirect) redirectToLogin();
    throw new Error(data.error || 'Unauthorized');
  }
  if (!res.ok) throw new Error(data.error || `HTTP ${res.status}`);
  return data;
};

// ============================================================================
// DOM REFERENCES
// ============================================================================

const app = document.getElementById('app');
const tabStandorte = document.getElementById('tab-standorte');
const tabQueens = document.getElementById('tab-queens');
const authStatus = document.getElementById('auth-status');
const authAdmin = document.getElementById('auth-admin');
const authAccount = document.getElementById('auth-account');
const authAction = document.getElementById('auth-action');
const topbarActions = document.getElementById('topbar-actions');
const topbarBack = document.getElementById('topbar-back');
const topbarRight = document.querySelector('.topbar-right');
const topbarBrand = document.querySelector('.brand');
const menuToggle = document.getElementById('menu-toggle');
const menuPanel = document.getElementById('topbar-menu');

const authState = { user: null, checked: false, csrf: null };
let authReady = null;
let topbarActionLayoutRaf = 0;

function updateTopbarActionLayout() {
  if (!topbarActions || !topbarRight || !topbarBrand) return;
  const hasActions = !topbarActions.hidden && topbarActions.children.length > 0;
  topbarActions.classList.remove('topbar-actions-wrapped');
  if (!hasActions) return;

  const firstRowTop = topbarBrand.offsetTop;
  const actionsWrapped = topbarActions.offsetTop > firstRowTop + 1;
  const backWrapped = topbarRight.offsetTop > firstRowTop + 1;
  if (actionsWrapped || backWrapped) {
    topbarActions.classList.add('topbar-actions-wrapped');
  }
}

function scheduleTopbarActionLayout() {
  if (!topbarActions) return;
  if (topbarActionLayoutRaf) cancelAnimationFrame(topbarActionLayoutRaf);
  topbarActionLayoutRaf = requestAnimationFrame(() => {
    topbarActionLayoutRaf = 0;
    updateTopbarActionLayout();
  });
}

function setMenuOpen(open) {
  document.body.classList.toggle('menu-open', open);
  if (menuToggle) {
    menuToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    menuToggle.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
  }
}

function setTopbarBack(onClick = null) {
  if (!topbarBack) return;
  if (!onClick) {
    topbarBack.hidden = true;
    topbarBack.onclick = null;
    scheduleTopbarActionLayout();
    return;
  }
  topbarBack.hidden = false;
  topbarBack.onclick = (event) => {
    event.preventDefault();
    onClick();
  };
  scheduleTopbarActionLayout();
}

function setTopbarActions(actions = []) {
  if (!topbarActions) return;
  topbarActions.innerHTML = '';
  if (!actions || actions.length === 0) {
    topbarActions.hidden = true;
    topbarActions.classList.remove('topbar-actions-wrapped');
    scheduleTopbarActionLayout();
    return;
  }
  topbarActions.hidden = false;
  actions.forEach((action) => {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = `btn${action.primary ? ' primary' : ''}`;
    btn.textContent = action.label;
    btn.disabled = !!action.disabled;
    if (action.onClick && !action.disabled) {
      btn.addEventListener('click', (event) => {
        event.preventDefault();
        action.onClick();
      });
    }
    topbarActions.appendChild(btn);
  });
  scheduleTopbarActionLayout();
}

if (menuToggle) {
  menuToggle.addEventListener('click', () => {
    const open = !document.body.classList.contains('menu-open');
    setMenuOpen(open);
  });
}

if (menuPanel) {
  menuPanel.addEventListener('click', (event) => {
    const target = event.target;
    if (target && target.closest && target.closest('a, button')) {
      setMenuOpen(false);
    }
  });
}

window.addEventListener('hashchange', () => {
  setMenuOpen(false);
  scheduleTopbarActionLayout();
});

window.addEventListener('resize', () => {
  if (window.innerWidth > 720) {
    setMenuOpen(false);
  }
  scheduleTopbarActionLayout();
});

function setAuth(user, csrfToken = null) {
  authState.user = user || null;
  if (csrfToken) {
    authState.csrf = csrfToken;
  } else if (!user) {
    authState.csrf = null;
  }
  authState.checked = true;
  updateAuthUi();
}

function canWrite() {
  return !!authState.user && ['admin', 'contributor'].includes(authState.user.role);
}

function isAdmin() {
  return !!authState.user && authState.user.role === 'admin';
}

function updateAuthUi() {
  if (!authStatus || !authAction || !authAccount) return;
  if (authState.user) {
    const name = authState.user.username || 'user';
    authStatus.textContent = `Signed in as ${name}`;
    if (authAdmin) {
      if (isAdmin()) {
        authAdmin.style.display = '';
        authAdmin.textContent = 'User Administration';
        authAdmin.onclick = () => {
          location.hash = '#/admin/users';
        };
      } else {
        authAdmin.style.display = 'none';
      }
    }
    authAccount.style.display = '';
    authAccount.textContent = 'Change password';
    authAccount.onclick = () => {
      location.hash = '#/account';
    };
    authAction.textContent = 'Log out';
    authAction.onclick = async () => {
      authAction.disabled = true;
      try {
        await apiPost({ action:'logout' }, {}, { suppressAuthRedirect: true });
      } catch (_) {
        // best-effort logout
      } finally {
        authAction.disabled = false;
        setAuth(null);
        location.hash = '#/';
      }
    };
  } else {
    authStatus.textContent = authState.checked ? 'Not signed in' : 'Checking...';
    if (authAdmin) authAdmin.style.display = 'none';
    authAccount.style.display = 'none';
    authAction.textContent = 'Log in';
    authAction.onclick = () => {
      const next = encodeURIComponent(location.hash || '#/');
      location.hash = `#/login?next=${next}`;
    };
  }
}

function redirectToLogin() {
  if (location.hash.startsWith('#/login')) return;
  const next = encodeURIComponent(location.hash || '#/');
  location.hash = `#/login?next=${next}`;
}

async function initAuth() {
  try {
    const res = await apiGet({ action:'me' }, { suppressAuthRedirect: true });
    setAuth(res.user || null, res.csrf || null);
  } catch (_) {
    setAuth(null);
  }
}

function setActiveTab(path) {
  tabStandorte.classList.toggle('active', path === '/' || path.startsWith('/standort') || path.startsWith('/hive') || path.startsWith('/visit'));
  tabQueens.classList.toggle('active', path.startsWith('/queens') || path.startsWith('/queen'));
}

function htmlesc(s) {
  return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

async function apiGet(params, opts = {}) {
  const url = new URL(API_URL, window.location.href);
  Object.entries(params).forEach(([k,v]) => url.searchParams.set(k, v));
  const res = await fetch(url.toString(), {
    headers: { 'Accept': 'application/json' },
    credentials: 'same-origin'
  });
  return handleApiResponse(res, opts);
}

async function apiPost(params, bodyObj, opts = {}) {
  const url = new URL(API_URL, window.location.href);
  Object.entries(params).forEach(([k,v]) => url.searchParams.set(k, v));
  const headers = { 'Content-Type':'application/json', 'Accept':'application/json' };
  if (authState.csrf) headers['X-CSRF-Token'] = authState.csrf;
  const res = await fetch(url.toString(), {
    method: 'POST',
    headers,
    body: JSON.stringify(bodyObj ?? {}),
    credentials: 'same-origin'
  });
  return handleApiResponse(res, opts);
}

function parseRoute() {
  const h = (location.hash || '#/').slice(1);
  const [path, query] = h.split('?');
  const parts = path.split('/').filter(Boolean);
  return { path: '/' + (parts[0] || ''), parts, query: new URLSearchParams(query || '') };
}

function card(title, subtitle, innerHtml, titleClass = '') {
  const titleClassAttr = titleClass ? `title ${titleClass}` : 'title';
  return `
    <section class="card">
      <div class="hstack">
        <div class="vstack" style="gap:4px">
          <div class="${titleClassAttr}">${htmlesc(title)}</div>
          ${subtitle ? `<div class="subtitle">${htmlesc(subtitle)}</div>` : ''}
        </div>
      </div>
      <div style="height:12px"></div>
      ${innerHtml}
    </section>
  `;
}

function fmtDate(d) {
  if (!d) return '—';
  const m = String(d).match(/^(\d{4})-(\d{2})-(\d{2})/);
  if (m) return `${m[3]}.${m[2]}.${m[1]}`;
  return d;
}

function authGateHtml({ title, subtitle }) {
  const next = encodeURIComponent(location.hash || '#/');
  return card(title, subtitle, `
    <div class="notice">Please sign in to continue.</div>
    <div style="height:12px"></div>
    <div class="hstack">
      <button class="btn primary" onclick="location.hash='#/login?next=${next}'">Sign in</button>
    </div>
  `);
}

async function renderStandorte() {
  setActiveTab('/');
  app.innerHTML = card('Locations', null, `<div class="skeleton"></div>`);
  const data = await apiGet({ action:'standorte' });
  const canEdit = canWrite();
  let addHiveBtn = '';
  if (canEdit) {
    setTopbarActions([
      { label: 'Add Hive', primary: true, onClick: () => { location.hash = '#/hive/new'; } }
    ]);
  } else if (authState.user) {
    addHiveBtn = `<button class="btn" disabled>Read-only</button>`;
  } else {
    addHiveBtn = `<button class="btn" onclick="location.hash='#/login?next=${encodeURIComponent('#/hive/new')}'">Sign in to add</button>`;
  }

  const rows = data.standorte.map(r => `
    <tr role="button" tabindex="0" onclick="location.hash='#/standort/${encodeURIComponent(r.Standort)}'">
      <td>${htmlesc(r.Standort)}</td>
      <td>${htmlesc(r.active_hives)}</td>
      <td>${r.todo_hives > 0 ? htmlesc(r.todo_hives) : ''}</td>
    </tr>
  `).join('');

  app.innerHTML = card('Locations', null, `
    <div class="hstack">
      ${addHiveBtn}
    </div>
    <table class="table queen-table">
      <thead><tr><th>Location</th><th>Active hives</th><th>Hives with to-do</th></tr></thead>
      <tbody>${rows || `<tr><td colspan="3" class="muted">No data</td></tr>`}</tbody>
    </table>
  `);
}

async function renderQueens() {
  setActiveTab('/queens');
  app.innerHTML = card('Queens', null, `<div class="skeleton"></div>`);
  const data = await apiGet({ action:'queens' });
  const canEdit = canWrite();
  const strong = v => (v ? `<strong>${htmlesc(v)}</strong>` : '');
  const joinParts = parts => parts.filter(p => p && String(p).length > 0).join(' · ');
  let addQueenBtn = '';
  if (canEdit) {
    setTopbarActions([
      { label: 'Add Queen', primary: true, onClick: () => { location.hash = '#/queen/new'; } }
    ]);
  } else if (authState.user) {
    addQueenBtn = `<button type="button" class="btn" disabled>Read-only</button>`;
  } else {
    addQueenBtn = `<button type="button" class="btn" onclick="location.hash='#/login?next=${encodeURIComponent('#/queen/new')}'">Sign in to add</button>`;
  }

  const rows = data.queens.map(q => `
    <tr role="button" tabindex="0" onclick="location.hash='#/queen/${encodeURIComponent(q.ID)}'">
      <td>
        <div class="vstack" style="gap:4px">
          <div class="qline">
            <div class="qleft">${joinParts([
              strong(q.ID),
              htmlesc(q.Rasse || ''),
              htmlesc(q.gezeichnet || ''),
              htmlesc(q.Belegstelle || ''),
            ])}</div>
            <div class="qright">${strong(q.Hive_nr || '')}</div>
          </div>
          <div class="qline muted">
            <div class="qleft">${joinParts([
              htmlesc(q.Geburtsjahr || ''),
              htmlesc(q.Züchter || ''),
              htmlesc(q.LN_Mutter || ''),
            ])}</div>
            <div class="qright">${strong(q.Standort || '')}</div>
          </div>
        </div>
      </td>
    </tr>
  `).join('');

  app.innerHTML = card('Queens', null, `
    <div class="hstack">
      ${addQueenBtn}
    </div>
    <table class="table queens-table">
      <thead><tr><th>Queen</th></tr></thead>
      <tbody>${rows || `<tr><td class="muted">No queens found</td></tr>`}</tbody>
    </table>
  `);
}

async function renderQueenEdit(queenId) {
  setActiveTab('/queen');
  if (!authState.user) {
    setTopbarBack(() => history.back());
    app.innerHTML = authGateHtml({ title: 'Queen', subtitle: `#${queenId}` });
    return;
  }
  setTopbarBack(() => history.back());
  const writable = canWrite();
  app.innerHTML = card('Queen', `#${queenId}`, `<div class="skeleton"></div>`);
  try {
    const data = await apiGet({ action:'queen', id: queenId });
    const q = data.queen;

    app.innerHTML = card('Queen', `Edit #${q.ID}`, `
      ${!writable ? `<div class="notice">Read-only access.</div><div style="height:12px"></div>` : ''}
      ${queenFormHtml({ q, mode:'update', readOnly: !writable })}
    `);

    if (writable) wireQueenForm({ queenId: q.ID, mode:'update' });
  } catch (err) {
    app.innerHTML = card('Queen', `#${queenId}`, `
      <div class="notice">Error loading queen: ${htmlesc(err.message)}</div>
    `);
  }
}

async function renderQueenCreate() {
  setActiveTab('/queen');
  if (!authState.user || !canWrite()) {
    setTopbarBack(() => history.back());
    app.innerHTML = card('Queen', 'New', `
      <div class="notice">Write access required to create queens.</div>
    `);
    return;
  }
  setTopbarBack(() => {
    location.hash = '#/queens';
  });
  app.innerHTML = card('Queen', 'New', `<div class="skeleton"></div>`);

  const q = {
    Lebensnummer: null,
    Geburtsjahr: null,
    gezeichnet: null,
    Rasse: null,
    'Züchter': null,
    LN_Mutter: null,
    LN_Vatermutter: null,
    Belegstelle: null,
  };

  app.innerHTML = card('Queen', 'Create new queen', `
    ${queenFormHtml({ q, mode:'create', readOnly: false })}
  `);

  wireQueenForm({ mode:'create' });
}

function queenFormHtml({ q, mode='update', readOnly=false }) {
  const isCreate = mode === 'create';
  const submitLabel = isCreate ? 'Create queen' : 'Save changes';
  const cancelAction = isCreate ? "location.hash='#/queens'" : 'history.back()';
  const deleteBtn = isCreate || readOnly ? '' : `<button type="button" class="btn danger" id="queen-delete">Delete queen</button>`;
  const submitBtn = readOnly ? '' : `<button type="submit" class="btn primary">${submitLabel}</button>`;

  return `
  <form id="queen-form" class="vstack">
    <fieldset class="form single" ${readOnly ? 'disabled' : ''}>
      <div class="field">
        <label>Life no.</label>
        <input name="Lebensnummer" value="${htmlesc(q.Lebensnummer || '')}" placeholder="e.g., 24-178-003"/>
      </div>

      <div class="field">
        <label>Birth year</label>
        <input name="Geburtsjahr" value="${htmlesc(q.Geburtsjahr || '')}" placeholder="e.g., 2024"/>
      </div>

      <div class="field">
        <label>Marked</label>
        <input name="gezeichnet" value="${htmlesc(q.gezeichnet || '')}" placeholder="e.g., yellow / unmarked"/>
      </div>

      <div class="field">
        <label>Breed</label>
        <input name="Rasse" value="${htmlesc(q.Rasse || '')}" placeholder="e.g., Carnica"/>
      </div>

      <div class="field">
        <label>Breeder</label>
        <input name="Zuechter" value="${htmlesc(q['Züchter'] || '')}" placeholder="Breeder name"/>
      </div>

      <div class="field">
        <label>Mother (life no.)</label>
        <input name="LN_Mutter" value="${htmlesc(q.LN_Mutter || '')}" placeholder="Life no. of mother"/>
      </div>

      <div class="field">
        <label>Mother of father (life no.)</label>
        <input name="LN_Vatermutter" value="${htmlesc(q.LN_Vatermutter || '')}" placeholder="Life no. of father's mother"/>
      </div>

      <div class="field">
        <label>Mating station</label>
        <input name="Belegstelle" value="${htmlesc(q.Belegstelle || '')}" placeholder="Belegstelle"/>
      </div>
    </fieldset>

    <div class="hstack" style="justify-content:space-between; gap:8px">
      ${deleteBtn}
      <div class="hstack" style="gap:8px">
        <button type="button" class="btn" onclick="${cancelAction}">Cancel</button>
        ${submitBtn}
      </div>
    </div>

    <div id="queen-form-msg" class="muted" aria-live="polite"></div>
  </form>`;
}

function wireQueenForm({ queenId, mode='update' }) {
  const form = document.getElementById('queen-form');
  const msg = document.getElementById('queen-form-msg');
  const delBtn = document.getElementById('queen-delete');

  if (delBtn) {
    delBtn.addEventListener('click', async () => {
      if (!confirm('Delete this queen? This cannot be undone.')) return;
      msg.textContent = 'Deleting…';
      try {
        await apiPost({ action:'queen_delete', id: queenId }, {});
        msg.textContent = 'Deleted.';
        setTimeout(() => location.hash = '#/queens', 200);
      } catch (err) {
        msg.textContent = `Error: ${err.message}`;
      }
    });
  }

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    msg.textContent = 'Saving…';

    const data = normalizeFormData(Object.fromEntries(new FormData(form).entries()));

    try {
      if (mode === 'create') {
        await apiPost({ action:'queen_create' }, data);
        msg.textContent = 'Created.';
        setTimeout(() => location.hash = '#/queens', 350);
      } else {
        await apiPost({ action:'queen_update', id: queenId }, data);
        msg.textContent = 'Saved.';
        setTimeout(() => location.hash = '#/queens', 350);
      }
    } catch (err) {
      msg.textContent = `Error: ${err.message}`;
    }
  });
}

async function renderStandortDetail(standort) {
  setActiveTab('/standort');
  setTopbarBack(() => {
    location.hash = '#/';
  });
  const locationTitle = `Hives at Location ${standort}`;
  app.innerHTML = card(locationTitle, '', `<div class="skeleton"></div>`, 'title');
  const data = await apiGet({ action:'hives_by_standort', standort });
  const hasVal = v => v !== null && v !== undefined && String(v).trim().length > 0;
  const joinParts = (parts, sep = ' ') => parts.filter(hasVal).map(v => htmlesc(v)).join(sep);

  const rows = data.hives.map(h => `
    <tr class="location-hive-primary" role="button" tabindex="0"
      onclick="location.hash='#/hive/${h.Hive_ID}'"
      onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();location.hash='#/hive/${h.Hive_ID}'}">
      <td>${joinParts([
        h.Hive_nr || h.Hive_ID,
        h.last_visit_date ? fmtDate(h.last_visit_date) : '',
        'Q:',
        h.queen_birth_year,
        h.queen_marked,
        h.queen_breed
      ])}</td>
    </tr>
    <tr class="location-hive-secondary" role="button" tabindex="0"
      onclick="location.hash='#/hive/${h.Hive_ID}'"
      onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();location.hash='#/hive/${h.Hive_ID}'}">
      <td>
        <div class="location-hive-secondary-line">
          <span>${joinParts([h.Volksstaerke, h.Aufbau, h.Schwarmneigung])}</span>
          <span class="location-hive-todo">${htmlesc(h.ToDo || '')}</span>
        </div>
      </td>
    </tr>
    <tr class="location-hive-remarks" role="button" tabindex="0"
      onclick="location.hash='#/hive/${h.Hive_ID}'"
      onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();location.hash='#/hive/${h.Hive_ID}'}">
      <td>${htmlesc(h.Bemerkungen || '')}</td>
    </tr>
  `).join('');

  app.innerHTML = `
    ${card(locationTitle, '', `
      <table class="table location-hives-table">
        <tbody>${rows || `<tr><td class="muted">No hives found</td></tr>`}</tbody>
      </table>
    `, 'title')}
  `;
}

async function renderHive(hiveId) {
  setActiveTab('/hive');
  setTopbarBack(() => history.back());
  app.innerHTML = card('Hive', `#${hiveId}`, `<div class="skeleton"></div>`);
  const data = await apiGet({ action:'visits_by_hive', hive_id: hiveId });
  const canEdit = canWrite();
  const hasVal = v => v !== null && v !== undefined && String(v).length > 0;
  const joinParts = (parts, sep) => parts.filter(hasVal).map(v => htmlesc(v)).join(sep);
  if (canEdit) {
    setTopbarActions([
      { label: 'Edit Hive', onClick: () => { location.hash = `#/hive/${hiveId}/edit`; } },
      { label: 'Add Visit', primary: true, onClick: () => { location.hash = `#/hive/${hiveId}/new-visit`; } },
    ]);
  }
  const editButtons = canEdit ? '' : (authState.user ? `
      <div class="hstack" style="gap:8px">
        <button class="btn" disabled>Read-only</button>
      </div>
    ` : `
      <div class="hstack" style="gap:8px">
        <button class="btn" onclick="location.hash='#/login?next=${encodeURIComponent(`#/hive/${hiveId}`)}'">Sign in to edit</button>
      </div>
    `);

  const hiveTitle = data.hive?.Hive_nr ? `Hive Nr. ${data.hive.Hive_nr}` : `Hive ID: #${hiveId}`;
  const latestVisit = data.visits && data.visits.length ? data.visits[0] : null;
  const joinRawParts = (parts, sep = ' ') => parts.filter(hasVal).map(v => String(v)).join(sep);
  const queenSummary = latestVisit
    ? joinRawParts([latestVisit.queen_breed, latestVisit.queen_marked, latestVisit.queen_birth_year], ' ')
    : '';
  const hiveSubtitle = [
    `Queen: ${queenSummary || '—'}`,
    `Züchter: ${latestVisit?.queen_breeder || '—'}`,
    `Belegstelle: ${latestVisit?.queen_belegstelle || '—'}`
  ].join('\n');

  const rows = data.visits.map(v => {
    const clickAttr = `onclick="location.hash='#/visit/${v.ID}'"`;
    const keyboardNavAttr = `${clickAttr} role="button" tabindex="0" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();location.hash='#/visit/${v.ID}'}"`;
    const brood = [v.Brut_Stifte, v.Brut_offen, v.Brut_verdeckelt].join('/');
    const queenParts = [v.Queen_ID, v.Koenigin_status].filter(hasVal).map(String).join(' ');
    const queen = `Q:${htmlesc(queenParts)}`;
    const temperament = [v.Sanftmut, v.Wabensitz, v.Schwarmneigung].join('/');
    const honeyFeed = ['H:', v.Honig, ' F: ',v.Futter].join('');
    const strength = htmlesc(v.Volksstaerke || '');
    const locationSetup = joinParts([v.Standort, v.Aufbau], ': ');
    return `
      <tr class="hive-visit-row hive-visit-row-1" ${keyboardNavAttr}>
        <td class="hive-visit-left"><strong>${htmlesc(v.Datum ? fmtDate(v.Datum) : '')}</strong></td>
        <td class="hive-visit-left" colspan="3">${locationSetup}</td>
        <td class="hive-visit-right">${strength}</td>
      </tr>
      <tr class="hive-visit-row hive-visit-row-2" ${clickAttr}>
        <td class="hive-visit-left">Brut:${brood}</td>
        <td class="hive-visit-left">${queen}</td>
        <td class="hive-visit-left">${temperament}</td>
        <td class="hive-visit-left">${honeyFeed}</td>
      </tr>
      <tr class="hive-visit-row hive-visit-row-3" ${clickAttr}>
        <td class="hive-visit-left" colspan="4">${htmlesc(v.Bemerkungen || '')}</td>
        <td class="hive-visit-right hive-visit-todo">${htmlesc(v.ToDo || '')}</td>
      </tr>
      <tr class="hive-visit-row hive-visit-row-4" ${clickAttr}>
        <td colspan="5">&nbsp;</td>
      </tr>
    `;
  }).join('');

  app.innerHTML = card(hiveTitle, hiveSubtitle, `
    <div class="hstack">
      ${editButtons}
    </div>
    <table class="table hive-visits-table">
      <tbody>${rows || `<tr><td colspan="5" class="muted">No visits yet</td></tr>`}</tbody>
    </table>
  `);
}

async function renderHiveEdit(hiveId) {
  setActiveTab('/hive');
  if (!authState.user) {
    setTopbarBack(() => history.back());
    app.innerHTML = authGateHtml({ title: 'Hive', subtitle: `#${hiveId}` });
    return;
  }
  setTopbarBack(() => history.back());
  const writable = canWrite();
  app.innerHTML = card('Hive', `#${hiveId}`, `<div class="skeleton"></div>`);
  try {
    const data = await apiGet({ action:'hive', id: hiveId });
    const h = data.hive;

    app.innerHTML = card('Hive', `Edit #${hiveId}`, `
      ${!writable ? `<div class="notice">Read-only access.</div><div style="height:12px"></div>` : ''}
      ${hiveFormHtml({ h, mode:'update', readOnly: !writable })}
    `);

    if (writable) wireHiveForm({ hiveId, mode:'update' });
  } catch (err) {
    app.innerHTML = card('Hive', `#${hiveId}`, `
      <div class="notice">Error loading hive: ${htmlesc(err.message)}</div>
    `);
  }
}

async function renderHiveCreate() {
  setActiveTab('/hive');
  if (!authState.user || !canWrite()) {
    setTopbarBack(() => history.back());
    app.innerHTML = card('Hive', 'New', `
      <div class="notice">Write access required to create hives.</div>
    `);
    return;
  }
  setTopbarBack(() => {
    location.hash = '#/';
  });
  app.innerHTML = card('Hive', 'New', `<div class="skeleton"></div>`);

  const h = { Hive_nr: null, inactive: 0 };
  app.innerHTML = card('Hive', 'Create new hive', `
    ${hiveFormHtml({ h, mode:'create', readOnly: false })}
  `);

  wireHiveForm({ mode:'create' });
}

function hiveFormHtml({ h, mode='update', readOnly=false }) {
  const isCreate = mode === 'create';
  const submitLabel = isCreate ? 'Create hive' : 'Save changes';
  const cancelAction = isCreate ? "location.hash='#/'" : 'history.back()';
  const submitBtn = readOnly ? '' : `<button type="submit" class="btn primary">${submitLabel}</button>`;

  return `
  <form id="hive-form" class="vstack">
    <fieldset class="form single" ${readOnly ? 'disabled' : ''}>
      <div class="field">
        <label>Hive no.</label>
        <input name="Hive_nr" value="${htmlesc(h.Hive_nr || '')}" placeholder="e.g., 12"/>
      </div>

      <div class="field">
        <label>Inactive</label>
        <label class="pill" style="justify-content:flex-start; gap:8px; cursor:pointer;">
          <input type="checkbox" name="inactive" value="1" ${String(h.inactive) === '1' ? 'checked' : ''}/>
          Mark hive as inactive
        </label>
      </div>
    </fieldset>

    <div class="hstack" style="justify-content:flex-end; gap:8px">
      <button type="button" class="btn" onclick="${cancelAction}">Cancel</button>
      ${submitBtn}
    </div>

    <div id="hive-form-msg" class="muted" aria-live="polite"></div>
  </form>`;
}

function wireHiveForm({ hiveId, mode='update' }) {
  const form = document.getElementById('hive-form');
  const msg = document.getElementById('hive-form-msg');
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    msg.textContent = 'Saving…';

    const data = Object.fromEntries(new FormData(form).entries());
    data.inactive = data.inactive ? 1 : 0;
    if (data.Hive_nr === '') data.Hive_nr = null;

    try {
      if (mode === 'create') {
        await apiPost({ action:'hive_create' }, data);
        msg.textContent = 'Created.';
        setTimeout(() => location.hash = '#/', 350);
      } else {
        await apiPost({ action:'hive_update', id: hiveId }, data);
        msg.textContent = 'Saved.';
        setTimeout(() => location.hash = `#/hive/${hiveId}`, 350);
      }
    } catch (err) {
      msg.textContent = `Error: ${err.message}`;
    }
  });
}

async function renderNewVisit(hiveId) {
  setActiveTab('/hive');
  if (!authState.user || !canWrite()) {
    setTopbarBack(() => history.back());
    app.innerHTML = card('New visit', `Hive #${hiveId}`, `
      <div class="notice">Write access required to add visits.</div>
    `);
    return;
  }
  setTopbarBack(() => history.back());
  app.innerHTML = card('New visit', `Hive #${hiveId}`, `<div class="skeleton"></div>`);
  const [defaultsRes, queensRes] = await Promise.all([
    apiGet({ action:'visit_defaults', hive_id: hiveId }),
    apiGet({ action:'queen_options' })
  ]);
  const d = defaultsRes.defaults;
  const today = new Date().toISOString().split('T')[0]; // YYYY-MM-DD format
  const prefill = {
    Datum: today,
    Standort: d?.Standort ?? '',
    Queen_ID: d?.Queen_ID ?? null,
    Aufbau: d?.Aufbau ?? '',
    Volksstaerke: '',
    Koenigin_status: '',
    Brut_Stifte: '',
    Brut_offen: '',
    Brut_verdeckelt: '',
    Sanftmut: '',
    Wabensitz: '',
    Schwarmneigung: '',
    Honig: '',
    Futter: '',
    Bemerkungen: '',
    ToDo: ''
  };
  const queens = queensRes.queens || [];

  app.innerHTML = card('New visit', `Hive #${hiveId} (prefilled: location, queen, setup)`, `
    <div class="notice">Tip: only location, queen ID, and setup are prefilled from the latest visit (if any).</div>
    <div style="height:12px"></div>
    ${visitFormHtml({ mode:'create', hiveId, visit: prefill, queens, readOnly: false })}
  `);

  wireVisitForm({ mode:'create', hiveId });
}

async function renderVisit(visitId) {
  setActiveTab('/visit');
  if (!authState.user) {
    setTopbarBack(() => history.back());
    app.innerHTML = authGateHtml({ title: 'Visit', subtitle: `#${visitId}` });
    return;
  }
  setTopbarBack(() => history.back());
  const writable = canWrite();
  app.innerHTML = card('Visit', `#${visitId}`, `<div class="skeleton"></div>`);
  const [visitRes, queensRes] = await Promise.all([
    apiGet({ action:'visit', id: visitId }),
    apiGet({ action:'queen_options' })
  ]);

  const v = visitRes.visit;
  const hiveId = v.Hive_ID;
  const queens = queensRes.queens || [];

  app.innerHTML = card('Visit', `Hive #${hiveId} · ${fmtDate(v.Datum)} · Visit #${visitId}`, `
    ${!writable ? `<div class="notice">Read-only access.</div><div style="height:12px"></div>` : ''}
    ${visitFormHtml({ mode:'update', hiveId, visitId, readOnly: !writable, visit: {
      ...v,
      Volksstaerke: v.Volksstaerke ?? v['Volksstärke'],
      Koenigin_status: v.Koenigin_status ?? v['Königin']
    }, queens })}
  `);

  if (writable) wireVisitForm({ mode:'update', visitId, hiveId });
}

function visitFormHtml({ mode, hiveId, visitId, visit, queens, readOnly=false }) {
  const isCreate = mode === 'create';
  const deleteBtn = isCreate || readOnly ? '' : `<button type="button" class="btn danger" id="visit-delete">Delete visit</button>`;
  const submitBtn = readOnly ? '' : `<button type="submit" class="btn primary">${mode === 'create' ? 'Create visit' : 'Save changes'}</button>`;
  const vs = String(visit.Volksstaerke ?? '');
  const vsNorm = vs === 'k.A.' ? '' : vs;
  const tm = String(visit.Sanftmut ?? '');
  const tmNorm = tm === 'k.A.' ? '' : tm;
  const ws = String(visit.Wabensitz ?? '');
  const wsNorm = ws === 'k.A.' ? '' : ws;
  const sw = String(visit.Schwarmneigung ?? '');
  const swNorm = sw === 'k.A.' ? '' : sw;
  const qOptions = [
    `<option value="">—</option>`,
    ...queens.map(q => {
      const label = `#${q.ID} · ${q.Geburtsjahr} · ${q.gezeichnet || 'unmarked'} · ${q.Rasse || '—'}${q.Lebensnummer ? ` · ${q.Lebensnummer}` : ''}`;
      const sel = String(q.ID) === String(visit.Queen_ID ?? '') ? 'selected' : '';
      return `<option value="${htmlesc(q.ID)}" ${sel}>${htmlesc(label)}</option>`;
    })
  ].join('');

  return `
  <form id="visit-form" class="vstack">
    <input type="hidden" name="Hive_ID" value="${htmlesc(hiveId)}"/>
    <fieldset class="form single" ${readOnly ? 'disabled' : ''}>
      <div class="field">
        <label>Date</label>
        <input name="Datum" type="date" value="${htmlesc(visit.Datum || '')}" required />
      </div>

      <div class="field">
        <label>Location</label>
        <input name="Standort" value="${htmlesc(visit.Standort || '')}" placeholder="e.g., Garten, Waldstand, …"/>
      </div>

      <div class="field">
        <label>Queen ID</label>
        <select name="Queen_ID">${qOptions}</select>
      </div>

      <div class="field">
        <label>Setup</label>
        <input name="Aufbau" value="${htmlesc(visit.Aufbau || '')}" placeholder="e.g., 2 BR + 1 HR"/>
      </div>

      <div class="field">
        <label>Colony strength</label>
        <div class="hstack" style="gap:8px; align-items:center; justify-content:flex-start;">
          <label class="pill" style="cursor:pointer;">
            <input type="radio" name="Volksstaerke" value="" ${vsNorm === '' ? 'checked' : ''}/> k.A.
          </label>
          <label class="pill" style="cursor:pointer;">
            <input type="radio" name="Volksstaerke" value="+" ${vsNorm === '+' ? 'checked' : ''}/> +
          </label>
          <label class="pill" style="cursor:pointer;">
            <input type="radio" name="Volksstaerke" value="++" ${vsNorm === '++' ? 'checked' : ''}/> ++
          </label>
          <label class="pill" style="cursor:pointer;">
            <input type="radio" name="Volksstaerke" value="+++" ${vsNorm === '+++' ? 'checked' : ''}/> +++
          </label>
        </div>
      </div>

      <div class="field">
        <label>Queen status (e.g., da, nicht gesehen, weisellos)</label>
        <input name="Koenigin_status" value="${htmlesc(visit.Koenigin_status || '')}" placeholder="da / …"/>
      </div>

      <div class="field">
        <label>Brood eggs</label>
        <label class="pill" style="justify-content:flex-start; gap:8px; cursor:pointer;">
          <input type="hidden" name="Brut_Stifte" value=""/>
          <input type="checkbox" name="Brut_Stifte" value="+" ${visit.Brut_Stifte === '+' ? 'checked' : ''}/>
          +
        </label>
      </div>

      <div class="field">
        <label>Brood open</label>
        <label class="pill" style="justify-content:flex-start; gap:8px; cursor:pointer;">
          <input type="hidden" name="Brut_offen" value=""/>
          <input type="checkbox" name="Brut_offen" value="+" ${visit.Brut_offen === '+' ? 'checked' : ''}/>
          +
        </label>
      </div>

      <div class="field">
        <label>Brood closed</label>
        <label class="pill" style="justify-content:flex-start; gap:8px; cursor:pointer;">
          <input type="hidden" name="Brut_verdeckelt" value=""/>
          <input type="checkbox" name="Brut_verdeckelt" value="+" ${visit.Brut_verdeckelt === '+' ? 'checked' : ''}/>
          +
        </label>
      </div>

      <div class="field">
        <label>Temperament</label>
        <div class="hstack" style="gap:8px; align-items:center; justify-content:flex-start;">
          <label class="pill" style="cursor:pointer;">
            <input type="radio" name="Sanftmut" value="" ${tmNorm === '' ? 'checked' : ''}/> k.A.
          </label>
          <label class="pill" style="cursor:pointer;">
            <input type="radio" name="Sanftmut" value="+" ${tmNorm === '+' ? 'checked' : ''}/> +
          </label>
          <label class="pill" style="cursor:pointer;">
            <input type="radio" name="Sanftmut" value="-" ${tmNorm === '-' ? 'checked' : ''}/> -
          </label>
        </div>
      </div>

      <div class="field">
        <label>Comb seat</label>
        <div class="hstack" style="gap:8px; align-items:center; justify-content:flex-start;">
          <label class="pill" style="cursor:pointer;">
            <input type="radio" name="Wabensitz" value="" ${wsNorm === '' ? 'checked' : ''}/> k.A.
          </label>
          <label class="pill" style="cursor:pointer;">
            <input type="radio" name="Wabensitz" value="+" ${wsNorm === '+' ? 'checked' : ''}/> +
          </label>
          <label class="pill" style="cursor:pointer;">
            <input type="radio" name="Wabensitz" value="-" ${wsNorm === '-' ? 'checked' : ''}/> -
          </label>
        </div>
      </div>

      <div class="field">
        <label>Swarm tendency</label>
        <div class="hstack" style="gap:8px; align-items:center; justify-content:flex-start; flex-wrap:wrap;">
          <label class="pill" style="cursor:pointer;">
            <input type="radio" name="Schwarmneigung" value="" ${swNorm === '' ? 'checked' : ''}/> k.A.
          </label>
          <label class="pill" style="cursor:pointer;">
            <input type="radio" name="Schwarmneigung" value="WZ b" ${swNorm === 'WZ b' ? 'checked' : ''}/> WZ b
          </label>
          <label class="pill" style="cursor:pointer;">
            <input type="radio" name="Schwarmneigung" value="WZ o" ${swNorm === 'WZ o' ? 'checked' : ''}/> WZ o
          </label>
          <label class="pill" style="cursor:pointer;">
            <input type="radio" name="Schwarmneigung" value="WZ g" ${swNorm === 'WZ g' ? 'checked' : ''}/> WZ g
          </label>
          <label class="pill" style="cursor:pointer;">
            <input type="radio" name="Schwarmneigung" value="Schw" ${swNorm === 'Schw' ? 'checked' : ''}/> Schw
          </label>
        </div>
      </div>

      <div class="field">
        <label>Honey</label>
        <input name="Honig" value="${htmlesc(visit.Honig || '')}"/>
      </div>

      <div class="field">
        <label>Feed</label>
        <input name="Futter" value="${htmlesc(visit.Futter || '')}"/>
      </div>

      <div class="field full">
        <label>Notes</label>
        <textarea name="Bemerkungen">${htmlesc(visit.Bemerkungen || '')}</textarea>
      </div>

      <div class="field full">
        <label>To‑do</label>
        <textarea name="ToDo">${htmlesc(visit.ToDo || '')}</textarea>
      </div>
    </fieldset>

    <div class="hstack" style="justify-content:space-between; gap:8px">
      ${deleteBtn}
      <div class="hstack" style="gap:8px">
        <button type="button" class="btn" onclick="history.back()">Cancel</button>
        ${submitBtn}
      </div>
    </div>

    <div id="form-msg" class="muted" aria-live="polite"></div>
  </form>`;
}

function wireVisitForm({ mode, hiveId, visitId }) {
  const form = document.getElementById('visit-form');
  const msg = document.getElementById('form-msg');
  const delBtn = document.getElementById('visit-delete');
  const returnToHiveWithHistory = () => {
    const currentHash = location.hash || '#/';
    const fallbackHash = `#/hive/${hiveId}`;
    if (window.history.length > 1) {
      history.back();
      // Fallback for direct-entry routes where back cannot return inside the app.
      setTimeout(() => {
        if ((location.hash || '#/') === currentHash) {
          location.hash = fallbackHash;
        }
      }, 250);
      return;
    }
    location.hash = fallbackHash;
  };

  if (delBtn) {
    delBtn.addEventListener('click', async () => {
      if (!confirm('Delete this visit? This cannot be undone.')) return;
      msg.textContent = 'Deleting…';
      try {
        await apiPost({ action:'visit_delete', id: visitId }, {});
        msg.textContent = 'Deleted.';
        setTimeout(returnToHiveWithHistory, 200);
      } catch (err) {
        msg.textContent = `Error: ${err.message}`;
      }
    });
  }

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    msg.textContent = 'Saving…';

    const data = normalizeFormData(Object.fromEntries(new FormData(form).entries()));

    try {
      if (mode === 'create') {
        await apiPost({ action:'visit_create' }, data);
        msg.textContent = 'Created.';
        setTimeout(returnToHiveWithHistory, 350);
      } else {
        await apiPost({ action:'visit_update', id: visitId }, data);
        msg.textContent = 'Saved.';
        setTimeout(returnToHiveWithHistory, 350);
      }
    } catch (err) {
      msg.textContent = `Error: ${err.message}`;
    }
  });
}

function renderLogin(r) {
  setActiveTab('/login');
  setTopbarBack(() => history.back());
  const nextParam = r?.query?.get('next');
  const decodedNext = nextParam ? decodeURIComponent(nextParam) : '#/';
  const nextHash = decodedNext.startsWith('#') ? decodedNext : '#/';

  app.innerHTML = card('Sign in', 'Use your Apiary account', `
    <form id="login-form" class="vstack">
      <div class="form">
        <div class="field">
          <label>Username</label>
          <input name="username" autocomplete="username" required />
        </div>
        <div class="field">
          <label>Password</label>
          <input type="password" name="password" autocomplete="current-password" required />
        </div>
      </div>
      <div class="hstack" style="justify-content:flex-end; gap:8px">
        <button type="button" class="btn" onclick="history.back()">Cancel</button>
        <button type="submit" class="btn primary">Sign in</button>
      </div>
      <div id="login-msg" class="muted" aria-live="polite"></div>
    </form>
    <div id="login-bootstrap" class="notice" style="display:none">
      <div class="vstack" style="gap:8px">
        <div>No admin user exists yet. Create a default admin account (admin / admin).</div>
        <div class="hstack" style="justify-content:flex-end; gap:8px">
          <button type="button" id="login-bootstrap-btn" class="btn primary">Create admin user</button>
        </div>
        <div id="login-bootstrap-msg" class="muted" aria-live="polite"></div>
      </div>
    </div>
  `);

  const form = document.getElementById('login-form');
  const msg = document.getElementById('login-msg');
  const bootstrapBox = document.getElementById('login-bootstrap');
  const bootstrapBtn = document.getElementById('login-bootstrap-btn');
  const bootstrapMsg = document.getElementById('login-bootstrap-msg');

  async function ensureAnonymousCsrf() {
    if (authState.csrf) return;
    try {
      const res = await apiGet({ action:'me' }, { suppressAuthRedirect: true });
      if (res && res.csrf) authState.csrf = res.csrf;
    } catch (_) {
      // ignore; bootstrap will fail with a clear error if CSRF is unavailable
    }
  }

  async function checkAdminBootstrap() {
    if (!bootstrapBox) return;
    try {
      const status = await apiGet({ action:'admin_bootstrap_status' }, { suppressAuthRedirect: true });
      if (status && status.exists) {
        bootstrapBox.style.display = 'none';
        return;
      }
      bootstrapBox.style.display = '';
      await ensureAnonymousCsrf();
    } catch (_) {
      bootstrapBox.style.display = 'none';
    }
  }

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    msg.textContent = 'Signing in...';
    const data = Object.fromEntries(new FormData(form).entries());
    try {
      const res = await apiPost({ action:'login' }, data, { suppressAuthRedirect: true });
      setAuth(res.user || null, res.csrf || null);
      msg.textContent = 'Signed in.';
      location.hash = nextHash;
    } catch (err) {
      msg.textContent = `Error: ${err.message}`;
    }
  });

  if (bootstrapBtn) {
    bootstrapBtn.addEventListener('click', async () => {
      if (!confirm('Create default admin account with username "admin" and password "admin"?')) {
        return;
      }
      bootstrapBtn.disabled = true;
      if (bootstrapMsg) bootstrapMsg.textContent = 'Creating admin user...';
      try {
        await ensureAnonymousCsrf();
        await apiPost({ action:'admin_bootstrap_create' }, { confirm: true }, { suppressAuthRedirect: true });
        if (bootstrapMsg) bootstrapMsg.textContent = 'Admin user created. You can sign in with admin / admin.';
        bootstrapBtn.style.display = 'none';
        const usernameInput = form.querySelector('input[name="username"]');
        const passwordInput = form.querySelector('input[name="password"]');
        if (usernameInput) usernameInput.value = 'admin';
        if (passwordInput) passwordInput.value = 'admin';
      } catch (err) {
        if (bootstrapMsg) bootstrapMsg.textContent = `Error: ${err.message}`;
      } finally {
        bootstrapBtn.disabled = false;
      }
    });
  }

  checkAdminBootstrap();
}

function renderAccount() {
  setActiveTab('/account');
  setTopbarBack(() => history.back());
  app.innerHTML = card('Account', 'Change password', `
    <form id="password-form" class="vstack">
      <div class="form">
        <div class="field">
          <label>Current password</label>
          <input type="password" name="current_password" autocomplete="current-password" required />
        </div>
        <div class="field">
          <label>New password</label>
          <input type="password" name="new_password" autocomplete="new-password" minlength="7" required />
        </div>
        <div class="field">
          <label>Confirm new password</label>
          <input type="password" name="confirm_password" autocomplete="new-password" minlength="7" required />
        </div>
      </div>
      <div class="hstack" style="justify-content:flex-end; gap:8px">
        <button type="button" class="btn" onclick="history.back()">Cancel</button>
        <button type="submit" class="btn primary">Update password</button>
      </div>
      <div id="password-msg" class="muted" aria-live="polite"></div>
    </form>
  `);

  const form = document.getElementById('password-form');
  const msg = document.getElementById('password-msg');

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    msg.textContent = 'Updating...';

    const data = Object.fromEntries(new FormData(form).entries());
    if (data.new_password !== data.confirm_password) {
      msg.textContent = 'Error: new passwords do not match.';
      return;
    }

    try {
      await apiPost(
        { action:'change_password' },
        { current_password: data.current_password, new_password: data.new_password }
      );
      msg.textContent = 'Password updated.';
      form.reset();
    } catch (err) {
      msg.textContent = `Error: ${err.message}`;
    }
  });
}

function renderAdminGate() {
  setTopbarBack(() => history.back());
  app.innerHTML = card('User Administration', 'Admin only', `
    <div class="notice">Admin access required.</div>
  `);
}

async function renderUserAdmin() {
  if (!isAdmin()) return renderAdminGate();
  setTopbarBack(() => history.back());
  app.innerHTML = card('User Administration', 'Manage users', `<div class="skeleton"></div>`);
  const data = await apiGet({ action:'users_list' });
  const users = data.users || [];

  const rows = users.map(u => {
    const isSelf = authState.user && String(authState.user.id) === String(u.id);
    const roleSelect = `
      <select class="user-role" data-id="${htmlesc(u.id)}" data-prev="${htmlesc(u.role)}" ${isSelf ? 'disabled' : ''}>
        <option value="admin" ${u.role === 'admin' ? 'selected' : ''}>admin</option>
        <option value="contributor" ${u.role === 'contributor' ? 'selected' : ''}>contributor</option>
        <option value="readonly" ${u.role === 'readonly' ? 'selected' : ''}>readonly</option>
      </select>
    `;
    const resetBtn = isSelf
      ? `<button class="btn" disabled>Reset Password</button>`
      : `<button class="btn user-reset" data-id="${htmlesc(u.id)}" data-name="${htmlesc(u.username)}">Reset Password</button>`;
    const delBtn = isSelf
      ? `<button class="btn" disabled>Current user</button>`
      : `<button class="btn danger user-delete" data-id="${htmlesc(u.id)}" data-name="${htmlesc(u.username)}">Delete</button>`;
    return `
      <tr>
        <td>${htmlesc(u.id)}</td>
        <td>${htmlesc(u.username)}</td>
        <td>${roleSelect}</td>
        <td>${htmlesc(u.created_at || '—')}</td>
        <td>${htmlesc(u.last_login || '—')}</td>
        <td class="hstack" style="gap:8px; justify-content:flex-end;">
          ${resetBtn}
          ${delBtn}
        </td>
      </tr>
    `;
  }).join('');

  app.innerHTML = card('User Administration', 'Manage users', `
    <div class="hstack">
      <div class="muted">Admins can add or remove users.</div>
      <div class="hstack" style="gap:8px">
        <button class="btn primary" onclick="location.hash='#/admin/users/new'">Add User</button>
      </div>
    </div>
    <div style="height:12px"></div>
    <table class="table">
      <thead><tr>
        <th>ID</th>
        <th>Username</th>
        <th>Role</th>
        <th>Created</th>
        <th>Last login</th>
        <th>Actions</th>
      </tr></thead>
      <tbody>${rows || `<tr><td colspan="6" class="muted">No users found</td></tr>`}</tbody>
    </table>
    <div id="users-msg" class="muted" aria-live="polite"></div>
  `);

  const msg = document.getElementById('users-msg');
  document.querySelectorAll('.user-reset').forEach(btn => {
    btn.addEventListener('click', async () => {
      const id = btn.getAttribute('data-id');
      const name = btn.getAttribute('data-name') || 'this user';
      if (!confirm(`Reset password for ${name} to 12345678?`)) return;
      msg.textContent = 'Resetting password...';
      try {
        await apiPost({ action:'user_reset_password' }, { id });
        msg.textContent = 'Password reset.';
      } catch (err) {
        msg.textContent = `Error: ${err.message}`;
      }
    });
  });
  document.querySelectorAll('.user-role').forEach(select => {
    select.addEventListener('change', async () => {
      const id = select.getAttribute('data-id');
      const prev = select.getAttribute('data-prev') || '';
      const role = select.value;
      msg.textContent = 'Updating role...';
      try {
        await apiPost({ action:'user_update_role' }, { id, role });
        select.setAttribute('data-prev', role);
        msg.textContent = 'Role updated.';
        if (authState.user && String(authState.user.id) === String(id)) {
          authState.user.role = role;
          updateAuthUi();
          if (role !== 'admin') {
            location.hash = '#/';
          }
        }
      } catch (err) {
        msg.textContent = `Error: ${err.message}`;
        select.value = prev;
      }
    });
  });
  document.querySelectorAll('.user-delete').forEach(btn => {
    btn.addEventListener('click', async () => {
      const id = btn.getAttribute('data-id');
      const name = btn.getAttribute('data-name') || 'this user';
      if (!confirm(`Delete ${name}? This cannot be undone.`)) return;
      msg.textContent = 'Deleting...';
      try {
        await apiPost({ action:'user_delete' }, { id });
        msg.textContent = 'Deleted.';
        setTimeout(() => renderUserAdmin(), 200);
      } catch (err) {
        msg.textContent = `Error: ${err.message}`;
      }
    });
  });
}

function renderUserCreate() {
  if (!isAdmin()) return renderAdminGate();
  setTopbarBack(() => history.back());
  app.innerHTML = card('Add User', 'Create a new account', `
    <form id="user-create-form" class="vstack">
      <div class="form">
        <div class="field">
          <label>Username</label>
          <input name="username" autocomplete="username" required />
        </div>
        <div class="field">
          <label>Role</label>
          <select name="role" required>
            <option value="admin">admin</option>
            <option value="contributor" selected>contributor</option>
            <option value="readonly">readonly</option>
          </select>
        </div>
        <div class="field">
          <label>Password</label>
          <input type="password" name="password" autocomplete="new-password" minlength="7" required />
        </div>
        <div class="field">
          <label>Confirm password</label>
          <input type="password" name="confirm_password" autocomplete="new-password" minlength="7" required />
        </div>
      </div>
      <div class="hstack" style="justify-content:flex-end; gap:8px">
        <button type="button" class="btn" onclick="history.back()">Cancel</button>
        <button type="submit" class="btn primary">Create user</button>
      </div>
      <div id="user-create-msg" class="muted" aria-live="polite"></div>
    </form>
  `);

  const form = document.getElementById('user-create-form');
  const msg = document.getElementById('user-create-msg');
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    msg.textContent = 'Creating...';
    const data = Object.fromEntries(new FormData(form).entries());
    if (data.password !== data.confirm_password) {
      msg.textContent = 'Error: passwords do not match.';
      return;
    }
    try {
      await apiPost(
        { action:'user_create' },
        { username: data.username, role: data.role, password: data.password }
      );
      msg.textContent = 'User created.';
      setTimeout(() => location.hash = '#/admin/users', 300);
    } catch (err) {
      msg.textContent = `Error: ${err.message}`;
    }
  });
}

async function router() {
  if (authReady) await authReady;
  const r = parseRoute();
  const parts = r.parts; // e.g., ['standort','Foo'] etc.
  const path = '/' + (parts[0] || '');
  setTopbarBack(null);
  setTopbarActions([]);

  if (!authState.user && path !== '/login') {
    const next = encodeURIComponent(location.hash || '#/');
    location.hash = `#/login?next=${next}`;
    return;
  }

  if (path === '/' || path === '//') return renderStandorte();
  if (path === '/queens') return renderQueens();
  if (path === '/login') return renderLogin(r);
  if (path === '/account') return renderAccount();
  if (path === '/admin') {
    if (parts[1] === 'users' && parts[2] === 'new') return renderUserCreate();
    return renderUserAdmin();
  }

  if (path === '/standort') {
    const standort = decodeURIComponent(parts[1] || '');
    return renderStandortDetail(standort);
  }

  if (path === '/queen') {
    const queenId = parts[1];
    if (!queenId || queenId === 'new') return renderQueenCreate();
    return renderQueenEdit(queenId);
  }

  if (path === '/hive') {
    const hiveId = parts[1];
    if (hiveId === 'new') return renderHiveCreate();
    if (parts[2] === 'new-visit') return renderNewVisit(hiveId);
    if (parts[2] === 'edit') return renderHiveEdit(hiveId);
    return renderHive(hiveId);
  }

  if (path === '/visit') {
    const visitId = parts[1];
    return renderVisit(visitId);
  }

  // default
  return renderStandorte();
}

// Global event delegation for navigation and actions
document.addEventListener('click', (e) => {
  const target = e.target.closest('[data-navigate]');
  if (target) {
    e.preventDefault();
    location.hash = target.dataset.navigate;
  }
});

authReady = initAuth();
window.addEventListener('hashchange', router);
window.addEventListener('load', router);
