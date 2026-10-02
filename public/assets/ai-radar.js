/**
 * AI-радар — фронтенд каталогу AI-сайтів (дані: FreeSerp через наш проксі).
 * Чистий JS без залежностей. DOM будується лише через textContent /
 * createElement — дані приходять із зовнішнього API, тож жодного innerHTML.
 */
(function () {
  'use strict';

  var root = document.getElementById('air');
  if (!root) return;

  var endpoint = root.dataset.endpoint;
  var NICHES = JSON.parse(root.dataset.niches || '{}');
  var STACKS = JSON.parse(root.dataset.stacks || '{}');
  var FIELDS = ['q', 'niche', 'stack', 'period', 'dr_min', 'sort'];
  var DEFAULTS = { q: '', niche: '', stack: '', period: 'all', dr_min: '0', sort: 'new' };
  var MAX_COMPARE = 3;

  var form = document.getElementById('air-filters');
  var resultsEl = document.getElementById('air-results');
  var statusEl = document.getElementById('air-status');
  var moreBtn = document.getElementById('air-more');
  var compareBar = document.getElementById('air-compare-bar');
  var compareCount = document.getElementById('air-compare-count');
  var dialog = document.getElementById('air-dialog');

  var state = { page: 0, pages: 0, loading: false, controller: null };
  var compare = new Map(); // domain → item

  // ---------- утиліти ----------
  function el(tag, cls, text) {
    var node = document.createElement(tag);
    if (cls) node.className = cls;
    if (text !== undefined && text !== null) node.textContent = text;
    return node;
  }
  function fmtDate(iso) {
    if (!iso) return '—';
    var p = iso.split('-');
    return p.length === 3 ? p[2] + '.' + p[1] + '.' + p[0] : iso;
  }
  function fmtNum(n) {
    return Number(n).toLocaleString('uk-UA');
  }
  function plural(n, one, few, many) {
    var m10 = n % 10, m100 = n % 100;
    if (m10 === 1 && m100 !== 11) return one;
    if (m10 >= 2 && m10 <= 4 && (m100 < 12 || m100 > 14)) return few;
    return many;
  }
  function nicheLabel(n) { return NICHES[n] || n; }
  function stackLabel(s) { return s ? (STACKS[s] || s) : 'Невідомо'; }

  // ---------- стан фільтрів <-> форма <-> URL ----------
  function readForm() {
    var data = {};
    FIELDS.forEach(function (f) { data[f] = (form.elements[f].value || '').trim(); });
    return data;
  }
  function writeForm(data) {
    FIELDS.forEach(function (f) {
      var field = form.elements[f];
      var value = data[f] !== undefined ? data[f] : DEFAULTS[f];
      if (field.tagName === 'SELECT' && !Array.prototype.some.call(field.options, function (o) { return o.value === value; })) {
        value = DEFAULTS[f];
      }
      field.value = value;
    });
    syncRelevance();
  }
  function readUrl() {
    var params = new URLSearchParams(location.search);
    var data = {};
    FIELDS.forEach(function (f) { if (params.has(f)) data[f] = params.get(f); });
    return data;
  }
  function writeUrl(data) {
    var params = new URLSearchParams();
    FIELDS.forEach(function (f) { if (data[f] && data[f] !== DEFAULTS[f]) params.set(f, data[f]); });
    var qs = params.toString();
    history.replaceState(null, '', location.pathname + (qs ? '?' + qs : ''));
  }
  // «За релевантністю» має сенс лише з пошуковим запитом
  function syncRelevance() {
    var hasQuery = form.elements.q.value.trim() !== '';
    var opt = form.elements.sort.querySelector('option[value="relevance"]');
    opt.disabled = !hasQuery;
    if (!hasQuery && form.elements.sort.value === 'relevance') form.elements.sort.value = 'new';
  }

  // ---------- завантаження ----------
  function load(reset) {
    var filters = readForm();
    if (reset) {
      state.page = 0;
      writeUrl(filters);
    }
    if (state.controller) state.controller.abort();
    state.controller = new AbortController();
    state.loading = true;
    moreBtn.disabled = true;
    root.classList.add('is-loading');
    if (reset) statusEl.textContent = 'Шукаємо…';

    var params = new URLSearchParams();
    FIELDS.forEach(function (f) { if (filters[f]) params.set(f, filters[f]); });
    params.set('page', String(state.page));

    fetch(endpoint + '?' + params.toString(), { signal: state.controller.signal, headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
      .then(function (data) {
        if (!data || !data.ok) {
          throw new Error((data && data.error) || 'Не вдалося отримати дані.');
        }
        if (reset) resultsEl.textContent = '';
        state.pages = data.pages;
        renderStatus(data);
        data.items.forEach(function (item) { resultsEl.appendChild(renderCard(item)); });
        moreBtn.hidden = state.page + 1 >= state.pages || data.items.length === 0;
      })
      .catch(function (err) {
        if (err.name === 'AbortError') return;
        if (reset) resultsEl.textContent = '';
        statusEl.textContent = err.message + ' ';
        var retry = el('button', 'air-link', 'Спробувати ще раз');
        retry.type = 'button';
        retry.addEventListener('click', function () { load(reset); });
        statusEl.appendChild(retry);
        moreBtn.hidden = true;
      })
      .then(function () {
        state.loading = false;
        moreBtn.disabled = false;
        root.classList.remove('is-loading');
      });
  }

  function renderStatus(data) {
    statusEl.textContent = '';
    if (data.total === 0) {
      statusEl.appendChild(el('strong', null, 'Нічого не знайдено.'));
      statusEl.appendChild(document.createTextNode(' Спробуйте прибрати частину фільтрів або змінити запит.'));
      return;
    }
    var shown = Math.min(data.total, (state.page + 1) * 24);
    statusEl.appendChild(el('strong', null, 'Знайдено ' + fmtNum(data.total) + ' ' + plural(data.total, 'сайт', 'сайти', 'сайтів')));
    statusEl.appendChild(document.createTextNode(' · показано ' + fmtNum(shown)));
    if (data.anchor) {
      statusEl.appendChild(document.createTextNode(' · найсвіжіший запуск в індексі: ' + fmtDate(data.anchor)));
    }
  }

  // ---------- картка сайту ----------
  function renderCard(item) {
    var card = el('article', 'air-card');

    var head = el('div', 'air-card__head');
    var avatar = el('span', 'air-card__avatar', item.domain.charAt(0).toUpperCase());
    avatar.setAttribute('aria-hidden', 'true');
    var names = el('div', 'air-card__names');
    var domainLink = el('a', 'air-card__domain', item.domain);
    domainLink.href = item.url;
    domainLink.target = '_blank';
    domainLink.rel = 'noopener nofollow';
    names.appendChild(domainLink);
    if (item.title) names.appendChild(el('span', 'air-card__title', item.title));
    head.appendChild(avatar);
    head.appendChild(names);
    card.appendChild(head);

    var meta = el('div', 'air-card__meta');
    meta.appendChild(badge('DR', item.dr === null ? '—' : String(item.dr), 'Domain Rating — авторитетність домену (0–100)'));
    meta.appendChild(badge('Запущено', fmtDate(item.went_live)));
    meta.appendChild(badge('Стек', stackLabel(item.stack)));
    card.appendChild(meta);

    if (item.summary) {
      var summary = el('p', 'air-card__summary', item.summary);
      summary.lang = 'en';
      card.appendChild(summary);
      if (item.summary.length > 180) {
        var toggle = el('button', 'air-link air-card__more', 'Детальніше');
        toggle.type = 'button';
        toggle.addEventListener('click', function () {
          var open = summary.classList.toggle('is-open');
          toggle.textContent = open ? 'Згорнути' : 'Детальніше';
        });
        card.appendChild(toggle);
      }
    }

    if (item.niches.length) {
      var chips = el('div', 'air-card__niches');
      item.niches.forEach(function (n) {
        var chip = el('button', 'air-chip air-chip--small', nicheLabel(n));
        chip.type = 'button';
        chip.title = 'Показати всі сайти ніші «' + nicheLabel(n) + '»';
        chip.addEventListener('click', function () { applyNiche(n); });
        chips.appendChild(chip);
      });
      card.appendChild(chips);
    }

    var foot = el('div', 'air-card__foot');
    var cmp = el('label', 'air-card__compare');
    var box = document.createElement('input');
    box.type = 'checkbox';
    box.checked = compare.has(item.domain);
    box.dataset.domain = item.domain;
    box.addEventListener('change', function () { toggleCompare(item, box); });
    cmp.appendChild(box);
    cmp.appendChild(document.createTextNode(' Порівняти'));
    var open = el('a', 'air-btn air-btn--small', 'Відкрити сайт ↗');
    open.href = item.url;
    open.target = '_blank';
    open.rel = 'noopener nofollow';
    foot.appendChild(cmp);
    foot.appendChild(open);
    card.appendChild(foot);

    return card;
  }

  function badge(label, value, title) {
    var b = el('span', 'air-badge');
    if (title) b.title = title;
    b.appendChild(el('span', 'air-badge__label', label));
    b.appendChild(el('span', 'air-badge__value', value));
    return b;
  }

  function applyNiche(niche) {
    form.elements.niche.value = niche;
    load(true);
    form.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  // ---------- порівняння ----------
  function toggleCompare(item, box) {
    if (box.checked) {
      if (compare.size >= MAX_COMPARE) {
        box.checked = false;
        statusEl.textContent = 'Порівнювати можна до ' + MAX_COMPARE + ' сайтів одночасно — приберіть один із вибраних.';
        return;
      }
      compare.set(item.domain, item);
    } else {
      compare.delete(item.domain);
    }
    updateCompareBar();
  }

  function updateCompareBar() {
    var n = compare.size;
    compareBar.hidden = n === 0;
    compareCount.textContent = 'Вибрано для порівняння: ' + n + ' з ' + MAX_COMPARE;
    document.getElementById('air-compare-open').disabled = n < 2;
    resultsEl.querySelectorAll('input[type="checkbox"][data-domain]').forEach(function (b) {
      b.checked = compare.has(b.dataset.domain);
    });
  }

  function openCompare() {
    var items = Array.from(compare.values());
    var wrap = document.getElementById('air-compare-table');
    wrap.textContent = '';
    var table = el('table', 'air-compare');
    var thead = el('thead');
    var hr = el('tr');
    hr.appendChild(el('th', null, ''));
    items.forEach(function (it) { hr.appendChild(el('th', null, it.domain)); });
    thead.appendChild(hr);
    table.appendChild(thead);

    var maxDr = Math.max.apply(null, items.map(function (it) { return it.dr === null ? -1 : it.dr; }));
    var newest = items.map(function (it) { return it.went_live || ''; }).sort().pop();

    var rows = [
      ['Заголовок', function (it) { return it.title || '—'; }],
      ['Domain Rating', function (it) { return it.dr === null ? '—' : String(it.dr); }, function (it) { return it.dr !== null && it.dr === maxDr && items.length > 1; }],
      ['Запущено', function (it) { return fmtDate(it.went_live); }, function (it) { return it.went_live && it.went_live === newest && items.length > 1; }],
      ['Стек', function (it) { return stackLabel(it.stack); }],
      ['Ніші', function (it) { return it.niches.map(nicheLabel).join(', ') || '—'; }],
      ['Опис', function (it) { return it.summary || '—'; }],
    ];
    var tbody = el('tbody');
    rows.forEach(function (row) {
      var tr = el('tr');
      tr.appendChild(el('th', null, row[0]));
      items.forEach(function (it) {
        var td = el('td', row[2] && row[2](it) ? 'is-best' : null, row[1](it));
        if (row[0] === 'Опис') td.lang = 'en';
        tr.appendChild(td);
      });
      tbody.appendChild(tr);
    });
    var linkRow = el('tr');
    linkRow.appendChild(el('th', null, 'Сайт'));
    items.forEach(function (it) {
      var td = el('td');
      var a = el('a', null, 'Відкрити ↗');
      a.href = it.url;
      a.target = '_blank';
      a.rel = 'noopener nofollow';
      td.appendChild(a);
      linkRow.appendChild(td);
    });
    tbody.appendChild(linkRow);
    table.appendChild(tbody);
    wrap.appendChild(table);
    wrap.appendChild(el('p', 'air-compare__note', 'Зеленим позначено найвищий DR і найсвіжіший запуск серед вибраних.'));

    if (typeof dialog.showModal === 'function') dialog.showModal();
    else dialog.setAttribute('open', '');
  }

  // ---------- події ----------
  form.addEventListener('submit', function (e) { e.preventDefault(); load(true); });
  form.elements.q.addEventListener('input', syncRelevance);
  ['niche', 'stack', 'period', 'dr_min', 'sort'].forEach(function (f) {
    form.elements[f].addEventListener('change', function () { load(true); });
  });
  form.querySelectorAll('.air-chip[data-niche]').forEach(function (chip) {
    chip.addEventListener('click', function () { applyNiche(chip.dataset.niche); });
  });
  document.getElementById('air-reset').addEventListener('click', function () {
    writeForm(DEFAULTS);
    load(true);
  });
  moreBtn.addEventListener('click', function () {
    if (state.loading) return;
    state.page += 1;
    load(false);
  });
  document.getElementById('air-compare-open').addEventListener('click', openCompare);
  document.getElementById('air-compare-clear').addEventListener('click', function () {
    compare.clear();
    updateCompareBar();
  });
  document.getElementById('air-dialog-close').addEventListener('click', function () { dialog.close(); });
  dialog.addEventListener('click', function (e) { if (e.target === dialog) dialog.close(); });

  // ---------- старт ----------
  writeForm(Object.assign({}, DEFAULTS, readUrl()));
  load(true);
})();
