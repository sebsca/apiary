export function normalizeFormData(data) {
  Object.keys(data).forEach((key) => {
    if (data[key] === '') {
      data[key] = null;
    }
  });
  return data;
}

export function htmlesc(value) {
  return String(value ?? '').replace(
    /[&<>"']/g,
    (character) => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' })[character]
  );
}

export function parseRoute() {
  const hash = (location.hash || '#/').slice(1);
  const [path, query] = hash.split('?');
  const parts = path.split('/').filter(Boolean);
  return {
    path: '/' + (parts[0] || ''),
    parts,
    query: new URLSearchParams(query || '')
  };
}

export function card(title, subtitle, innerHtml, titleClass = '') {
  const titleClassAttr = titleClass ? `title ${titleClass}` : 'title';
  return `
    <section class="card">
      <div class="hstack">
        <div class="vstack stack-tight">
          <div class="${titleClassAttr}">${htmlesc(title)}</div>
          ${subtitle ? `<div class="subtitle">${htmlesc(subtitle)}</div>` : ''}
        </div>
      </div>
      <div class="spacer"></div>
      ${innerHtml}
    </section>
  `;
}

export function fmtDate(date) {
  if (!date) {
    return '—';
  }
  const match = String(date).match(/^(\d{4})-(\d{2})-(\d{2})/);
  return match ? `${match[3]}.${match[2]}.${match[1]}` : date;
}

export function hasValue(value) {
  return value !== null && value !== undefined && String(value).trim() !== '';
}

export function joinValues(parts, separator = ' ') {
  return parts.filter(hasValue).map(String).join(separator);
}

export function joinEscaped(parts, separator = ' ') {
  return parts.filter(hasValue).map(htmlesc).join(separator);
}
