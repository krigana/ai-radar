<?php
/**
 * AI-радар — серверний проксі до публічного API FreeSerp (https://freeserp.ai/docs.php).
 *
 * Навіщо проксі, а не fetch() прямо з браузера: API віддає некоректний
 * CORS-заголовок `Access-Control-Allow-Origin: *, *` (значення
 * продубльоване), і браузери відхиляють такі відповіді. Тому сторінка
 * звертається до нашого /ai-radar/api, а вже PHP — до FreeSerp.
 *
 * Клас навмисно без залежностей від CMS сайту (лише PHP + cURL/streams),
 * щоб той самий файл працював і в розділі на web-service.studio, і в
 * окремому публічному репозиторії ai-radar.
 */

declare(strict_types=1);

final class AiRadar
{
    public const API_URL = 'https://freeserp.ai/api.php';
    public const PAGE_SIZE = 24;
    public const CACHE_TTL = 600;          // 10 хв — відповіді індексу змінюються рідко
    public const ANCHOR_CACHE_TTL = 3600;  // дата найсвіжішого запису в індексі

    /** Ніші (ai_categories з API) → підписи українською. */
    public const NICHES = [
        'AI Agents & Autonomous' => 'AI-агенти',
        'AI Automation & Workflows' => 'Автоматизація процесів',
        'AI Chatbot & Assistant' => 'Чат-боти й асистенти',
        'AI Companion & Character' => 'AI-компаньйони',
        'AI Infrastructure & API' => 'Інфраструктура й API',
        'AI Search & Answers' => 'AI-пошук',
        'AI Website Builder' => 'Конструктори сайтів з AI',
        'AI Avatars & Headshots' => 'Аватари й портрети',
        '3D & Modeling' => '3D та моделювання',
        'Background Removal' => 'Видалення фону',
        'Code & Dev Tools' => 'Код і інструменти розробника',
        'Copywriting & Marketing' => 'Копірайтинг',
        'Customer Support' => 'Підтримка клієнтів',
        'Data & Analytics' => 'Дані й аналітика',
        'Design & UI' => 'Дизайн та UI',
        'Directory / Aggregator' => 'Каталоги й агрегатори',
        'Document & PDF AI' => 'Документи й PDF',
        'E-commerce' => 'E-commerce',
        'Education & Tutoring' => 'Освіта',
        'Email AI' => 'Email',
        'Finance & Trading' => 'Фінанси й трейдинг',
        'Healthcare & Medical' => 'Медицина',
        'Image Editing & Enhancement' => 'Редагування зображень',
        'Image Generation' => 'Генерація зображень',
        'Knowledge & RAG' => 'Бази знань і RAG',
        'LLM & Prompt Tools' => 'LLM і промпти',
        'Lead Gen & Outreach' => 'Лідогенерація',
        'Legal' => 'Юридичні',
        'Logo & Branding' => 'Логотипи й брендинг',
        'Marketing & Ads' => 'Маркетинг і реклама',
        'No-code / App Builder' => 'No-code / конструктори застосунків',
        'Notes & Meetings' => 'Нотатки й зустрічі',
        'Presentations & Slides' => 'Презентації',
        'Productivity' => 'Продуктивність',
        'Recruiting & HR' => 'Рекрутинг і HR',
        'Research & Science' => 'Дослідження й наука',
        'SEO & Content' => 'SEO і контент',
        'Sales & CRM' => 'Продажі й CRM',
        'Security & Moderation' => 'Безпека й модерація',
        'Social Media' => 'Соцмережі',
        'Video Editing' => 'Монтаж відео',
        'Video Generation' => 'Генерація відео',
        'Voice & Text-to-Speech' => 'Голос і озвучка',
        'Writing & Content' => 'Тексти',
    ];

    /** Стек (ai_source з API) → підпис. */
    public const STACKS = [
        'nextjs' => 'Next.js',
        'lovable' => 'Lovable',
        'v0' => 'v0',
        'bolt' => 'Bolt',
        'base44' => 'Base44',
        'ai_likely' => 'Ймовірно згенерований AI',
        'wordpress' => 'WordPress',
        'shopify' => 'Shopify',
    ];

    public const SORTS = ['new', 'dr', 'relevance'];
    public const PERIODS = ['7', '30', '90', '365', 'all'];

    /**
     * Пошук для сторінки. $input — сирі GET-параметри; усе перевіряється тут,
     * у FreeSerp іде лише білий список.
     *
     * @return array{ok:bool, total?:int, page?:int, pages?:int, anchor?:string, items?:array, error?:string}
     */
    public static function search(array $input): array
    {
        $q = trim((string) ($input['q'] ?? ''));
        $q = mb_substr(preg_replace('/\s+/u', ' ', $q) ?? '', 0, 100);

        $niche = (string) ($input['niche'] ?? '');
        $niche = array_key_exists($niche, self::NICHES) ? $niche : '';

        $stack = (string) ($input['stack'] ?? '');
        $stack = array_key_exists($stack, self::STACKS) ? $stack : '';

        $drMin = max(0, min(100, (int) ($input['dr_min'] ?? 0)));

        $period = (string) ($input['period'] ?? 'all');
        $period = in_array($period, self::PERIODS, true) ? $period : 'all';

        $sort = (string) ($input['sort'] ?? 'new');
        $sort = in_array($sort, self::SORTS, true) ? $sort : 'new';
        if ($sort === 'relevance' && $q === '') {
            $sort = 'new'; // релевантність має сенс лише з пошуковим запитом
        }

        $page = max(0, (int) ($input['page'] ?? 0));
        $from = $page * self::PAGE_SIZE;
        if ($from + self::PAGE_SIZE > 10000) { // обмеження API для index=sites
            return ['ok' => false, 'error' => 'Занадто далека сторінка результатів.'];
        }

        // «За останні N днів» рахуємо від найсвіжішої дати в самому індексі,
        // а не від сьогодні: індекс оновлюється із затримкою, і фільтр
        // «7 днів» від сьогоднішньої дати інколи повертав би порожній список.
        $anchor = self::latestDate();

        $params = [
            'index' => 'sites',
            'category' => 'ai',
            'ai_startups' => '1',
            'size' => (string) self::PAGE_SIZE,
            'from' => (string) $from,
        ];
        if ($q !== '') {
            $params['q'] = $q;
        }
        if ($niche !== '') {
            $params['ai_categories'] = $niche;
        }
        if ($stack !== '') {
            $params['ai_source'] = $stack;
        }
        if ($drMin > 0) {
            $params['dr_min'] = (string) $drMin;
        }
        if ($period !== 'all' && $anchor !== null) {
            $params['from_date'] = date('Y-m-d', strtotime($anchor . ' -' . ((int) $period - 1) . ' days'));
            $params['to_date'] = $anchor;
        }
        $params += match ($sort) {
            'dr' => ['sort' => 'dr', 'order' => 'desc'],
            'relevance' => ['sort' => 'relevance'],
            default => ['sort' => 'went_live', 'order' => 'desc'],
        };

        $data = self::request($params, self::CACHE_TTL);
        if ($data === null || empty($data['ok'])) {
            return ['ok' => false, 'error' => 'Сервіс FreeSerp зараз не відповідає. Спробуйте ще раз за хвилину.'];
        }

        $total = (int) ($data['total'] ?? 0);
        $items = array_values(array_filter(array_map([self::class, 'normalize'], $data['results'] ?? [])));

        return [
            'ok' => true,
            'total' => $total,
            'page' => $page,
            'pages' => (int) ceil(min($total, 10000) / self::PAGE_SIZE),
            'anchor' => $anchor,
            'items' => $items,
        ];
    }

    /** Найсвіжіша дата went_live в індексі AI-сайтів (кешується на годину). */
    public static function latestDate(): ?string
    {
        $data = self::request([
            'index' => 'sites',
            'category' => 'ai',
            'ai_startups' => '1',
            'sort' => 'went_live',
            'order' => 'desc',
            'size' => '1',
        ], self::ANCHOR_CACHE_TTL);
        $date = $data['results'][0]['went_live'] ?? null;
        return is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null;
    }

    /** Лишаємо тільки потрібні сторінці поля, перевіряємо типи. */
    private static function normalize(mixed $row): ?array
    {
        if (!is_array($row) || empty($row['domain'])) {
            return null;
        }
        $url = (string) ($row['url'] ?? ('https://' . $row['domain']));
        if (!preg_match('#^https?://#i', $url)) {
            return null;
        }
        $niches = array_values(array_filter(
            is_array($row['ai_categories'] ?? null) ? $row['ai_categories'] : [],
            'is_string'
        ));
        $stack = is_string($row['ai_source'] ?? null) ? $row['ai_source'] : null;

        return [
            'domain' => (string) $row['domain'],
            'url' => $url,
            'title' => trim(html_entity_decode((string) ($row['title'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
            'summary' => trim((string) ($row['ai_summary'] ?? '')),
            'niches' => $niches,
            'stack' => $stack !== null && array_key_exists($stack, self::STACKS) ? $stack : null,
            'dr' => isset($row['dr']) && is_numeric($row['dr']) ? (int) $row['dr'] : null,
            'went_live' => is_string($row['went_live'] ?? null) ? $row['went_live'] : null,
        ];
    }

    /** GET до FreeSerp з файловим кешем і одним повтором на 502. */
    private static function request(array $params, int $ttl): ?array
    {
        $params += [
            // Ідентифікація агента — FreeSerp просить про це в документації.
            'agent' => 'WebserviceStudio-AIRadar/1.0',
            'website' => 'https://web-service.studio/ai-radar',
        ];
        ksort($params);
        // AI_RADAR_API_URL — лише для локальних тестів (мок-сервер замість FreeSerp).
        $apiUrl = getenv('AI_RADAR_API_URL') ?: self::API_URL;
        $url = $apiUrl . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        $cacheFile = self::cacheDir() . '/' . md5($url) . '.json';
        if (is_file($cacheFile) && filemtime($cacheFile) > time() - $ttl) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached)) {
                return $cached;
            }
        }

        for ($attempt = 0; $attempt < 2; $attempt++) {
            [$code, $body] = self::httpGet($url);
            if ($code === 200 && $body !== null) {
                $data = json_decode($body, true);
                if (is_array($data) && !empty($data['ok'])) {
                    @file_put_contents($cacheFile, $body, LOCK_EX);
                    return $data;
                }
            }
            if ($code !== 502) {
                break;
            }
            usleep(400000);
        }

        // API недоступне — краще показати застарілий кеш, ніж помилку.
        if (is_file($cacheFile)) {
            $stale = json_decode((string) file_get_contents($cacheFile), true);
            return is_array($stale) ? $stale : null;
        }
        return null;
    }

    /** @return array{0:int, 1:?string} */
    private static function httpGet(string $url): array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return [$code, $body === false ? null : (string) $body];
        }

        $context = stream_context_create(['http' => ['timeout' => 8, 'ignore_errors' => true]]);
        $body = @file_get_contents($url, false, $context);
        $code = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $code = (int) $m[1];
            }
        }
        return [$code, $body === false ? null : $body];
    }

    private static function cacheDir(): string
    {
        $dir = sys_get_temp_dir() . '/ai-radar-cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }
}
