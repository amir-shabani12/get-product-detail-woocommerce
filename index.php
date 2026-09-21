<?php
/**
 * your-site.ir / WooCommerce Product Fetcher
 * Single-page tool: AJAX progress, selectable fields, table + export
 * Results stay in browser memory only — nothing is saved on server.
 */

set_time_limit(120);
ini_set('memory_limit', '256M');
header('Content-Type: text/html; charset=utf-8');

// ─── AJAX handlers ───────────────────────────────────────────────────────────
if (isset($_GET['action']) || (isset($_POST['action']))) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? $_GET['action'] ?? '';

    try {
        if ($action === 'fetch_page') {
            $site   = trim($_POST['site'] ?? '');
            $page   = max(1, (int)($_POST['page'] ?? 1));
            $perPage = min(100, max(1, (int)($_POST['per_page'] ?? 50)));
            $fields = isset($_POST['fields']) ? (array)$_POST['fields'] : [];

            if (!$site) {
                throw new Exception('آدرس سایت وارد نشده است.');
            }

            // Normalize site URL
            $site = preg_replace('#^https?://#i', '', $site);
            $site = rtrim($site, '/');
            $apiUrl = 'https://' . $site . '/wp-json/wc/store/v1/products';

            $result = apiRequest($apiUrl, $page, $perPage);

            $cleaned = [];
            foreach ($result['products'] as $p) {
                $cleaned[] = cleanProduct($p, $fields);
            }

            echo json_encode([
                'ok'            => true,
                'page'          => $page,
                'per_page'      => $perPage,
                'total'         => $result['total'],
                'total_pages'   => $result['total_pages'],
                'products'      => $cleaned,
                'count'         => count($cleaned),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        throw new Exception('اکشن نامعتبر');
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'ok'    => false,
            'error' => $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ─── Helper functions ────────────────────────────────────────────────────────
function apiRequest(string $url, int $page = 1, int $perPage = 50): array
{
    $query = http_build_query([
        'page'     => $page,
        'per_page' => $perPage,
    ]);

    $ch = curl_init($url . '?' . $query);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_HEADER         => true,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_USERAGENT      => 'WooProductFetcher/2.0',
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new Exception('خطا در اتصال: ' . $err);
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $headers = substr($raw, 0, $headerSize);
    $body    = substr($raw, $headerSize);

    if ($httpCode < 200 || $httpCode >= 300) {
        throw new Exception("API خطا برگرداند (HTTP $httpCode). مطمئن شوید آدرس صحیح است و افزونه ووکامرس فعال باشد.");
    }

    $data = json_decode($body, true);
    if (!is_array($data)) {
        throw new Exception('پاسخ API معتبر نیست.');
    }

    // Parse total from headers
    $total = 0;
    $totalPages = 1;
    if (preg_match('/X-WP-Total:\s*(\d+)/i', $headers, $m)) {
        $total = (int)$m[1];
    }
    if (preg_match('/X-WP-TotalPages:\s*(\d+)/i', $headers, $m)) {
        $totalPages = (int)$m[1];
    }
    // Fallback if headers missing
    if ($total === 0) {
        $total = count($data);
        $totalPages = count($data) < $perPage ? 1 : $page + 1;
    }

    return [
        'products'    => $data,
        'total'       => $total,
        'total_pages' => $totalPages,
    ];
}

function cleanText($text): string
{
    if (!$text) return '';
    $text = html_entity_decode((string)$text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = strip_tags($text);
    $text = preg_replace('/\s+/u', ' ', $text);
    return trim($text);
}

function cleanProduct(array $product, array $fields): array
{
    $out = [];

    $map = [
        'id'                  => fn($p) => $p['id'] ?? null,
        'name'                => fn($p) => cleanText($p['name'] ?? ''),
        'slug'                => fn($p) => $p['slug'] ?? '',
        'permalink'           => fn($p) => $p['permalink'] ?? '',
        'sku'                 => fn($p) => $p['sku'] ?? '',
        'short_description'   => fn($p) => cleanText($p['short_description'] ?? $p['summary'] ?? ''),
        'description'         => fn($p) => cleanText($p['description'] ?? ''),
        'price'               => fn($p) => $p['prices']['price'] ?? null,
        'regular_price'       => fn($p) => $p['prices']['regular_price'] ?? null,
        'sale_price'          => fn($p) => $p['prices']['sale_price'] ?? null,
        'currency'            => fn($p) => $p['prices']['currency_code'] ?? '',
        'currency_minor_unit' => fn($p) => $p['prices']['currency_minor_unit'] ?? null,
        'on_sale'             => fn($p) => !empty($p['on_sale']),
        'average_rating'      => fn($p) => $p['average_rating'] ?? null,
        'review_count'        => fn($p) => $p['review_count'] ?? null,
        'is_in_stock'         => fn($p) => $p['is_in_stock'] ?? null,
        'is_purchasable'      => fn($p) => $p['is_purchasable'] ?? null,
        'images'              => function ($p) {
            $imgs = [];
            foreach ($p['images'] ?? [] as $img) {
                $src = $img['src'] ?? '';
                $thumb = $img['thumbnail'] ?? $src;
                if (!$src && !$thumb) continue;
                $imgs[] = [
                    'id'        => $img['id'] ?? null,
                    'src'       => $src,
                    'thumbnail' => $thumb,
                    'alt'       => cleanText($img['alt'] ?? $img['name'] ?? ''),
                ];
            }
            return $imgs;
        },
        'categories'          => function ($p) {
            $cats = [];
            foreach ($p['categories'] ?? [] as $c) {
                $cats[] = [
                    'id'   => $c['id'] ?? null,
                    'name' => cleanText($c['name'] ?? ''),
                    'slug' => $c['slug'] ?? '',
                ];
            }
            return $cats;
        },
        'attributes'          => function ($p) {
            // جدول ویژگی‌های محصول (مثل woocommerce-product-attributes)
            // شامل ویژگی‌های سراسری (pa_*) و سفارشی محصول
            $attrs = [];
            foreach ($p['attributes'] ?? [] as $a) {
                $name = cleanText($a['name'] ?? $a['label'] ?? '');
                $taxonomy = $a['taxonomy'] ?? $a['slug'] ?? '';

                // مقادیر: terms (Store API) یا options (سبک REST) یا value
                $values = [];
                if (!empty($a['terms']) && is_array($a['terms'])) {
                    foreach ($a['terms'] as $t) {
                        if (is_array($t)) {
                            $values[] = cleanText($t['name'] ?? $t['label'] ?? '');
                        } else {
                            $values[] = cleanText((string)$t);
                        }
                    }
                } elseif (!empty($a['options']) && is_array($a['options'])) {
                    foreach ($a['options'] as $opt) {
                        $values[] = is_array($opt)
                            ? cleanText($opt['name'] ?? $opt['label'] ?? '')
                            : cleanText((string)$opt);
                    }
                } elseif (isset($a['value'])) {
                    $values[] = cleanText((string)$a['value']);
                }

                $values = array_values(array_filter($values, fn($v) => $v !== ''));

                $attrs[] = [
                    'id'             => $a['id'] ?? null,
                    'name'           => $name,
                    'taxonomy'       => $taxonomy,
                    'has_variations' => !empty($a['has_variations']) || !empty($a['variation']),
                    'visible'        => $a['visible'] ?? true,
                    'values'         => $values,
                    // سازگاری با نسخه قبلی فرانت
                    'terms'          => array_map(fn($v) => ['name' => $v], $values),
                ];
            }
            return $attrs;
        },
        // نسخه تخت برای جدول «اطلاعات بیشتر» محصول — فقط نام: مقدار
        'attributes_flat'     => function ($p) {
            $rows = [];
            foreach ($p['attributes'] ?? [] as $a) {
                $name = cleanText($a['name'] ?? $a['label'] ?? '');
                if ($name === '') continue;

                $values = [];
                if (!empty($a['terms']) && is_array($a['terms'])) {
                    foreach ($a['terms'] as $t) {
                        $values[] = is_array($t)
                            ? cleanText($t['name'] ?? '')
                            : cleanText((string)$t);
                    }
                } elseif (!empty($a['options']) && is_array($a['options'])) {
                    foreach ($a['options'] as $opt) {
                        $values[] = is_array($opt)
                            ? cleanText($opt['name'] ?? '')
                            : cleanText((string)$opt);
                    }
                } elseif (isset($a['value'])) {
                    $values[] = cleanText((string)$a['value']);
                }
                $values = array_values(array_filter($values, fn($v) => $v !== ''));
                if (!$values) continue;

                $rows[] = [
                    'name'  => $name,
                    'value' => implode('، ', $values),
                ];
            }
            return $rows;
        },
        'tags'                => function ($p) {
            $tags = [];
            foreach ($p['tags'] ?? [] as $t) {
                $tags[] = [
                    'id'   => $t['id'] ?? null,
                    'name' => cleanText($t['name'] ?? ''),
                    'slug' => $t['slug'] ?? '',
                ];
            }
            return $tags;
        },
    ];

    foreach ($fields as $f) {
        if (isset($map[$f])) {
            $out[$f] = $map[$f]($product);
        }
    }
    return $out;
}

// Field definitions (key => Persian label)
$availableFields = [
    'id'                  => 'شناسه',
    'name'                => 'نام محصول',
    'slug'                => 'اسلاگ',
    'permalink'           => 'لینک محصول',
    'sku'                 => 'کد کالا (SKU)',
    'short_description'   => 'توضیحات کوتاه',
    'description'         => 'توضیحات کامل',
    'price'               => 'قیمت',
    'regular_price'       => 'قیمت عادی',
    'sale_price'          => 'قیمت فروش ویژه',
    'currency'            => 'واحد پول',
    'currency_minor_unit' => 'واحد فرعی پول',
    'on_sale'             => 'در حراج',
    'average_rating'      => 'میانگین امتیاز',
    'review_count'        => 'تعداد نظرات',
    'is_in_stock'         => 'موجودی',
    'is_purchasable'      => 'قابل خرید',
    'images'              => 'تصاویر',
    'categories'          => 'دسته‌بندی‌ها',
    'attributes'          => 'ویژگی‌ها (کامل)',
    'attributes_flat'     => 'جدول ویژگی‌ها (نام / مقدار)',
    'tags'                => 'برچسب‌ها',
];

$defaultChecked = ['id', 'name', 'sku', 'price', 'regular_price', 'sale_price', 'categories', 'images', 'attributes_flat'];
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>دریافت محصولات ووکامرس</title>
    <link rel="preconnect" href="https://cdn.jsdelivr.net">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700&display=swap');
        body { font-family: 'Vazirmatn', Tahoma, sans-serif; }
        .progress-bar {
            transition: width 0.35s ease;
        }
        .table-wrap { max-height: 65vh; overflow: auto; }
        table th { position: sticky; top: 0; z-index: 10; }
        .field-chip:checked + label {
            background: #2563eb;
            color: white;
            border-color: #2563eb;
        }
        .field-chip + label:hover {
            border-color: #2563eb;
        }
        .btn-primary {
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
        }
        .btn-primary:hover {
            background: linear-gradient(135deg, #1d4ed8, #1e40af);
        }
        .btn-primary:disabled {
            opacity: 0.55;
            cursor: not-allowed;
        }
        .card {
            box-shadow: 0 10px 40px -12px rgba(0,0,0,.12);
        }
        .img-thumb {
            width: 56px; height: 56px; object-fit: cover; border-radius: 8px;
            background: #f1f5f9;
        }
        .truncate-cell {
            max-width: 220px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
    </style>
</head>
<body class="bg-slate-100 min-h-screen">

<div class="max-w-7xl mx-auto px-4 py-8">

    <!-- Header -->
    <div class="text-center mb-8">
        <h1 class="text-3xl font-bold text-slate-800 mb-2">دریافت محصولات فروشگاه ووکامرس</h1>
        <p class="text-slate-500 text-sm">بدون ذخیره‌سازی روی سرور — نتایج فقط در مرورگر شما نمایش داده می‌شود</p>
    </div>

    <!-- Config Card -->
    <div class="card bg-white rounded-2xl p-6 mb-6">
        <div class="grid md:grid-cols-2 gap-6">
            <!-- Site URL -->
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-2">آدرس سایت وردپرس / ووکامرس</label>
                <div class="flex gap-2">
                    <span class="inline-flex items-center px-3 rounded-r-lg bg-slate-100 text-slate-500 text-sm border border-l-0 border-slate-200">https://</span>
                    <input type="text" id="siteUrl" value="your-site.ir"
                           class="flex-1 rounded-l-lg border border-slate-200 px-4 py-2.5 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none"
                           placeholder="example.com" dir="ltr">
                </div>
                <p class="text-xs text-slate-400 mt-1">فقط دامنه را وارد کنید (مثال: your-site.ir)</p>
            </div>

            <!-- Per page for API -->
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-2">تعداد در هر درخواست API</label>
                <select id="apiPerPage" class="w-full rounded-lg border border-slate-200 px-4 py-2.5 focus:ring-2 focus:ring-blue-500 outline-none">
                    <option value="25">25</option>
                    <option value="50" selected>50</option>
                    <option value="100">100</option>
                </select>
            </div>
        </div>

        <!-- Field selection -->
        <div class="mt-6">
            <div class="flex items-center justify-between mb-3">
                <label class="text-sm font-medium text-slate-700">فیلدهای مورد نظر</label>
                <div class="flex gap-2 text-xs">
                    <button type="button" onclick="toggleAllFields(true)" class="text-blue-600 hover:underline">انتخاب همه</button>
                    <span class="text-slate-300">|</span>
                    <button type="button" onclick="toggleAllFields(false)" class="text-blue-600 hover:underline">حذف همه</button>
                </div>
            </div>
            <div class="flex flex-wrap gap-2" id="fieldsContainer">
                <?php foreach ($availableFields as $key => $label): ?>
                    <div>
                        <input type="checkbox" id="f_<?= $key ?>" value="<?= $key ?>"
                               class="field-chip hidden"
                            <?= in_array($key, $defaultChecked) ? 'checked' : '' ?>>
                        <label for="f_<?= $key ?>"
                               class="inline-block cursor-pointer select-none px-3 py-1.5 rounded-full border border-slate-200 text-sm text-slate-600 bg-white transition">
                            <?= htmlspecialchars($label) ?>
                        </label>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Start button -->
        <div class="mt-6">
            <button id="startBtn" onclick="startFetch()"
                    class="btn-primary w-full md:w-auto px-8 py-3 rounded-xl text-white font-medium text-base shadow-lg shadow-blue-500/25">
                شروع دریافت محصولات
            </button>
        </div>
    </div>

    <!-- Progress Card (hidden initially) -->
    <div id="progressCard" class="card bg-white rounded-2xl p-6 mb-6 hidden">
        <div class="flex items-center justify-between mb-2">
            <span class="text-sm font-medium text-slate-700">در حال دریافت...</span>
            <span id="progressText" class="text-sm font-semibold text-blue-600">0%</span>
        </div>
        <div class="w-full bg-slate-100 rounded-full h-3 overflow-hidden">
            <div id="progressBar" class="progress-bar h-full bg-gradient-to-l from-blue-500 to-indigo-600 rounded-full" style="width:0%"></div>
        </div>
        <p id="progressDetail" class="text-xs text-slate-500 mt-2">آماده</p>
    </div>

    <!-- Results Card -->
    <div id="resultsCard" class="card bg-white rounded-2xl p-6 hidden">
        <div class="flex flex-wrap items-center justify-between gap-4 mb-4">
            <div>
                <h2 class="text-lg font-bold text-slate-800">نتایج</h2>
                <p class="text-sm text-slate-500">
                    <span id="totalCount">0</span> محصول
                    <span id="filterInfo" class="hidden"> — نمایش <span id="filteredCount">0</span> مورد از جستجو</span>
                </p>
            </div>
            <div class="flex flex-wrap gap-2 items-center">
                <label class="text-sm text-slate-600">تعداد در صفحه:</label>
                <select id="pageSize" onchange="changePageSize()" class="rounded-lg border border-slate-200 px-3 py-1.5 text-sm">
                    <option value="10">10</option>
                    <option value="25" selected>25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                    <option value="0">همه</option>
                </select>
                <button onclick="exportJSON()" class="px-4 py-1.5 rounded-lg bg-emerald-500 hover:bg-emerald-600 text-white text-sm font-medium">
                    خروجی JSON
                </button>
                <button onclick="exportExcel()" class="px-4 py-1.5 rounded-lg bg-amber-500 hover:bg-amber-600 text-white text-sm font-medium">
                    خروجی Excel
                </button>
            </div>
        </div>

        <!-- Search -->
        <div class="mb-4 relative">
            <input type="search" id="tableSearch" placeholder="جستجو در نتایج (نام، SKU، توضیحات، ویژگی‌ها و …)"
                   oninput="onSearchInput()"
                   class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 pr-10 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 focus:bg-white outline-none"
                   autocomplete="off">
            <button type="button" id="clearSearchBtn" onclick="clearSearch()" title="پاک کردن"
                    class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 text-sm hidden">✕</button>
        </div>

        <div class="table-wrap border border-slate-200 rounded-xl">
            <table class="w-full text-sm text-right">
                <thead class="bg-slate-50 text-slate-600">
                    <tr id="tableHead"></tr>
                </thead>
                <tbody id="tableBody" class="divide-y divide-slate-100"></tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div id="pagination" class="flex flex-wrap items-center justify-center gap-2 mt-5"></div>
    </div>

    <!-- Error -->
    <div id="errorBox" class="hidden mt-4 bg-red-50 border border-red-200 text-red-700 rounded-xl p-4 text-sm"></div>
</div>

<script>
const FIELD_LABELS = <?= json_encode($availableFields, JSON_UNESCAPED_UNICODE) ?>;

let allProducts = [];
let selectedFields = [];
let currentPage = 1;
let pageSize = 25;
let searchQuery = '';

function getSelectedFields() {
    return Array.from(document.querySelectorAll('.field-chip:checked')).map(c => c.value);
}

function getFilteredProducts() {
    if (!searchQuery) return allProducts;
    const q = searchQuery.toLowerCase().trim();
    if (!q) return allProducts;

    return allProducts.filter(p => {
        for (const f of selectedFields) {
            const val = p[f];
            if (val == null || val === '') continue;
            if (typeof val === 'string' || typeof val === 'number' || typeof val === 'boolean') {
                if (String(val).toLowerCase().includes(q)) return true;
            } else if (Array.isArray(val)) {
                const text = JSON.stringify(val).toLowerCase();
                if (text.includes(q)) return true;
            } else if (typeof val === 'object') {
                if (JSON.stringify(val).toLowerCase().includes(q)) return true;
            }
        }
        return false;
    });
}

function onSearchInput() {
    const input = document.getElementById('tableSearch');
    searchQuery = input.value || '';
    const clearBtn = document.getElementById('clearSearchBtn');
    if (clearBtn) clearBtn.classList.toggle('hidden', !searchQuery);
    currentPage = 1;
    renderTable();
}

function clearSearch() {
    const input = document.getElementById('tableSearch');
    if (input) input.value = '';
    searchQuery = '';
    document.getElementById('clearSearchBtn')?.classList.add('hidden');
    currentPage = 1;
    renderTable();
}

function toggleAllFields(on) {
    document.querySelectorAll('.field-chip').forEach(c => c.checked = on);
}

function showError(msg) {
    const box = document.getElementById('errorBox');
    box.textContent = msg;
    box.classList.remove('hidden');
}

function hideError() {
    document.getElementById('errorBox').classList.add('hidden');
}

function setProgress(pct, detail) {
    document.getElementById('progressBar').style.width = pct + '%';
    document.getElementById('progressText').textContent = Math.round(pct) + '%';
    document.getElementById('progressDetail').textContent = detail || '';
}

async function startFetch() {
    hideError();
    allProducts = [];
    searchQuery = '';
    const searchInput = document.getElementById('tableSearch');
    if (searchInput) searchInput.value = '';
    document.getElementById('clearSearchBtn')?.classList.add('hidden');
    selectedFields = getSelectedFields();

    if (selectedFields.length === 0) {
        showError('حداقل یک فیلد را انتخاب کنید.');
        return;
    }

    const site = document.getElementById('siteUrl').value.trim();
    if (!site) {
        showError('آدرس سایت را وارد کنید.');
        return;
    }

    const apiPerPage = parseInt(document.getElementById('apiPerPage').value, 10);
    const btn = document.getElementById('startBtn');
    btn.disabled = true;
    btn.textContent = 'در حال دریافت...';

    document.getElementById('progressCard').classList.remove('hidden');
    document.getElementById('resultsCard').classList.add('hidden');
    setProgress(0, 'شروع ارتباط با سرور...');

    try {
        // First page → get totals
        let page = 1;
        let totalPages = 1;
        let total = 0;

        while (true) {
            const form = new FormData();
            form.append('action', 'fetch_page');
            form.append('site', site);
            form.append('page', page);
            form.append('per_page', apiPerPage);
            selectedFields.forEach(f => form.append('fields[]', f));

            const res = await fetch(window.location.href, {
                method: 'POST',
                body: form
            });
            const data = await res.json();

            if (!data.ok) {
                throw new Error(data.error || 'خطای ناشناخته');
            }

            if (page === 1) {
                totalPages = data.total_pages || 1;
                total = data.total || 0;
            }

            allProducts = allProducts.concat(data.products || []);

            const pct = totalPages > 0 ? (page / totalPages) * 100 : 100;
            setProgress(pct, `صفحه ${page} از ${totalPages} — ${allProducts.length} محصول تا الان`);

            if (page >= totalPages || (data.products || []).length === 0) {
                break;
            }
            page++;
        }

        setProgress(100, `تمام شد — ${allProducts.length} محصول دریافت شد`);
        currentPage = 1;
        renderTable();
        document.getElementById('resultsCard').classList.remove('hidden');

    } catch (err) {
        showError(err.message || String(err));
        setProgress(0, 'خطا');
    } finally {
        btn.disabled = false;
        btn.textContent = 'شروع دریافت محصولات';
    }
}

function changePageSize() {
    pageSize = parseInt(document.getElementById('pageSize').value, 10);
    currentPage = 1;
    renderTable();
}

function renderTable() {
    const filtered = getFilteredProducts();
    document.getElementById('totalCount').textContent = allProducts.length.toLocaleString('fa-IR');

    const filterInfo = document.getElementById('filterInfo');
    const filteredCount = document.getElementById('filteredCount');
    if (searchQuery.trim()) {
        filterInfo.classList.remove('hidden');
        filteredCount.textContent = filtered.length.toLocaleString('fa-IR');
    } else {
        filterInfo.classList.add('hidden');
    }

    const thead = document.getElementById('tableHead');
    thead.innerHTML = selectedFields.map(f =>
        `<th class="px-4 py-3 font-medium whitespace-nowrap">${FIELD_LABELS[f] || f}</th>`
    ).join('');

    const size = pageSize === 0 ? filtered.length : pageSize;
    const totalPages = pageSize === 0 ? 1 : Math.ceil(filtered.length / size) || 1;
    if (currentPage > totalPages) currentPage = Math.max(1, totalPages);

    const start = pageSize === 0 ? 0 : (currentPage - 1) * size;
    const slice = filtered.slice(start, start + size);

    const tbody = document.getElementById('tableBody');
    if (!slice.length) {
        tbody.innerHTML = `<tr><td colspan="${selectedFields.length || 1}" class="px-4 py-8 text-center text-slate-400">
            ${searchQuery.trim() ? 'موردی با این جستجو پیدا نشد.' : 'محصولی برای نمایش نیست.'}
        </td></tr>`;
    } else {
        tbody.innerHTML = slice.map(p => {
            return '<tr class="hover:bg-slate-50">' + selectedFields.map(f => {
                return `<td class="px-4 py-2.5 align-top">${formatCell(p[f], f)}</td>`;
            }).join('') + '</tr>';
        }).join('');
    }

    renderPagination(totalPages);
}

function formatCell(val, field) {
    if (val === null || val === undefined || val === '') return '<span class="text-slate-300">—</span>';

    if (field === 'images' && Array.isArray(val)) {
        if (!val.length) return '<span class="text-slate-300">بدون تصویر</span>';
        const items = val.slice(0, 4).map(img => {
            const url = img.thumbnail || img.src || '';
            const full = img.src || url;
            if (!url) return '';
            return `<a href="${esc(full)}" target="_blank" rel="noopener" title="${esc(img.alt || '')}">
                <img src="${esc(url)}" class="img-thumb inline-block ml-1 border border-slate-200" alt="${esc(img.alt || '')}"
                     onerror="this.style.display='none';this.nextElementSibling&&(this.nextElementSibling.style.display='inline')">
                <span class="text-xs text-blue-500 hidden">لینک</span>
            </a>`;
        }).filter(Boolean).join('');
        const more = val.length > 4 ? ` <span class="text-xs text-slate-400">+${val.length - 4}</span>` : '';
        return items + more || '<span class="text-slate-300">بدون تصویر</span>';
    }

    if (field === 'categories' && Array.isArray(val)) {
        return val.map(c => `<span class="inline-block bg-blue-50 text-blue-700 text-xs px-2 py-0.5 rounded-full ml-1">${esc(c.name)}</span>`).join('') || '—';
    }

    if (field === 'tags' && Array.isArray(val)) {
        return val.map(t => `<span class="inline-block bg-slate-100 text-slate-600 text-xs px-2 py-0.5 rounded-full ml-1">${esc(t.name)}</span>`).join('') || '—';
    }

    if (field === 'attributes' && Array.isArray(val)) {
        if (!val.length) return '<span class="text-slate-300">بدون ویژگی</span>';
        return `<table class="text-xs border border-slate-200 rounded overflow-hidden w-full max-w-xs">
            ${val.map(a => {
                const vals = (a.values && a.values.length)
                    ? a.values.join('، ')
                    : (a.terms || []).map(t => t.name || t).join('، ');
                return `<tr class="border-b border-slate-100 last:border-0">
                    <td class="px-2 py-1 bg-slate-50 font-medium whitespace-nowrap">${esc(a.name)}</td>
                    <td class="px-2 py-1">${esc(vals) || '—'}</td>
                </tr>`;
            }).join('')}
        </table>`;
    }

    if (field === 'attributes_flat' && Array.isArray(val)) {
        if (!val.length) return '<span class="text-slate-300">بدون ویژگی</span>';
        return `<table class="text-xs border border-slate-200 rounded overflow-hidden w-full max-w-xs">
            ${val.map(r => `<tr class="border-b border-slate-100 last:border-0">
                <td class="px-2 py-1 bg-slate-50 font-medium whitespace-nowrap">${esc(r.name)}</td>
                <td class="px-2 py-1">${esc(r.value)}</td>
            </tr>`).join('')}
        </table>`;
    }

    if (field === 'permalink') {
        return `<a href="${esc(val)}" target="_blank" rel="noopener" class="text-blue-600 hover:underline truncate-cell inline-block">${esc(val)}</a>`;
    }

    if (typeof val === 'boolean') {
        return val
            ? '<span class="text-emerald-600 font-medium">بله</span>'
            : '<span class="text-slate-400">خیر</span>';
    }

    if (['price', 'regular_price', 'sale_price'].includes(field) && val !== null) {
        // Prices from Store API are in minor units (e.g. 1105 for 11.05)
        // We show raw value; user can interpret with currency_minor_unit
        return `<span class="font-medium tabular-nums" dir="ltr">${esc(String(val))}</span>`;
    }

    const str = typeof val === 'object' ? JSON.stringify(val) : String(val);
    if (str.length > 80) {
        return `<span class="truncate-cell" title="${esc(str)}">${esc(str.slice(0, 80))}…</span>`;
    }
    return esc(str);
}

function esc(s) {
    if (s == null) return '';
    return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function renderPagination(totalPages) {
    const el = document.getElementById('pagination');
    if (totalPages <= 1) {
        el.innerHTML = '';
        return;
    }

    let html = '';
    const addBtn = (label, page, disabled = false, active = false) => {
        const cls = active
            ? 'bg-blue-600 text-white'
            : disabled
                ? 'bg-slate-100 text-slate-400 cursor-not-allowed'
                : 'bg-white text-slate-700 hover:bg-slate-50 border border-slate-200';
        html += `<button ${disabled ? 'disabled' : `onclick="goPage(${page})"`}
            class="min-w-[36px] h-9 px-3 rounded-lg text-sm font-medium ${cls}">${label}</button>`;
    };

    addBtn('«', currentPage - 1, currentPage === 1);

    const range = 2;
    let start = Math.max(1, currentPage - range);
    let end = Math.min(totalPages, currentPage + range);

    if (start > 1) {
        addBtn('1', 1);
        if (start > 2) html += '<span class="px-1 text-slate-400">…</span>';
    }
    for (let i = start; i <= end; i++) {
        addBtn(String(i), i, false, i === currentPage);
    }
    if (end < totalPages) {
        if (end < totalPages - 1) html += '<span class="px-1 text-slate-400">…</span>';
        addBtn(String(totalPages), totalPages);
    }

    addBtn('»', currentPage + 1, currentPage === totalPages);
    el.innerHTML = html;
}

function goPage(p) {
    currentPage = p;
    renderTable();
    document.getElementById('resultsCard').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// ─── Export ──────────────────────────────────────────────────────────────────
function exportJSON() {
    if (!allProducts.length) return;
    const blob = new Blob([JSON.stringify({
        source: document.getElementById('siteUrl').value.trim(),
        exported_at: new Date().toISOString(),
        total: allProducts.length,
        fields: selectedFields,
        products: allProducts
    }, null, 2)], { type: 'application/json;charset=utf-8' });
    downloadBlob(blob, 'products.json');
}

function exportExcel() {
    if (!allProducts.length) return;

    // Flatten complex fields for Excel
    const rows = allProducts.map(p => {
        const row = {};
        selectedFields.forEach(f => {
            const label = FIELD_LABELS[f] || f;
            let v = p[f];
            if (Array.isArray(v)) {
                if (f === 'images') {
                    v = v.map(i => i.src || i.thumbnail).filter(Boolean).join(' | ');
                } else if (f === 'categories' || f === 'tags') {
                    v = v.map(c => c.name).join('، ');
                } else if (f === 'attributes') {
                    v = v.map(a => {
                        const vals = (a.values && a.values.length)
                            ? a.values.join('/')
                            : (a.terms || []).map(t => t.name || t).join('/');
                        return a.name + (vals ? ': ' + vals : '');
                    }).join(' | ');
                } else if (f === 'attributes_flat') {
                    v = v.map(r => r.name + ': ' + r.value).join(' | ');
                } else {
                    v = JSON.stringify(v);
                }
            } else if (typeof v === 'boolean') {
                v = v ? 'بله' : 'خیر';
            } else if (v == null) {
                v = '';
            }
            row[label] = v;
        });
        return row;
    });

    const ws = XLSX.utils.json_to_sheet(rows);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Products');
    XLSX.writeFile(wb, 'products.xlsx');
}

function downloadBlob(blob, filename) {
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    a.click();
    URL.revokeObjectURL(url);
}
</script>
</body>
</html>
