<?php
/**
 * Розмітка застосунку «AI-радар» (фільтри, результати, порівняння).
 * Спільна для розділу на сайті (templates/ai-radar.php) і для окремого
 * публічного репозиторію ai-radar. Очікує:
 *   $aiRadarEndpoint — URL проксі (напр. '/ai-radar/api')
 * Дані рендерить assets/js/ai-radar.js; без JS показується підказка.
 */
$aiRadarEndpoint = $aiRadarEndpoint ?? '/ai-radar/api';
$e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$niches = AiRadar::NICHES;
asort($niches, SORT_LOCALE_STRING);
?>
<div class="air" id="air" data-endpoint="<?= $e($aiRadarEndpoint) ?>"
     data-niches='<?= $e(json_encode(AiRadar::NICHES, JSON_UNESCAPED_UNICODE)) ?>'
     data-stacks='<?= $e(json_encode(AiRadar::STACKS, JSON_UNESCAPED_UNICODE)) ?>'>

  <form class="air-filters" id="air-filters" role="search" aria-label="Фільтри AI-радару">
    <div class="air-filters__search">
      <label class="air-sr" for="air-q">Пошук</label>
      <input type="search" id="air-q" name="q" maxlength="100" placeholder="Що шукаєте? Напр.: video, voice, agent, CRM…" autocomplete="off">
      <button type="submit" class="air-btn air-btn--primary">Знайти</button>
    </div>

    <div class="air-filters__row">
      <label class="air-field">
        <span>Ніша</span>
        <select name="niche" id="air-niche">
          <option value="">Усі ніші</option>
          <?php foreach ($niches as $value => $label): ?>
            <option value="<?= $e($value) ?>"><?= $e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <label class="air-field">
        <span>На чому зроблено</span>
        <select name="stack" id="air-stack">
          <option value="">Будь-який</option>
          <?php foreach (AiRadar::STACKS as $value => $label): ?>
            <option value="<?= $e($value) ?>"><?= $e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <label class="air-field">
        <span>Запущено</span>
        <select name="period" id="air-period">
          <option value="all">За весь час</option>
          <option value="7">Останні 7 днів</option>
          <option value="30">Останні 30 днів</option>
          <option value="90">Останні 3 місяці</option>
          <option value="365">Останній рік</option>
        </select>
      </label>

      <label class="air-field">
        <span>Domain Rating від</span>
        <select name="dr_min" id="air-dr">
          <option value="0">Будь-який</option>
          <option value="10">10+</option>
          <option value="20">20+</option>
          <option value="40">40+</option>
          <option value="60">60+</option>
        </select>
      </label>

      <label class="air-field">
        <span>Сортування</span>
        <select name="sort" id="air-sort">
          <option value="new">Спершу нові</option>
          <option value="dr">Спершу авторитетні (DR)</option>
          <option value="relevance">За релевантністю</option>
        </select>
      </label>
    </div>

    <div class="air-quick" aria-label="Популярні ніші">
      <span class="air-quick__label">Швидкий вибір:</span>
      <?php foreach (['AI Agents & Autonomous', 'AI Website Builder', 'Video Generation', 'Voice & Text-to-Speech', 'Code & Dev Tools', 'Marketing & Ads'] as $quick): ?>
        <button type="button" class="air-chip" data-niche="<?= $e($quick) ?>"><?= $e(AiRadar::NICHES[$quick]) ?></button>
      <?php endforeach; ?>
      <button type="button" class="air-chip air-chip--reset" id="air-reset">Скинути фільтри</button>
    </div>
  </form>

  <div class="air-status" id="air-status" role="status" aria-live="polite">
    <noscript>Для роботи каталогу потрібен увімкнений JavaScript.</noscript>
  </div>

  <div class="air-grid" id="air-results"></div>

  <div class="air-more">
    <button type="button" class="air-btn" id="air-more" hidden>Показати ще</button>
  </div>

  <div class="air-compare-bar" id="air-compare-bar" hidden>
    <span id="air-compare-count"></span>
    <button type="button" class="air-btn air-btn--primary" id="air-compare-open">Порівняти</button>
    <button type="button" class="air-btn air-btn--ghost" id="air-compare-clear">Очистити</button>
  </div>

  <dialog class="air-dialog" id="air-dialog" aria-labelledby="air-dialog-title">
    <div class="air-dialog__head">
      <h2 id="air-dialog-title">Порівняння сайтів</h2>
      <button type="button" class="air-dialog__close" id="air-dialog-close" aria-label="Закрити">×</button>
    </div>
    <div class="air-dialog__body" id="air-compare-table"></div>
  </dialog>

  <p class="air-source">
    Дані: <a href="https://freeserp.ai" target="_blank" rel="noopener">FreeSerp</a> — індекс головних сторінок сайтів (index=sites, ніша AI).
    Опис сайтів згенерований AI на боці FreeSerp і може бути неточним.
  </p>
</div>
