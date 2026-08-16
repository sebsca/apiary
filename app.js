// app.js - vanilla SPA
import { createApiClient } from './api-client.js';
import { wireCrudForm } from './form-controller.js';
import { renderSankeyChart } from './sankey-chart.js';
import { card, fmtDate, htmlesc, joinEscaped, joinValues, parseRoute } from './ui-utils.js';

// ============================================================================
// DOM REFERENCES
// ============================================================================

const app = document.getElementById('app');
const tabStandorte = document.getElementById('tab-standorte');
const tabHives = document.getElementById('tab-hives');
const tabMovements = document.getElementById('tab-movements');
const tabQueens = document.getElementById('tab-queens');
const authStatus = document.getElementById('auth-status');
const authAdmin = document.getElementById('auth-admin');
const authAccount = document.getElementById('auth-account');
const authAction = document.getElementById('auth-action');
const topbarToolbar = document.getElementById('topbar-toolbar');
const topbarActions = document.getElementById('topbar-actions');
const topbarBack = document.getElementById('topbar-back');
const topbarLocation = document.getElementById('topbar-location');
const menuToggle = document.getElementById('menu-toggle');
const menuPanel = document.getElementById('topbar-menu');
const skipLink = document.querySelector('.skip-link');

const authState = { user: null, checked: false, csrf: null };
let authReady = null;
let routeRenderToken = 0;
let routeAbortController = null;
const baseDocumentTitle = 'Apiary Logbook';
const apiClient = createApiClient({
  getCsrfToken: () => authState.csrf,
  onUnauthorized: (shouldRedirect) => {
    setAuth(null);
    if (shouldRedirect) {
      redirectToLogin();
    }
  }
});
const apiGet = (params, options = {}) => apiClient.get(params, {
  ...options,
  signal: options.signal ?? routeAbortController?.signal
});
const apiPost = apiClient.post;

function loadingStateHtml(label = 'Loading…') {
  return `
    <div class="loading-state" role="status" aria-live="polite" aria-label="${htmlesc(label)}">
      <div class="skeleton" aria-hidden="true"></div>
    </div>
  `;
}

function noticeHtml(message, state = 'info') {
  const role = state === 'error' ? 'alert' : 'status';
  const live = state === 'error' ? 'assertive' : 'polite';
  return `<div class="notice" data-state="${htmlesc(state)}" role="${role}" aria-live="${live}">${htmlesc(message)}</div>`;
}

function emptyStateHtml(message) {
  return `<div class="empty-state" role="status">${htmlesc(message)}</div>`;
}

function tableEmptyRow(message, columns) {
  return `<tr class="table-empty-row"><td colspan="${columns}">${emptyStateHtml(message)}</td></tr>`;
}

function tableScrollHtml(label, tableHtml) {
  return `
    <div class="table-scroll" role="region" aria-label="${htmlesc(label)}" tabindex="0">
      ${tableHtml}
    </div>
  `;
}

function formStatusHtml(id) {
  return `<div id="${htmlesc(id)}" class="form-status" data-state="idle" role="status" aria-live="polite" aria-atomic="true" aria-busy="false"></div>`;
}

function setFormStatus(element, message, state = 'info') {
  if (!element) return;
  element.dataset.state = message ? state : 'idle';
  element.setAttribute('role', state === 'error' ? 'alert' : 'status');
  element.setAttribute('aria-live', state === 'error' ? 'assertive' : 'polite');
  element.setAttribute('aria-busy', state === 'busy' ? 'true' : 'false');
  element.textContent = message;
}

function setFormBusy(form, busy) {
  form.setAttribute('aria-busy', busy ? 'true' : 'false');
  form.querySelectorAll('button').forEach((button) => {
    button.disabled = busy;
  });
}

function finalizeRouteView(token) {
  if (token !== routeRenderToken) return;
  const heading = app.querySelector('.title');
  const title = heading?.textContent?.trim() || baseDocumentTitle;
  document.title = title === baseDocumentTitle ? baseDocumentTitle : `${title} · ${baseDocumentTitle}`;
  app.setAttribute('aria-busy', 'false');
  if (!heading) return;
  if (!/^H[1-6]$/.test(heading.tagName)) {
    heading.setAttribute('role', 'heading');
    heading.setAttribute('aria-level', '1');
  }
  heading.setAttribute('tabindex', '-1');
  requestAnimationFrame(() => {
    if (token === routeRenderToken && document.contains(heading)) {
      heading.focus({ preventScroll: true });
    }
  });
}

function setMenuOpen(open) {
  document.body.classList.toggle('menu-open', open);
  if (menuToggle) {
    menuToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    menuToggle.setAttribute('aria-label', open ? 'Close account menu' : 'Open account menu');
  }
  if (menuPanel) {
    menuPanel.setAttribute('aria-hidden', open ? 'false' : 'true');
  }
}

function syncTopbarToolbar() {
  if (!topbarToolbar) return;
  topbarToolbar.hidden = topbarBack.hidden && topbarLocation.hidden && topbarActions.hidden;
}

function setTopbarBack(onClick = null, label = 'Back') {
  if (!topbarBack) return;
  topbarBack.textContent = label;
  if (!onClick) {
    topbarBack.hidden = true;
    topbarBack.onclick = null;
    syncTopbarToolbar();
    return;
  }
  topbarBack.hidden = false;
  topbarBack.onclick = (event) => {
    event.preventDefault();
    onClick();
  };
  syncTopbarToolbar();
}

function setTopbarLocation(onClick = null, label = '') {
  if (!topbarLocation) return;
  topbarLocation.textContent = label;
  if (!onClick) {
    topbarLocation.hidden = true;
    topbarLocation.onclick = null;
    syncTopbarToolbar();
    return;
  }
  topbarLocation.hidden = false;
  topbarLocation.onclick = (event) => {
    event.preventDefault();
    onClick();
  };
  syncTopbarToolbar();
}

function setTopbarActions(actions = []) {
  if (!topbarActions) return;
  topbarActions.innerHTML = '';
  if (!actions || actions.length === 0) {
    topbarActions.hidden = true;
    syncTopbarToolbar();
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
  syncTopbarToolbar();
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

if (skipLink) {
  skipLink.addEventListener('click', (event) => {
    event.preventDefault();
    app.focus({ preventScroll: true });
    app.scrollIntoView({ block: 'start' });
  });
}

document.addEventListener('click', (event) => {
  if (!document.body.classList.contains('menu-open')) return;
  const target = event.target;
  if (!(target instanceof Node)) return;
  if (menuPanel?.contains(target) || menuToggle?.contains(target)) return;
  setMenuOpen(false);
});

document.addEventListener('keydown', (event) => {
  if (event.key !== 'Escape' || !document.body.classList.contains('menu-open')) return;
  setMenuOpen(false);
  menuToggle?.focus();
});

window.addEventListener('hashchange', () => {
  setMenuOpen(false);
});

window.addEventListener('resize', () => {
  if (window.innerWidth >= 768) {
    setMenuOpen(false);
  }
});

setMenuOpen(false);

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
        authAdmin.hidden = false;
        authAdmin.textContent = 'User Administration';
        authAdmin.onclick = () => {
          location.hash = '#/admin/users';
        };
      } else {
        authAdmin.hidden = true;
      }
    }
    authAccount.hidden = false;
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
        location.hash = '#/login?next=%23%2F';
      }
    };
  } else {
    authStatus.textContent = authState.checked ? 'Not signed in' : 'Checking...';
    if (authAdmin) authAdmin.hidden = true;
    authAccount.hidden = true;
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
  const tabs = [
    [tabStandorte, path === '/' || path.startsWith('/standort') || path.startsWith('/visit')],
    [tabHives, path.startsWith('/hive')],
    [tabMovements, path.startsWith('/movements')],
    [tabQueens, path.startsWith('/queens') || path.startsWith('/queen')]
  ];
  tabs.forEach(([tab, active]) => {
    if (!tab) return;
    tab.classList.toggle('active', active);
    if (active) {
      tab.setAttribute('aria-current', 'page');
    } else {
      tab.removeAttribute('aria-current');
    }
  });
}

function authGateHtml({ title, subtitle }) {
  const next = encodeURIComponent(location.hash || '#/');
  return card(title, subtitle, `
    ${noticeHtml('Please sign in to continue.', 'warning')}
    <div class="hstack">
      <button class="btn primary" data-navigate="#/login?next=${next}">Sign in</button>
    </div>
  `);
}

async function renderStandorte() {
  setActiveTab('/');
  app.innerHTML = card('Locations', null, loadingStateHtml('Loading locations…'));
  const data = await apiGet({ action:'standorte' });
  const canEdit = canWrite();
  let addHiveBtn = '';
  if (canEdit) {
    setTopbarActions([
      { label: 'Add Hive', primary: true, onClick: () => { location.hash = '#/hive/new'; } }
    ]);
  } else if (authState.user) {
    setTopbarActions([{ label: 'Read-only', disabled: true }]);
  } else {
    addHiveBtn = `<button class="btn" data-navigate="#/login?next=${encodeURIComponent('#/hive/new')}">Sign in to add</button>`;
  }

  const rows = data.standorte.map(r => {
    const route = `#/standort/${encodeURIComponent(r.Standort)}`;
    return `
    <tr data-navigate="${route}">
      <th scope="row"><a class="table-record-link" href="${route}">${htmlesc(r.Standort)}</a></th>
      <td>${htmlesc(r.active_hives)}</td>
      <td>${r.todo_hives > 0 ? htmlesc(r.todo_hives) : ''}</td>
    </tr>
  `;
  }).join('');

  app.innerHTML = card('Locations', null, `
    <div class="hstack">
      ${addHiveBtn}
    </div>
    ${tableScrollHtml('Locations overview', `
      <table class="table locations-table" aria-label="Locations overview">
        <thead><tr><th scope="col">Location</th><th scope="col">Active hives</th><th scope="col">Hives with to-do</th></tr></thead>
        <tbody>${rows || tableEmptyRow('No locations found.', 3)}</tbody>
      </table>
    `)}
  `);
}

async function renderHives() {
  setActiveTab('/hives');
  app.innerHTML = card('Hives', null, loadingStateHtml('Loading hives…'));
  const data = await apiGet({ action:'hives' });

  if (canWrite()) {
    setTopbarActions([
      { label: 'Add Hive', primary: true, onClick: () => { location.hash = '#/hive/new'; } }
    ]);
  } else {
    setTopbarActions([{ label: 'Read-only', disabled: true }]);
  }

  const hives = data.hives || [];
  const renderHiveRows = hiveRows => hiveRows.map(hive => {
    const queen = hive.queen_id
      ? `${htmlesc(hive.queen_id)} · ${htmlesc(hive.queen_breed || '—')} · ${htmlesc(hive.queen_birth_year || '—')}`
      : '—';
    const route = `#/hive/${encodeURIComponent(hive.Hive_ID)}`;
    return `
      <tr data-navigate="${route}">
        <th scope="row"><a class="table-record-link" href="${route}" aria-label="Open hive ${htmlesc(hive.Hive_nr || hive.Hive_ID)}">${htmlesc(hive.Hive_nr || '—')}</a></th>
        <td>${htmlesc(hive.Standort || '—')}</td>
        <td>${htmlesc(fmtDate(hive.last_visit_date))}</td>
        <td>${queen}</td>
        <td>${hive.ToDo
          ? `<span class="hives-current-todo">${htmlesc(hive.ToDo)}</span>`
          : '<span class="muted">—</span>'}</td>
      </tr>
    `;
  }).join('');

  const sortValues = {
    hive: hive => hive.Hive_nr || hive.Hive_ID,
    location: hive => hive.Standort,
    date: hive => hive.last_visit_date,
    queen: hive => hive.queen_id
      ? `${hive.queen_id} ${hive.queen_breed || ''} ${hive.queen_birth_year || ''}`
      : null,
    todo: hive => hive.ToDo
  };
  const compareValues = (left, right, ascending) => {
    const leftEmpty = left === null || left === undefined || left === '';
    const rightEmpty = right === null || right === undefined || right === '';
    if (leftEmpty !== rightEmpty) return leftEmpty ? 1 : -1;
    const comparison = String(left || '').localeCompare(
      String(right || ''),
      undefined,
      { numeric: true, sensitivity: 'base' }
    );
    return ascending ? comparison : -comparison;
  };
  const sortHives = (key, ascending) => [...hives].sort((a, b) => (
    compareValues(sortValues[key](a), sortValues[key](b), ascending)
      || compareValues(a.Hive_ID, b.Hive_ID, true)
  ));

  app.innerHTML = card('Hives', null, tableScrollHtml('Active hives', `
    <table class="table hives-table" aria-label="Active hives">
      <thead>
        <tr>
          <th scope="col"><button type="button" class="table-sort-button" data-hive-sort="hive">Hive_Nr</button></th>
          <th scope="col"><button type="button" class="table-sort-button" data-hive-sort="location">Location</button></th>
          <th scope="col"><button type="button" class="table-sort-button" data-hive-sort="date">Last visit</button></th>
          <th scope="col"><button type="button" class="table-sort-button" data-hive-sort="queen">Queen (ID · Breed · Birth year)</button></th>
          <th scope="col"><button type="button" class="table-sort-button" data-hive-sort="todo">To-Do</button></th>
        </tr>
      </thead>
      <tbody id="hives-table-body">${renderHiveRows(sortHives('hive', true)) || tableEmptyRow('No active hives found.', 5)}</tbody>
    </table>
  `));

  const tableBody = document.getElementById('hives-table-body');
  const sortButtons = [...app.querySelectorAll('[data-hive-sort]')];
  let activeSort = 'hive';
  let ascending = true;
  const updateSortIndicators = () => {
    sortButtons.forEach(sortButton => {
      const isActive = sortButton.dataset.hiveSort === activeSort;
      sortButton.closest('th').setAttribute('aria-sort', isActive ? (ascending ? 'ascending' : 'descending') : 'none');
      sortButton.classList.toggle('active', isActive);
      sortButton.dataset.direction = isActive ? (ascending ? 'asc' : 'desc') : '';
    });
  };
  sortButtons.forEach(button => {
    button.addEventListener('click', () => {
      const key = button.dataset.hiveSort;
      ascending = activeSort === key ? !ascending : true;
      activeSort = key;
      tableBody.innerHTML = renderHiveRows(sortHives(key, ascending))
        || tableEmptyRow('No active hives found.', 5);
      updateSortIndicators();
    });
  });
  updateSortIndicators();
}

async function renderHiveMovements() {
  setActiveTab('/movements');
  app.innerHTML = card('Hive Movements', null, loadingStateHtml('Loading hive movements…'));
  const data = await apiGet({ action:'hive_movements' });

  if ((!data.nodes || data.nodes.length === 0) && (!data.links || data.links.length === 0)) {
    app.innerHTML = card('Hive Movements', null, emptyStateHtml('No hive movements found.'));
    return;
  }

  const nodes = data.nodes || [];
  const chartAvailable = !!(window.d3 && window.d3.sankey && window.d3.sankeyLinkHorizontal);
  const nodeName = (reference) => {
    if (reference && typeof reference === 'object') return reference.name || '—';
    return nodes[Number(reference)]?.name || '—';
  };
  const movementRows = (data.links || []).map(link => `
    <tr>
      <td>${htmlesc(fmtDate(link.date))}</td>
      <td>${htmlesc(nodeName(link.source))} <span aria-hidden="true">→</span><span class="sr-only"> to </span> ${htmlesc(nodeName(link.target))}</td>
      <td>${htmlesc(link.value ?? 0)}</td>
      <td>${htmlesc(link.hives || link.hive_ids || '—')}</td>
    </tr>
  `).join('');

  app.innerHTML = card('Hive Movements', null, `
    ${chartAvailable
      ? '<div id="sankey-chart" class="sankey-chart" role="region" aria-label="Hive movement flow diagram" tabindex="0"></div>'
      : noticeHtml('The flow diagram is unavailable. Movement data remains available below.', 'warning')}
    <h2 class="section-title">Movement overview</h2>
    ${tableScrollHtml('Hive movement details', `
      <table class="table movements-table" aria-label="Hive movement details">
        <thead>
          <tr>
            <th scope="col">Date</th>
            <th scope="col">Movement</th>
            <th scope="col">Count</th>
            <th scope="col">Hives</th>
          </tr>
        </thead>
        <tbody>${movementRows || tableEmptyRow('No movement flows found.', 4)}</tbody>
      </table>
    `)}
  `);
  if (chartAvailable) {
    renderSankeyChart(document.getElementById('sankey-chart'), data);
  }
}

async function renderQueens() {
  setActiveTab('/queens');
  const sortOptions = {
    birth: { label: 'Geburtsjahr + ID', defaultAscending: false },
    id: { label: 'ID', defaultAscending: false },
    location: { label: 'Standort + Hive_nr', defaultAscending: true, alignRight: true }
  };
  let activeSort = 'birth';
  let ascending = sortOptions[activeSort].defaultAscending;
  const sortHeader = `
    <thead>
      <tr>
        ${Object.entries(sortOptions).map(([value, option]) => `
          <th scope="col"><button type="button" class="table-sort-button${option.alignRight ? ' align-right' : ''}" data-queen-sort="${htmlesc(value)}" aria-label="Sort queens by ${htmlesc(option.label)}">${htmlesc(option.label)}</button></th>
        `).join('')}
      </tr>
    </thead>
  `;

  app.innerHTML = card('Queens', null, tableScrollHtml('Queens', `
    <table class="table queens-table" aria-label="Queens">
      ${sortHeader}
      <tbody><tr><td colspan="3">${loadingStateHtml('Loading queens…')}</td></tr></tbody>
    </table>
  `));
  const data = await apiGet({ action:'queens' });
  const queens = data.queens || [];
  const canEdit = canWrite();
  const strong = v => (v ? `<strong>${htmlesc(v)}</strong>` : '');
  const joinParts = parts => parts.filter(p => p && String(p).length > 0).join(' · ');
  const queenYearClass = year => {
    const digit = Number.parseInt(String(year ?? '').slice(-1), 10);
    if (!Number.isFinite(digit)) return '';
    return `queen-year-${digit % 5}`;
  };
  const compareValues = (left, right, sortAscending) => {
    const leftEmpty = left === null || left === undefined || left === '';
    const rightEmpty = right === null || right === undefined || right === '';
    if (leftEmpty !== rightEmpty) return leftEmpty ? 1 : -1;
    const comparison = String(left ?? '').localeCompare(
      String(right ?? ''),
      undefined,
      { numeric: true, sensitivity: 'base' }
    );
    return sortAscending ? comparison : -comparison;
  };
  const sortValues = {
    birth: queen => [queen.Geburtsjahr, queen.ID],
    id: queen => [queen.ID],
    location: queen => [queen.Standort, queen.Hive_nr]
  };
  const sortQueens = (key, sortAscending) => [...queens].sort((left, right) => {
    const valueGetter = sortValues[key] || sortValues.birth;
    const leftValues = valueGetter(left);
    const rightValues = valueGetter(right);
    for (let index = 0; index < leftValues.length; index += 1) {
      const comparison = compareValues(
        leftValues[index],
        rightValues[index],
        sortAscending
      );
      if (comparison !== 0) return comparison;
    }
    return compareValues(left.ID, right.ID, true);
  });
  let addQueenBtn = '';
  if (canEdit) {
    setTopbarActions([
      { label: 'Add Queen', primary: true, onClick: () => { location.hash = '#/queen/new'; } }
    ]);
  } else if (authState.user) {
    setTopbarActions([
      { label: 'Read-only', disabled: true }
    ]);
  } else {
    addQueenBtn = `<button type="button" class="btn" data-navigate="#/login?next=${encodeURIComponent('#/queen/new')}">Sign in to add</button>`;
  }

  const renderQueenRows = queenRows => queenRows.map(q => {
    const route = `#/queen/${encodeURIComponent(q.ID)}`;
    return `
    <tr class="${queenYearClass(q.Geburtsjahr)}" data-navigate="${route}">
      <td colspan="3">
        <div class="vstack stack-tight">
          <div class="qline">
            <div class="qleft">${joinParts([
              `<a class="table-record-link" href="${route}" aria-label="Open queen ${htmlesc(q.ID)}"><strong>${htmlesc(q.ID)}</strong></a>`,
              htmlesc(q.Rasse || ''),
              htmlesc(q.gezeichnet || ''),
              htmlesc(q.Lebensnummer || ''),
              htmlesc(q.Belegstelle || ''),
            ])}</div>
            <div class="qright">${strong(q.Hive_nr || '')}</div>
          </div>
          <div class="qline muted">
            <div class="qleft">${joinParts([
              htmlesc(q.Geburtsjahr || ''),
              htmlesc(q.Zuechter || ''),
              htmlesc(q.LN_Mutter || ''),
              htmlesc(q.LN_Vatermutter || ''),
            ])}</div>
            <div class="qright">${strong(q.Standort || '')}</div>
          </div>
        </div>
      </td>
    </tr>
  `;
  }).join('');

  app.innerHTML = card('Queens', null, `
    ${addQueenBtn ? `<div class="hstack">${addQueenBtn}</div>` : ''}
    ${tableScrollHtml('Queens', `
      <table class="table queens-table" aria-label="Queens">
        ${sortHeader}
        <tbody id="queens-table-body">${renderQueenRows(sortQueens(activeSort, ascending)) || tableEmptyRow('No queens found.', 3)}</tbody>
      </table>
    `)}
  `);

  const tableBody = document.getElementById('queens-table-body');
  const sortButtons = [...app.querySelectorAll('[data-queen-sort]')];
  const updateSortIndicators = () => {
    sortButtons.forEach(sortButton => {
      const isActive = sortButton.dataset.queenSort === activeSort;
      sortButton.closest('th').setAttribute('aria-sort', isActive ? (ascending ? 'ascending' : 'descending') : 'none');
      sortButton.classList.toggle('active', isActive);
      sortButton.dataset.direction = isActive ? (ascending ? 'asc' : 'desc') : '';
      const option = sortOptions[sortButton.dataset.queenSort];
      const direction = isActive ? `, currently ${ascending ? 'ascending' : 'descending'}` : '';
      sortButton.setAttribute('aria-label', `Sort queens by ${option.label}${direction}`);
    });
  };
  sortButtons.forEach(button => {
    button.addEventListener('click', () => {
      const requestedKey = button.dataset.queenSort;
      const key = Object.prototype.hasOwnProperty.call(sortOptions, requestedKey) ? requestedKey : 'birth';
      ascending = activeSort === key ? !ascending : sortOptions[key].defaultAscending;
      activeSort = key;
      tableBody.innerHTML = renderQueenRows(sortQueens(key, ascending))
        || tableEmptyRow('No queens found.', 3);
      updateSortIndicators();
    });
  });
  updateSortIndicators();
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
  app.innerHTML = card('Queen', `#${queenId}`, loadingStateHtml('Loading queen…'));
  try {
    const data = await apiGet({ action:'queen', id: queenId });
    const q = data.queen;

    app.innerHTML = card('Queen', `Edit #${q.ID}`, `
      ${!writable ? noticeHtml('Read-only access.', 'info') : ''}
      ${queenFormHtml({ q, mode:'update', readOnly: !writable })}
    `);

    if (writable) wireQueenForm({ queenId: q.ID, mode:'update' });
  } catch (err) {
    if (err?.name === 'AbortError') throw err;
    app.innerHTML = card('Queen', `#${queenId}`, `
      ${noticeHtml(`Error loading queen: ${err.message}`, 'error')}
    `);
  }
}

async function renderQueenCreate() {
  setActiveTab('/queen');
  if (!authState.user || !canWrite()) {
    setTopbarBack(() => history.back());
    app.innerHTML = card('Queen', 'New', `
      ${noticeHtml('Write access required to create queens.', 'warning')}
    `);
    return;
  }
  setTopbarBack(() => {
    location.hash = '#/queens';
  });
  app.innerHTML = card('Queen', 'New', loadingStateHtml('Preparing queen form…'));

  const q = {
    Lebensnummer: null,
    Geburtsjahr: null,
    gezeichnet: null,
    Rasse: null,
    Zuechter: null,
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
  const cancelAttrs = isCreate ? 'data-navigate="#/queens"' : 'data-back';
  const deleteBtn = isCreate || readOnly ? '' : `<button type="button" class="btn danger" id="queen-delete">Delete queen</button>`;
  const submitBtn = readOnly ? '' : `<button type="submit" class="btn primary">${submitLabel}</button>`;

  return `
  <form id="queen-form" class="vstack">
    <fieldset class="form form-grid" ${readOnly ? 'disabled' : ''}>
      <div class="field">
        <label for="queen-life-number">Life no.</label>
        <input id="queen-life-number" name="Lebensnummer" value="${htmlesc(q.Lebensnummer || '')}" placeholder="e.g., 24-178-003"/>
      </div>

      <div class="field">
        <label for="queen-birth-year">Birth year</label>
        <input id="queen-birth-year" name="Geburtsjahr" type="number" min="1900" max="2100" step="1" value="${htmlesc(q.Geburtsjahr || '')}" placeholder="e.g., 2024" inputmode="numeric" required/>
      </div>

      <div class="field">
        <label for="queen-marked">Marked</label>
        <input id="queen-marked" name="gezeichnet" value="${htmlesc(q.gezeichnet || '')}" placeholder="e.g., yellow / unmarked"/>
      </div>

      <div class="field">
        <label for="queen-breed">Breed</label>
        <input id="queen-breed" name="Rasse" value="${htmlesc(q.Rasse || '')}" placeholder="e.g., Carnica"/>
      </div>

      <div class="field">
        <label for="queen-breeder">Breeder</label>
        <input id="queen-breeder" name="Zuechter" value="${htmlesc(q.Zuechter || '')}" placeholder="Breeder name"/>
      </div>

      <div class="field">
        <label for="queen-mother">Mother (life no.)</label>
        <input id="queen-mother" name="LN_Mutter" value="${htmlesc(q.LN_Mutter || '')}" placeholder="Life no. of mother"/>
      </div>

      <div class="field">
        <label for="queen-father-mother">Mother of father (life no.)</label>
        <input id="queen-father-mother" name="LN_Vatermutter" value="${htmlesc(q.LN_Vatermutter || '')}" placeholder="Life no. of father's mother"/>
      </div>

      <div class="field">
        <label for="queen-mating-station">Mating station</label>
        <input id="queen-mating-station" name="Belegstelle" value="${htmlesc(q.Belegstelle || '')}" placeholder="Belegstelle"/>
      </div>
    </fieldset>

    <div class="hstack form-actions-split">
      ${deleteBtn}
      <div class="hstack stack-gap-sm">
        <button type="button" class="btn" ${cancelAttrs}>Cancel</button>
        ${submitBtn}
      </div>
    </div>

    ${formStatusHtml('queen-form-msg')}
  </form>`;
}

function wireQueenForm({ queenId, mode='update' }) {
  wireCrudForm({
    apiPost,
    formId: 'queen-form',
    messageId: 'queen-form-msg',
    mode,
    id: queenId,
    createAction: 'queen_create',
    updateAction: 'queen_update',
    deleteButtonId: 'queen-delete',
    deleteAction: 'queen_delete',
    deleteConfirm: 'Delete this queen? This cannot be undone.',
    onSaved: () => { location.hash = '#/queens'; },
    onDeleted: () => { location.hash = '#/queens'; }
  });
}

async function renderStandortDetail(standort) {
  setActiveTab('/standort');
  setTopbarBack(() => {
    location.hash = '#/';
  });
  const locationTitle = `Hives at Location ${standort}`;
  app.innerHTML = card(locationTitle, '', loadingStateHtml('Loading hives at this location…'), 'title');
  const data = await apiGet({ action:'hives_by_standort', standort });

  const hives = data.hives || [];
  const renderHiveRows = hiveRows => hiveRows.map(h => {
    const route = `#/hive/${encodeURIComponent(h.Hive_ID)}`;
    const hiveNumber = h.Hive_nr || h.Hive_ID || '—';
    const queenDetails = joinEscaped([
      h.queen_birth_year,
      h.queen_marked,
      h.queen_breed
    ], ' · ');
    return `
    <tr class="location-hive-row" data-navigate="${route}">
      <th class="location-hive-key-cell" scope="row">
        <a class="location-hive-number-link" href="${route}" aria-label="Open hive ${htmlesc(hiveNumber)}">
          <span class="location-hive-number">${htmlesc(hiveNumber)}</span>
        </a>
      </th>
      <td class="location-hive-comparison-cell">
        <div class="location-hive-grid">
          <div class="location-hive-slot location-hive-slot-visit">
            <span class="location-hive-slot-label sr-only">Latest inspection</span>
            ${h.last_visit_date
              ? `<time datetime="${htmlesc(h.last_visit_date)}">${htmlesc(fmtDate(h.last_visit_date))}</time>`
              : '<span class="location-hive-empty">—</span>'}
          </div>

          <div class="location-hive-slot location-hive-slot-queen">
            <span class="location-hive-slot-label sr-only">Queen</span>
            <span>
              ${h.Queen_ID
                ? `<span>Q ${htmlesc(h.Queen_ID)}</span>${queenDetails ? `<span class="location-hive-queen-details"> · ${queenDetails}</span>` : ''}`
                : (queenDetails || '<span class="location-hive-empty">—</span>')}
            </span>
          </div>

          <dl class="location-hive-metrics">
            <div>
              <dt class="sr-only">Strength</dt>
              <dd>${htmlesc(h.Volksstaerke || '—')}</dd>
            </div>
            <div>
              <dt class="sr-only">Setup</dt>
              <dd class="location-hive-setup-value">${htmlesc(h.Aufbau || '—')}</dd>
            </div>
          </dl>

          <div class="location-hive-record-text">
            <div class="location-hive-slot muted">
              <span class="location-hive-slot-label sr-only">Notes</span>
              ${h.Bemerkungen
                ? `<span class="location-hive-clamp">${htmlesc(h.Bemerkungen)}</span>`
                : '<span class="location-hive-empty">—</span>'}
            </div>
            <div class="location-hive-slot location-hive-slot-todo">
              <span class="location-hive-slot-label sr-only">To-do</span>
              ${h.ToDo
                ? `<strong class="location-hive-clamp">${htmlesc(h.ToDo)}</strong>`
                : '<span class="location-hive-empty">—</span>'}
            </div>
          </div>
        </div>
      </td>
    </tr>
  `;
  }).join('');

  const compareHiveNumbers = (a, b) => String(a.Hive_nr || a.Hive_ID || '').localeCompare(
    String(b.Hive_nr || b.Hive_ID || ''),
    undefined,
    { numeric: true, sensitivity: 'base' }
  ) || Number(a.Hive_ID) - Number(b.Hive_ID);
  const compareDates = (a, b, sortAscending) => {
    const aDate = String(a.last_visit_date || '');
    const bDate = String(b.last_visit_date || '');
    if (!aDate && bDate) return 1;
    if (aDate && !bDate) return -1;
    const comparison = aDate.localeCompare(bDate);
    return sortAscending ? comparison : -comparison;
  };
  const sortHives = (key, sortAscending) => [...hives].sort((a, b) => {
    if (key === 'date') {
      return compareDates(a, b, sortAscending) || compareHiveNumbers(a, b);
    }
    const comparison = compareHiveNumbers(a, b);
    return sortAscending ? comparison : -comparison;
  });
  let activeSort = 'hive';
  let ascending = true;

  app.innerHTML = `
    ${card(locationTitle, '', `
      ${tableScrollHtml(`Hives at location ${standort}`, `
        <table class="table location-hives-table" aria-label="Hives at location ${htmlesc(standort)}">
          <colgroup><col class="location-hive-key-column"/><col/></colgroup>
          <thead>
            <tr>
              <th scope="col">
                <button type="button" class="table-sort-button record-sort-button" data-location-hive-sort="hive">Hive no.</button>
              </th>
              <th scope="col" aria-label="Latest inspection and hive details">
                <div class="location-hive-grid location-hive-header-grid">
                  <div class="location-hive-slot location-hive-slot-visit">
                    <button type="button" class="table-sort-button record-sort-button" data-location-hive-sort="date">Latest inspection</button>
                  </div>
                  <div class="location-hive-slot location-hive-slot-queen">
                    <span class="location-hive-header-label" aria-hidden="true">Queen</span>
                  </div>
                  <div class="location-hive-metrics location-hive-header-metrics" aria-hidden="true">
                    <span>Strength</span>
                    <span>Setup</span>
                  </div>
                  <div class="location-hive-record-text location-hive-header-text" aria-hidden="true">
                    <span>Notes</span>
                    <span>To-do</span>
                  </div>
                </div>
              </th>
            </tr>
          </thead>
          <tbody id="location-hives-body">${renderHiveRows(sortHives(activeSort, ascending)) || tableEmptyRow('No hives found.', 2)}</tbody>
        </table>
      `)}
    `, 'title')}
  `;

  const hivesBody = document.getElementById('location-hives-body');
  const sortButtons = [...app.querySelectorAll('[data-location-hive-sort]')];
  const sortLabels = {
    hive: 'Hive no.',
    date: 'Latest inspection'
  };
  const updateSortIndicators = () => {
    sortButtons.forEach(sortButton => {
      const key = sortButton.dataset.locationHiveSort;
      const isActive = key === activeSort;
      sortButton.closest('th').setAttribute('aria-sort', isActive ? (ascending ? 'ascending' : 'descending') : 'none');
      sortButton.classList.toggle('active', isActive);
      sortButton.dataset.direction = isActive ? (ascending ? 'asc' : 'desc') : '';
      const direction = isActive ? `, currently ${ascending ? 'ascending' : 'descending'}` : '';
      sortButton.setAttribute('aria-label', `Sort hives by ${sortLabels[key]}${direction}`);
    });
  };
  sortButtons.forEach(button => {
    button.addEventListener('click', () => {
      const requestedKey = button.dataset.locationHiveSort;
      const key = Object.prototype.hasOwnProperty.call(sortLabels, requestedKey) ? requestedKey : 'hive';
      ascending = activeSort === key ? !ascending : true;
      activeSort = key;
      hivesBody.innerHTML = renderHiveRows(sortHives(key, ascending))
        || tableEmptyRow('No hives found.', 2);
      updateSortIndicators();
    });
  });
  updateSortIndicators();
}

async function renderHive(hiveId) {
  setActiveTab('/standort');
  app.innerHTML = card('Hive', `#${hiveId}`, loadingStateHtml('Loading hive visits…'));
  const data = await apiGet({ action:'visits_by_hive', hive_id: hiveId });
  let visits = data.visits || [];
  let hasMoreVisits = !!data.has_more;
  let nextVisitOffset = Number(data.next_offset) || visits.length;
  const latestVisit = visits.length ? visits[0] : null;
  const standort = latestVisit?.Standort || '—';
  setTopbarLocation(() => {
    location.hash = `#/standort/${encodeURIComponent(standort)}`;
  }, standort);
  const canEdit = canWrite();
  if (canEdit) {
    setTopbarActions([
      { label: 'Edit Hive', onClick: () => { location.hash = `#/hive/${hiveId}/edit`; } },
      { label: 'Add Visit', primary: true, onClick: () => { location.hash = `#/hive/${hiveId}/new-visit`; } },
    ]);
  } else if (authState.user) {
    setTopbarActions([{ label: 'Read-only', disabled: true }]);
  }
  const editButtons = canEdit || authState.user ? '' : `
      <div class="hstack stack-gap-sm">
        <button class="btn" data-navigate="#/login?next=${encodeURIComponent(`#/hive/${hiveId}`)}">Sign in to edit</button>
      </div>
    `;

  const hiveTitle = data.hive?.Hive_nr ? `Hive Nr. ${data.hive.Hive_nr}` : `Hive ID: #${hiveId}`;
  const queenSummary = latestVisit
    ? joinValues([latestVisit.queen_breed, latestVisit.queen_marked, latestVisit.queen_birth_year], ' ')
    : '';
  const hiveSubtitle = [
    `Queen: ${queenSummary || '—'}`,
    `Züchter: ${latestVisit?.queen_breeder || '—'}`,
    `Belegstelle: ${latestVisit?.queen_belegstelle || '—'}`
  ].join('\n');

  const displayValue = value => (
    value !== null && value !== undefined && String(value).trim() !== ''
      ? htmlesc(value)
      : '—'
  );
  const renderVisitRows = visitRows => visitRows.map(v => {
    const route = `#/visit/${encodeURIComponent(v.ID)}`;
    const formattedDate = v.Datum ? fmtDate(v.Datum) : 'unknown date';
    const dateContent = `<span class="hive-visit-date-full">${htmlesc(v.Datum ? fmtDate(v.Datum) : '—')}</span>`;
    const dateMarkup = v.Datum
      ? `<time datetime="${htmlesc(v.Datum)}">${dateContent}</time>`
      : dateContent;
    const locationSetup = joinEscaped([v.Standort, v.Aufbau], ' · ') || '—';
    const queenId = v.Queen_ID ? `Q ${htmlesc(v.Queen_ID)}` : '—';
    const queenStatus = joinEscaped([v.Koenigin_status]);
    const brood = [v.Brut_Stifte, v.Brut_offen, v.Brut_verdeckelt]
      .map(displayValue)
      .join(' / ');
    return `
      <tr class="hive-visit-row" data-navigate="${route}">
        <th class="hive-visit-key-cell" scope="row">
          <a class="hive-visit-date-link" href="${route}" aria-label="Open visit from ${htmlesc(formattedDate)}">
            ${dateMarkup}
          </a>
        </th>
        <td class="hive-visit-comparison-cell">
          <div class="hive-visit-grid">
            <div class="hive-visit-slot hive-visit-slot-location">
              <span class="sr-only">Location and setup</span>
              <span class="hive-visit-clamp">${locationSetup}</span>
            </div>

            <div class="hive-visit-slot hive-visit-slot-queen">
              <span class="sr-only">Queen ID and status</span>
              <span class="hive-visit-clamp">${queenId}${queenStatus ? ` <span class="hive-visit-secondary">${queenStatus}</span>` : ''}</span>
            </div>

            <dl class="hive-visit-group hive-visit-colony">
              <div><dt class="sr-only">Strength</dt><dd>${displayValue(v.Volksstaerke)}</dd></div>
              <div><dt class="sr-only">Brood eggs, open, closed</dt><dd>${brood}</dd></div>
            </dl>

            <dl class="hive-visit-group hive-visit-behavior">
              <div><dt class="sr-only">Temperament</dt><dd>${displayValue(v.Sanftmut)}</dd></div>
              <div><dt class="sr-only">Comb seat</dt><dd>${displayValue(v.Wabensitz)}</dd></div>
              <div><dt class="sr-only">Swarm tendency</dt><dd>${displayValue(v.Schwarmneigung)}</dd></div>
            </dl>

            <dl class="hive-visit-group hive-visit-stores">
              <div><dt class="sr-only">Honey</dt><dd>${displayValue(v.Honig)}</dd></div>
              <div><dt class="sr-only">Feed</dt><dd>${displayValue(v.Futter)}</dd></div>
            </dl>

            <div class="hive-visit-text">
              <div class="hive-visit-slot muted">
                <span class="sr-only">Notes</span>
                ${v.Bemerkungen
                  ? `<span class="hive-visit-clamp">${htmlesc(v.Bemerkungen)}</span>`
                  : '<span class="hive-visit-empty">—</span>'}
              </div>
              <div class="hive-visit-slot hive-visit-slot-todo">
                <span class="sr-only">To-do</span>
                ${v.ToDo
                  ? `<strong class="hive-visit-clamp">${htmlesc(v.ToDo)}</strong>`
                  : '<span class="hive-visit-empty">—</span>'}
              </div>
            </div>
          </div>
        </td>
      </tr>
    `;
  }).join('');

  const sortVisits = sortAscending => [...visits].sort((a, b) => {
    const aDate = String(a.Datum || '');
    const bDate = String(b.Datum || '');
    if (!aDate && bDate) return 1;
    if (aDate && !bDate) return -1;
    const comparison = aDate.localeCompare(bDate) || Number(a.ID) - Number(b.ID);
    return sortAscending ? comparison : -comparison;
  });
  let visitsAscending = false;

  app.innerHTML = card(hiveTitle, hiveSubtitle, `
    <div class="hstack">
      ${editButtons}
    </div>
    ${tableScrollHtml(`Visits for ${hiveTitle}`, `
      <table class="table hive-visits-table" aria-label="Visits for ${htmlesc(hiveTitle)}">
        <colgroup><col class="hive-visit-key-column"/><col/></colgroup>
        <thead>
          <tr>
            <th scope="col">
              <button type="button" class="table-sort-button record-sort-button" id="hive-visits-date-sort">Date</button>
            </th>
            <th scope="col" aria-label="Visit details">
              <div class="hive-visit-grid hive-visit-header-grid">
                <div class="hive-visit-slot hive-visit-slot-location" aria-hidden="true">Location / setup</div>
                <div class="hive-visit-slot hive-visit-slot-queen" aria-hidden="true">Queen / status</div>
                <div class="hive-visit-group hive-visit-colony hive-visit-header-group" aria-hidden="true">
                  <span>Strength</span><span>Brood E/O/C</span>
                </div>
                <div class="hive-visit-group hive-visit-behavior hive-visit-header-group" aria-hidden="true">
                  <span>Temper.</span><span>Comb seat</span><span>Swarm</span>
                </div>
                <div class="hive-visit-group hive-visit-stores hive-visit-header-group" aria-hidden="true">
                  <span>Honey</span><span>Feed</span>
                </div>
                <div class="hive-visit-text hive-visit-header-text" aria-hidden="true">
                  <span>Notes</span><span>To-do</span>
                </div>
              </div>
            </th>
          </tr>
        </thead>
        <tbody id="hive-visits-body">${renderVisitRows(sortVisits(visitsAscending)) || tableEmptyRow('No visits yet.', 2)}</tbody>
      </table>
    `)}
    <div class="hstack hive-visits-pagination" id="hive-visits-pagination" ${hasMoreVisits ? '' : 'hidden'}>
      <span class="muted" id="hive-visits-load-status" role="status" aria-live="polite"></span>
      <button type="button" class="btn" id="hive-visits-load-more">Weitere laden</button>
    </div>
  `);

  const visitsBody = document.getElementById('hive-visits-body');
  const dateSortButton = document.getElementById('hive-visits-date-sort');
  const pagination = document.getElementById('hive-visits-pagination');
  const loadMoreButton = document.getElementById('hive-visits-load-more');
  const loadStatus = document.getElementById('hive-visits-load-status');
  const updateVisitSortIndicator = () => {
    dateSortButton.closest('th').setAttribute('aria-sort', visitsAscending ? 'ascending' : 'descending');
    dateSortButton.classList.add('active');
    dateSortButton.dataset.direction = visitsAscending ? 'asc' : 'desc';
    dateSortButton.setAttribute('aria-label', `Sort visits by date, currently ${visitsAscending ? 'ascending' : 'descending'}`);
  };
  dateSortButton.addEventListener('click', () => {
    visitsAscending = !visitsAscending;
    visitsBody.innerHTML = renderVisitRows(sortVisits(visitsAscending))
      || tableEmptyRow('No visits yet.', 2);
    updateVisitSortIndicator();
  });
  loadMoreButton.addEventListener('click', async () => {
    loadMoreButton.disabled = true;
    loadMoreButton.textContent = 'Lädt…';
    loadStatus.textContent = '';
    try {
      const moreData = await apiGet({
        action: 'visits_by_hive',
        hive_id: hiveId,
        offset: nextVisitOffset
      });
      const newVisits = moreData.visits || [];
      visits = visits.concat(newVisits);
      hasMoreVisits = !!moreData.has_more;
      nextVisitOffset = Number(moreData.next_offset) || visits.length;
      visitsBody.innerHTML = renderVisitRows(sortVisits(visitsAscending))
        || tableEmptyRow('No visits yet.', 2);
      loadStatus.textContent = newVisits.length === 1
        ? '1 weiterer Visit geladen.'
        : `${newVisits.length} weitere Visits geladen.`;
      pagination.hidden = false;
      loadMoreButton.hidden = !hasMoreVisits;
    } catch (error) {
      if (error.name === 'AbortError') return;
      loadStatus.textContent = `Weitere Visits konnten nicht geladen werden: ${error.message}`;
    } finally {
      loadMoreButton.disabled = false;
      loadMoreButton.textContent = 'Weitere laden';
    }
  });
  updateVisitSortIndicator();
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
  app.innerHTML = card('Hive', `#${hiveId}`, loadingStateHtml('Loading hive…'));
  try {
    const data = await apiGet({ action:'hive', id: hiveId });
    const h = data.hive;

    app.innerHTML = card('Hive', `Edit #${hiveId}`, `
      ${!writable ? noticeHtml('Read-only access.', 'info') : ''}
      ${hiveFormHtml({ h, mode:'update', readOnly: !writable })}
    `);

    if (writable) wireHiveForm({ hiveId, mode:'update' });
  } catch (err) {
    if (err?.name === 'AbortError') throw err;
    app.innerHTML = card('Hive', `#${hiveId}`, `
      ${noticeHtml(`Error loading hive: ${err.message}`, 'error')}
    `);
  }
}

async function renderHiveCreate() {
  setActiveTab('/hive');
  if (!authState.user || !canWrite()) {
    setTopbarBack(() => history.back());
    app.innerHTML = card('Hive', 'New', `
      ${noticeHtml('Write access required to create hives.', 'warning')}
    `);
    return;
  }
  setTopbarBack(() => {
    location.hash = '#/';
  });
  app.innerHTML = card('Hive', 'New', loadingStateHtml('Preparing hive form…'));

  const h = { Hive_nr: null, inactive: 0 };
  app.innerHTML = card('Hive', 'Create new hive', `
    ${hiveFormHtml({ h, mode:'create', readOnly: false })}
  `);

  wireHiveForm({ mode:'create' });
}

function hiveFormHtml({ h, mode='update', readOnly=false }) {
  const isCreate = mode === 'create';
  const submitLabel = isCreate ? 'Create hive' : 'Save changes';
  const cancelAttrs = isCreate ? 'data-navigate="#/"' : 'data-back';
  const submitBtn = readOnly ? '' : `<button type="submit" class="btn primary">${submitLabel}</button>`;

  return `
  <form id="hive-form" class="vstack">
    <fieldset class="form form-grid" ${readOnly ? 'disabled' : ''}>
      <div class="field">
        <label for="hive-number">Hive no.</label>
        <input id="hive-number" name="Hive_nr" value="${htmlesc(h.Hive_nr || '')}" placeholder="e.g., 12"/>
      </div>

      <div class="field">
        <label for="hive-inactive">Inactive</label>
        <label class="checkbox-pill" for="hive-inactive">
          <input id="hive-inactive" type="checkbox" name="inactive" value="1" ${String(h.inactive) === '1' ? 'checked' : ''}/>
          Mark hive as inactive
        </label>
      </div>
    </fieldset>

    <div class="hstack form-actions">
      <button type="button" class="btn" ${cancelAttrs}>Cancel</button>
      ${submitBtn}
    </div>

    ${formStatusHtml('hive-form-msg')}
  </form>`;
}

function wireHiveForm({ hiveId, mode='update' }) {
  wireCrudForm({
    apiPost,
    formId: 'hive-form',
    messageId: 'hive-form-msg',
    mode,
    id: hiveId,
    createAction: 'hive_create',
    updateAction: 'hive_update',
    transform: (data) => ({
      Hive_nr: data.Hive_nr || null,
      inactive: data.inactive ? 1 : 0
    }),
    onSaved: (result) => {
      const targetId = mode === 'create' ? result.id : hiveId;
      location.hash = `#/hive/${targetId}`;
    }
  });
}

async function renderNewVisit(hiveId) {
  setActiveTab('/hive');
  if (!authState.user || !canWrite()) {
    setTopbarBack(() => history.back());
    app.innerHTML = card('New visit', `Hive #${hiveId}`, `
      ${noticeHtml('Write access required to add visits.', 'warning')}
    `);
    return;
  }
  setTopbarBack(() => history.back());
  app.innerHTML = card('New visit', `Hive #${hiveId}`, loadingStateHtml('Preparing visit form…'));
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
    ToDo: d?.ToDo ?? ''
  };
  const queens = queensRes.queens || [];

  app.innerHTML = card('New visit', `Hive #${hiveId} (prefilled: location, queen, setup, to-do)`, `
    ${noticeHtml('Tip: location, queen ID, setup, and to-do are prefilled from the latest visit (if any).', 'info')}
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
  setTopbarBack(null);
  const writable = canWrite();
  app.innerHTML = card('Visit', `#${visitId}`, loadingStateHtml('Loading visit…'));
  const [visitRes, queensRes] = await Promise.all([
    apiGet({ action:'visit', id: visitId }),
    apiGet({ action:'queen_options' })
  ]);

  const v = visitRes.visit;
  const hiveId = v.Hive_ID;
  const standort = v.Standort || '—';
  setTopbarBack(() => {
    location.hash = `#/hive/${encodeURIComponent(hiveId)}`;
  });
  setTopbarLocation(() => {
    location.hash = `#/standort/${encodeURIComponent(standort)}`;
  }, standort);
  const queens = queensRes.queens || [];

  app.innerHTML = card('Visit', `Hive #${hiveId} · ${fmtDate(v.Datum)} · Visit #${visitId}`, `
    ${!writable ? noticeHtml('Read-only access.', 'info') : ''}
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
  const todoClearBtn = isCreate && !readOnly
    ? `<button type="button" class="btn todo-clear" id="visit-todo-clear">Clear</button>`
    : '';
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
    <fieldset class="form form-grid" ${readOnly ? 'disabled' : ''}>
      <div class="field">
        <label for="visit-date">Date</label>
        <input id="visit-date" name="Datum" type="date" value="${htmlesc(visit.Datum || '')}" required />
      </div>

      <div class="field">
        <label for="visit-location">Location</label>
        <input id="visit-location" name="Standort" value="${htmlesc(visit.Standort || '')}" placeholder="e.g., Garten, Waldstand, …"/>
      </div>

      <div class="field">
        <label for="visit-queen">Queen ID</label>
        <select id="visit-queen" name="Queen_ID">${qOptions}</select>
      </div>

      <div class="field">
        <label for="visit-setup">Setup</label>
        <input id="visit-setup" name="Aufbau" value="${htmlesc(visit.Aufbau || '')}" placeholder="e.g., 2 BR + 1 HR"/>
      </div>

      <div class="field">
        <span id="visit-strength-label" class="field-legend">Colony strength</span>
        <div class="segmented-button segmented-button-neutral segmented-ka-soft" role="group" aria-labelledby="visit-strength-label">
          <label for="visit-strength-na">
            <input id="visit-strength-na" type="radio" name="Volksstaerke" value="" ${vsNorm === '' ? 'checked' : ''}/>
            <span>k.A.</span>
          </label>
          <label for="visit-strength-one">
            <input id="visit-strength-one" type="radio" name="Volksstaerke" value="+" ${vsNorm === '+' ? 'checked' : ''}/>
            <span>+</span>
          </label>
          <label for="visit-strength-two">
            <input id="visit-strength-two" type="radio" name="Volksstaerke" value="++" ${vsNorm === '++' ? 'checked' : ''}/>
            <span>++</span>
          </label>
          <label for="visit-strength-three">
            <input id="visit-strength-three" type="radio" name="Volksstaerke" value="+++" ${vsNorm === '+++' ? 'checked' : ''}/>
            <span>+++</span>
          </label>
        </div>
      </div>

      <div class="field">
        <label for="visit-queen-status">Queen status (e.g., da, nicht gesehen, weisellos)</label>
        <input id="visit-queen-status" name="Koenigin_status" value="${htmlesc(visit.Koenigin_status || '')}" placeholder="da / …"/>
      </div>

      <div class="field">
        <span id="visit-brood-label" class="field-legend">Brood</span>
        <div class="segmented-button segmented-checkboxes" role="group" aria-labelledby="visit-brood-label">
          <label for="visit-brood-eggs">
            <input type="hidden" name="Brut_Stifte" value=""/>
            <input id="visit-brood-eggs" type="checkbox" name="Brut_Stifte" value="+" ${visit.Brut_Stifte === '+' ? 'checked' : ''}/>
            <span>Eggs</span>
          </label>
          <label for="visit-brood-open">
            <input type="hidden" name="Brut_offen" value=""/>
            <input id="visit-brood-open" type="checkbox" name="Brut_offen" value="+" ${visit.Brut_offen === '+' ? 'checked' : ''}/>
            <span>Open</span>
          </label>
          <label for="visit-brood-closed">
            <input type="hidden" name="Brut_verdeckelt" value=""/>
            <input id="visit-brood-closed" type="checkbox" name="Brut_verdeckelt" value="+" ${visit.Brut_verdeckelt === '+' ? 'checked' : ''}/>
            <span>Closed</span>
          </label>
        </div>
      </div>

      <div class="field">
        <span id="visit-temperament-label" class="field-legend">Temperament</span>
        <div class="segmented-button segmented-ka-soft" role="group" aria-labelledby="visit-temperament-label">
          <label for="visit-temperament-na">
            <input id="visit-temperament-na" type="radio" name="Sanftmut" value="" ${tmNorm === '' ? 'checked' : ''}/>
            <span>k.A.</span>
          </label>
          <label for="visit-temperament-positive">
            <input id="visit-temperament-positive" type="radio" name="Sanftmut" value="+" ${tmNorm === '+' ? 'checked' : ''}/>
            <span>+</span>
          </label>
          <label for="visit-temperament-negative">
            <input id="visit-temperament-negative" type="radio" name="Sanftmut" value="-" ${tmNorm === '-' ? 'checked' : ''}/>
            <span>-</span>
          </label>
        </div>
      </div>

      <div class="field">
        <span id="visit-comb-seat-label" class="field-legend">Comb seat</span>
        <div class="segmented-button segmented-ka-soft" role="group" aria-labelledby="visit-comb-seat-label">
          <label for="visit-comb-seat-na">
            <input id="visit-comb-seat-na" type="radio" name="Wabensitz" value="" ${wsNorm === '' ? 'checked' : ''}/>
            <span>k.A.</span>
          </label>
          <label for="visit-comb-seat-positive">
            <input id="visit-comb-seat-positive" type="radio" name="Wabensitz" value="+" ${wsNorm === '+' ? 'checked' : ''}/>
            <span>+</span>
          </label>
          <label for="visit-comb-seat-negative">
            <input id="visit-comb-seat-negative" type="radio" name="Wabensitz" value="-" ${wsNorm === '-' ? 'checked' : ''}/>
            <span>-</span>
          </label>
        </div>
      </div>

      <div class="field full">
        <span id="visit-swarm-label" class="field-legend">Swarm tendency</span>
        <div class="segmented-button segmented-button-neutral segmented-button-fluid segmented-ka-soft" role="group" aria-labelledby="visit-swarm-label">
          <label for="visit-swarm-na">
            <input id="visit-swarm-na" type="radio" name="Schwarmneigung" value="" ${swNorm === '' ? 'checked' : ''}/>
            <span>k.A.</span>
          </label>
          <label for="visit-swarm-none">
            <input id="visit-swarm-none" type="radio" name="Schwarmneigung" value="-" ${swNorm === '-' ? 'checked' : ''}/>
            <span>None</span>
          </label>
          <label for="visit-swarm-wzb">
            <input id="visit-swarm-wzb" type="radio" name="Schwarmneigung" value="WZ b" ${swNorm === 'WZ b' ? 'checked' : ''}/>
            <span>WZ b</span>
          </label>
          <label for="visit-swarm-wzo">
            <input id="visit-swarm-wzo" type="radio" name="Schwarmneigung" value="WZ o" ${swNorm === 'WZ o' ? 'checked' : ''}/>
            <span>WZ o</span>
          </label>
          <label for="visit-swarm-wzg">
            <input id="visit-swarm-wzg" type="radio" name="Schwarmneigung" value="WZ g" ${swNorm === 'WZ g' ? 'checked' : ''}/>
            <span>WZ g</span>
          </label>
          <label for="visit-swarm-schw">
            <input id="visit-swarm-schw" type="radio" name="Schwarmneigung" value="Schw" ${swNorm === 'Schw' ? 'checked' : ''}/>
            <span>Schw</span>
          </label>
        </div>
      </div>

      <div class="field">
        <label for="visit-honey">Honey</label>
        <input id="visit-honey" name="Honig" value="${htmlesc(visit.Honig || '')}"/>
      </div>

      <div class="field">
        <label for="visit-feed">Feed</label>
        <input id="visit-feed" name="Futter" value="${htmlesc(visit.Futter || '')}"/>
      </div>

      <div class="field full">
        <label for="visit-notes">Notes</label>
        <textarea id="visit-notes" name="Bemerkungen">${htmlesc(visit.Bemerkungen || '')}</textarea>
      </div>

      <div class="field full">
        <div class="field-label-row">
          <label for="visit-todo">To‑do</label>
          ${todoClearBtn}
        </div>
        <textarea id="visit-todo" name="ToDo">${htmlesc(visit.ToDo || '')}</textarea>
      </div>
    </fieldset>

    <div class="hstack form-actions-split">
      ${deleteBtn}
      <div class="hstack stack-gap-sm">
        <button type="button" class="btn" data-back>Cancel</button>
        ${submitBtn}
      </div>
    </div>

    ${formStatusHtml('form-msg')}
  </form>`;
}

function wireVisitForm({ mode, hiveId, visitId }) {
  const form = document.getElementById('visit-form');
  const todoClearBtn = document.getElementById('visit-todo-clear');
  const todoField = form.elements.namedItem('ToDo');

  if (todoClearBtn && todoField) {
    todoClearBtn.addEventListener('click', () => {
      todoField.value = '';
      todoField.focus();
    });
  }

  wireCrudForm({
    apiPost,
    formId: 'visit-form',
    messageId: 'form-msg',
    mode,
    id: visitId,
    createAction: 'visit_create',
    updateAction: 'visit_update',
    deleteButtonId: 'visit-delete',
    deleteAction: 'visit_delete',
    deleteConfirm: 'Delete this visit? This cannot be undone.',
    onSaved: () => { location.hash = `#/hive/${hiveId}`; },
    onDeleted: () => { location.hash = `#/hive/${hiveId}`; }
  });
}

function renderLogin(r) {
  setActiveTab('/login');
  setTopbarBack(() => history.back());
  const nextParam = r?.query?.get('next');
  const nextHash = nextParam?.startsWith('#') ? nextParam : '#/';

  app.innerHTML = card('Sign in', 'Use your Apiary account', `
    <form id="login-form" class="vstack">
      <div class="form form-grid">
        <div class="field">
          <label for="login-username">Username</label>
          <input id="login-username" name="username" autocomplete="username" required />
        </div>
        <div class="field">
          <label for="login-password">Password</label>
          <input id="login-password" type="password" name="password" autocomplete="current-password" required />
        </div>
      </div>
      <div class="hstack form-actions">
        <button type="button" class="btn" data-back>Cancel</button>
        <button type="submit" class="btn primary">Sign in</button>
      </div>
      ${formStatusHtml('login-msg')}
    </form>
    <div id="login-bootstrap" class="notice" data-state="info" role="status" aria-live="polite" hidden>
      <div class="vstack stack-gap-sm">
        <div>No admin user exists yet. Create a default admin account (admin / admin).</div>
        <div class="hstack form-actions">
          <button type="button" id="login-bootstrap-btn" class="btn primary">Create admin user</button>
        </div>
        ${formStatusHtml('login-bootstrap-msg')}
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
        bootstrapBox.hidden = true;
        return;
      }
      bootstrapBox.hidden = false;
      await ensureAnonymousCsrf();
    } catch (_) {
      bootstrapBox.hidden = true;
    }
  }

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    setFormBusy(form, true);
    setFormStatus(msg, 'Signing in...', 'busy');
    const data = Object.fromEntries(new FormData(form).entries());
    try {
      const res = await apiPost({ action:'login' }, data, { suppressAuthRedirect: true });
      setAuth(res.user || null, res.csrf || null);
      setFormStatus(msg, 'Signed in.', 'success');
      location.hash = nextHash;
    } catch (err) {
      setFormStatus(msg, `Error: ${err.message}`, 'error');
    } finally {
      setFormBusy(form, false);
    }
  });

  if (bootstrapBtn) {
    bootstrapBtn.addEventListener('click', async () => {
      if (!confirm('Create default admin account with username "admin" and password "admin"?')) {
        return;
      }
      bootstrapBtn.disabled = true;
      setFormStatus(bootstrapMsg, 'Creating admin user...', 'busy');
      try {
        await ensureAnonymousCsrf();
        await apiPost({ action:'admin_bootstrap_create' }, { confirm: true }, { suppressAuthRedirect: true });
        setFormStatus(bootstrapMsg, 'Admin user created. You can sign in with admin / admin.', 'success');
        bootstrapBtn.hidden = true;
        const usernameInput = form.querySelector('input[name="username"]');
        const passwordInput = form.querySelector('input[name="password"]');
        if (usernameInput) usernameInput.value = 'admin';
        if (passwordInput) passwordInput.value = 'admin';
      } catch (err) {
        setFormStatus(bootstrapMsg, `Error: ${err.message}`, 'error');
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
      <div class="form form-grid">
        <div class="field">
          <label for="account-current-password">Current password</label>
          <input id="account-current-password" type="password" name="current_password" autocomplete="current-password" required />
        </div>
        <div class="field">
          <label for="account-new-password">New password</label>
          <input id="account-new-password" type="password" name="new_password" autocomplete="new-password" minlength="7" required />
        </div>
        <div class="field">
          <label for="account-confirm-password">Confirm new password</label>
          <input id="account-confirm-password" type="password" name="confirm_password" autocomplete="new-password" minlength="7" aria-describedby="password-msg" required />
        </div>
      </div>
      <div class="hstack form-actions">
        <button type="button" class="btn" data-back>Cancel</button>
        <button type="submit" class="btn primary">Update password</button>
      </div>
      ${formStatusHtml('password-msg')}
    </form>
  `);

  const form = document.getElementById('password-form');
  const msg = document.getElementById('password-msg');
  const confirmPassword = form.elements.namedItem('confirm_password');

  confirmPassword?.addEventListener('input', () => {
    confirmPassword.removeAttribute('aria-invalid');
  });

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    form.classList.add('was-validated');
    setFormStatus(msg, 'Updating...', 'busy');

    const data = Object.fromEntries(new FormData(form).entries());
    if (data.new_password !== data.confirm_password) {
      confirmPassword?.setAttribute('aria-invalid', 'true');
      setFormStatus(msg, 'Error: new passwords do not match.', 'error');
      confirmPassword?.focus();
      return;
    }

    setFormBusy(form, true);
    try {
      await apiPost(
        { action:'change_password' },
        { current_password: data.current_password, new_password: data.new_password }
      );
      setFormStatus(msg, 'Password updated.', 'success');
      form.reset();
    } catch (err) {
      setFormStatus(msg, `Error: ${err.message}`, 'error');
    } finally {
      setFormBusy(form, false);
    }
  });
}

function renderAdminGate() {
  setActiveTab('/admin');
  setTopbarBack(() => history.back());
  app.innerHTML = card('User Administration', 'Admin only', `
    ${noticeHtml('Admin access required.', 'warning')}
  `);
}

async function renderUserAdmin() {
  if (!isAdmin()) return renderAdminGate();
  setActiveTab('/admin');
  setTopbarBack(() => history.back());
  app.innerHTML = card('User Administration', 'Manage users', loadingStateHtml('Loading users…'));
  const data = await apiGet({ action:'users_list' });
  const users = data.users || [];

  const rows = users.map(u => {
    const isSelf = authState.user && String(authState.user.id) === String(u.id);
    const roleSelect = `
      <select id="user-role-${htmlesc(u.id)}" class="user-role" data-id="${htmlesc(u.id)}" data-prev="${htmlesc(u.role)}" aria-label="Role for ${htmlesc(u.username)}" ${isSelf ? 'disabled' : ''}>
        <option value="admin" ${u.role === 'admin' ? 'selected' : ''}>admin</option>
        <option value="contributor" ${u.role === 'contributor' ? 'selected' : ''}>contributor</option>
        <option value="readonly" ${u.role === 'readonly' ? 'selected' : ''}>readonly</option>
      </select>
    `;
    const resetBtn = isSelf
      ? `<button class="btn" aria-label="Reset password unavailable for current user" disabled>Reset Password</button>`
      : `<button class="btn user-reset" data-id="${htmlesc(u.id)}" data-name="${htmlesc(u.username)}" aria-label="Reset password for ${htmlesc(u.username)}">Reset Password</button>`;
    const delBtn = isSelf
      ? `<button class="btn" aria-label="Current user cannot be deleted" disabled>Current user</button>`
      : `<button class="btn danger user-delete" data-id="${htmlesc(u.id)}" data-name="${htmlesc(u.username)}" aria-label="Delete ${htmlesc(u.username)}">Delete</button>`;
    return `
      <tr>
        <td>${htmlesc(u.id)}</td>
        <th scope="row">${htmlesc(u.username)}</th>
        <td>${roleSelect}</td>
        <td>${htmlesc(u.created_at || '—')}</td>
        <td>${htmlesc(u.last_login || '—')}</td>
        <td class="table-actions">
          <div class="table-actions-group">
            ${resetBtn}
            ${delBtn}
          </div>
        </td>
      </tr>
    `;
  }).join('');

  app.innerHTML = card('User Administration', 'Manage users', `
    <div class="hstack">
      <div class="muted">Admins can add or remove users.</div>
      <div class="hstack stack-gap-sm">
        <button class="btn primary" data-navigate="#/admin/users/new">Add User</button>
      </div>
    </div>
    ${tableScrollHtml('User accounts', `
      <table class="table" aria-label="User accounts">
        <thead><tr>
          <th scope="col">ID</th>
          <th scope="col">Username</th>
          <th scope="col">Role</th>
          <th scope="col">Created</th>
          <th scope="col">Last login</th>
          <th scope="col">Actions</th>
        </tr></thead>
        <tbody>${rows || tableEmptyRow('No users found.', 6)}</tbody>
      </table>
    `)}
    ${formStatusHtml('users-msg')}
  `);

  const msg = document.getElementById('users-msg');
  document.querySelectorAll('.user-reset').forEach(btn => {
    btn.addEventListener('click', async () => {
      const id = btn.getAttribute('data-id');
      const name = btn.getAttribute('data-name') || 'this user';
      if (!confirm(`Reset password for ${name} to the configured temporary password?`)) return;
      setFormStatus(msg, 'Resetting password...', 'busy');
      btn.disabled = true;
      try {
        const result = await apiPost({ action:'user_reset_password' }, { id });
        setFormStatus(msg, `Password reset to ${result.temporary_password}.`, 'success');
      } catch (err) {
        setFormStatus(msg, `Error: ${err.message}`, 'error');
      } finally {
        btn.disabled = false;
      }
    });
  });
  document.querySelectorAll('.user-role').forEach(select => {
    select.addEventListener('change', async () => {
      const id = select.getAttribute('data-id');
      const prev = select.getAttribute('data-prev') || '';
      const role = select.value;
      setFormStatus(msg, 'Updating role...', 'busy');
      select.disabled = true;
      try {
        await apiPost({ action:'user_update_role' }, { id, role });
        select.setAttribute('data-prev', role);
        setFormStatus(msg, 'Role updated.', 'success');
        if (authState.user && String(authState.user.id) === String(id)) {
          authState.user.role = role;
          updateAuthUi();
          if (role !== 'admin') {
            location.hash = '#/';
          }
        }
      } catch (err) {
        setFormStatus(msg, `Error: ${err.message}`, 'error');
        select.value = prev;
      } finally {
        select.disabled = false;
      }
    });
  });
  document.querySelectorAll('.user-delete').forEach(btn => {
    btn.addEventListener('click', async () => {
      const id = btn.getAttribute('data-id');
      const name = btn.getAttribute('data-name') || 'this user';
      if (!confirm(`Delete ${name}? This cannot be undone.`)) return;
      setFormStatus(msg, 'Deleting...', 'busy');
      btn.disabled = true;
      try {
        await apiPost({ action:'user_delete' }, { id });
        setFormStatus(msg, 'Deleted.', 'success');
        const row = btn.closest('tr');
        const tableBody = row?.parentElement;
        row?.remove();
        if (tableBody && tableBody.children.length === 0) {
          tableBody.innerHTML = tableEmptyRow('No users found.', 6);
        }
      } catch (err) {
        setFormStatus(msg, `Error: ${err.message}`, 'error');
        btn.disabled = false;
      }
    });
  });
}

function renderUserCreate() {
  if (!isAdmin()) return renderAdminGate();
  setActiveTab('/admin');
  setTopbarBack(() => history.back());
  app.innerHTML = card('Add User', 'Create a new account', `
    <form id="user-create-form" class="vstack">
      <div class="form form-grid">
        <div class="field">
          <label for="user-create-username">Username</label>
          <input id="user-create-username" name="username" autocomplete="username" required />
        </div>
        <div class="field">
          <label for="user-create-role">Role</label>
          <select id="user-create-role" name="role" required>
            <option value="admin">admin</option>
            <option value="contributor" selected>contributor</option>
            <option value="readonly">readonly</option>
          </select>
        </div>
        <div class="field">
          <label for="user-create-password">Password</label>
          <input id="user-create-password" type="password" name="password" autocomplete="new-password" minlength="7" required />
        </div>
        <div class="field">
          <label for="user-create-confirm-password">Confirm password</label>
          <input id="user-create-confirm-password" type="password" name="confirm_password" autocomplete="new-password" minlength="7" aria-describedby="user-create-msg" required />
        </div>
      </div>
      <div class="hstack form-actions">
        <button type="button" class="btn" data-back>Cancel</button>
        <button type="submit" class="btn primary">Create user</button>
      </div>
      ${formStatusHtml('user-create-msg')}
    </form>
  `);

  const form = document.getElementById('user-create-form');
  const msg = document.getElementById('user-create-msg');
  const confirmPassword = form.elements.namedItem('confirm_password');

  confirmPassword?.addEventListener('input', () => {
    confirmPassword.removeAttribute('aria-invalid');
  });

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    form.classList.add('was-validated');
    setFormStatus(msg, 'Creating...', 'busy');
    const data = Object.fromEntries(new FormData(form).entries());
    if (data.password !== data.confirm_password) {
      confirmPassword?.setAttribute('aria-invalid', 'true');
      setFormStatus(msg, 'Error: passwords do not match.', 'error');
      confirmPassword?.focus();
      return;
    }
    setFormBusy(form, true);
    try {
      await apiPost(
        { action:'user_create' },
        { username: data.username, role: data.role, password: data.password }
      );
      setFormStatus(msg, 'User created.', 'success');
      location.hash = '#/admin/users';
    } catch (err) {
      setFormStatus(msg, `Error: ${err.message}`, 'error');
    } finally {
      setFormBusy(form, false);
    }
  });
}

async function router() {
  if (authReady) await authReady;
  const r = parseRoute();
  const parts = r.parts; // e.g., ['standort','Foo'] etc.
  const path = '/' + (parts[0] || '');
  document.body.dataset.route = parts.length ? parts.join('-') : 'locations';
  setTopbarBack(null);
  setTopbarLocation(null);
  setTopbarActions([]);

  if (!authState.user && path !== '/login') {
    const next = encodeURIComponent(location.hash || '#/');
    location.hash = `#/login?next=${next}`;
    return;
  }

  if (path === '/' || path === '//') return renderStandorte();
  if (path === '/hives') return renderHives();
  if (path === '/movements') return renderHiveMovements();
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

  setActiveTab('');
  app.innerHTML = card('Not found', null, noticeHtml('The requested page does not exist.', 'error'));
}

async function runRouter() {
  const token = ++routeRenderToken;
  routeAbortController?.abort();
  routeAbortController = new AbortController();
  app.setAttribute('aria-busy', 'true');
  try {
    await router();
    finalizeRouteView(token);
  } catch (error) {
    if (token !== routeRenderToken) return;
    setActiveTab('');
    app.innerHTML = card('Error', null, `
      ${noticeHtml(`Unable to load this page: ${error.message}`, 'error')}
    `);
    finalizeRouteView(token);
  }
}

function activateNavigation(target, event) {
  if (!target) {
    return;
  }
  event.preventDefault();
  if (target.hasAttribute('data-back')) {
    history.back();
    return;
  }
  location.hash = target.dataset.navigate;
}

document.addEventListener('click', (event) => {
  const target = event.target.closest('[data-navigate], [data-back]');
  const nativeLink = event.target.closest('a[href]');
  if (nativeLink && (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey)) {
    return;
  }
  activateNavigation(target, event);
});

document.addEventListener('keydown', (event) => {
  if ((event.key !== 'Enter' && event.key !== ' ') || event.repeat) return;
  const target = event.target.closest('[data-navigate], [data-back]');
  if (!target) return;
  const nativeControl = event.target.closest('a[href], button, input, select, textarea, summary');
  if (nativeControl || target.matches('a[href], button, input, select, textarea, summary')) return;
  activateNavigation(target, event);
});

authReady = initAuth();
window.addEventListener('hashchange', runRouter);
window.addEventListener('load', runRouter);
