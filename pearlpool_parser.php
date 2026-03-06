<?php
/**
 * Парсер товаров с сайта pearlpool.ru
 * Отправка данных в WooCommerce через REST API
 * 
 * Настройка cron: */15 * * * * /usr/bin/php /path/to/pearlpool_parser.php >> /path/to/parser.log 2>&1
 * 
 * Источники данных:
 * - Характеристики: <table class="details__specifications-table">
 * - Цена, описание, картинки: <script type="application/ld+json"> "@type": "Product"
 * - Структура каталога и категория: <script type="application/ld+json"> "@type": "BreadcrumbList"
 */

// Конфигурация WooCommerce API
$config = [
    'api_url' => 'https://9677018686.myjino.ru/basik/wp-json/wc/v3',
    'consumer_key' => 'ck_351a30e18d90c3db83151bd7eda51c971eb7d510',
    'consumer_secret' => 'cs_8ffea5d680b70c3b38123ef59071367bc1ba28a8',
];

// Конфигурация парсера
$parser_config = [
    'base_url' => 'https://pearlpool.ru/',
    'catalog_url' => 'https://pearlpool.ru/catalog/',
    'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    'delay_between_requests' => 2, // задержка между запросами в секундах
    'max_products_per_run' => 100, // максимум товаров за один запуск
];

class PearlPoolParser {
    private $config;
    private $wc_config;
    private $processed_products = [];
    private $existing_categories = [];
    
    public function __construct($parser_config, $wc_config) {
        $this->config = $parser_config;
        $this->wc_config = $wc_config;
    }
    
    /**
     * Выполнение HTTP запроса
     */
    private function makeRequest($url, $headers = []) {
        $ch = curl_init();
        
        $default_headers = [
            'User-Agent: ' . $this->config['user_agent'],
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language: ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
            'Accept-Encoding: gzip, deflate, br',
            'Connection: keep-alive',
            'Upgrade-Insecure-Requests: 1',
        ];
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER => array_merge($default_headers, $headers),
            CURLOPT_TIMEOUT => 60,
            CURLOPT_ENCODING => '',
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_close($ch);
        
        if ($error) {
            throw new Exception("Ошибка запроса к {$url}: {$error}");
        }
        
        if ($http_code !== 200) {
            throw new Exception("HTTP код {$http_code} при запросе к {$url}");
        }
        
        return $response;
    }
    
    /**
     * Извлечение JSON-LD данных из HTML
     */
    private function extractJsonLd($html, $type) {
        $data = null;
        
        // Ищем все script теги с type="application/ld+json"
        if (preg_match_all('#<script\s+type=["\']application/ld\+json["\']>(.*?)</script>#is', $html, $matches)) {
            foreach ($matches[1] as $json_string) {
                $json_string = trim($json_string);
                $json_data = json_decode($json_string, true);
                
                if (json_last_error() === JSON_ERROR_NONE && is_array($json_data)) {
                    // Проверяем @type
                    if (isset($json_data['@type']) && $json_data['@type'] === $type) {
                        $data = $json_data;
                        break;
                    }
                    
                    // Также проверяем вложенные графы (@graph)
                    if (isset($json_data['@graph']) && is_array($json_data['@graph'])) {
                        foreach ($json_data['@graph'] as $graph_item) {
                            if (isset($graph_item['@type']) && $graph_item['@type'] === $type) {
                                $data = $graph_item;
                                break 2;
                            }
                        }
                    }
                }
            }
        }
        
        return $data;
    }
    
    /**
     * Извлечение всех JSON-LD данных из HTML
     */
    private function extractAllJsonLd($html) {
        $all_data = [];
        
        if (preg_match_all('#<script\s+type=["\']application/ld\+json["\']>(.*?)</script>#is', $html, $matches)) {
            foreach ($matches[1] as $json_string) {
                $json_string = trim($json_string);
                $json_data = json_decode($json_string, true);
                
                if (json_last_error() === JSON_ERROR_NONE && is_array($json_data)) {
                    $all_data[] = $json_data;
                    
                    // Добавляем элементы из @graph
                    if (isset($json_data['@graph']) && is_array($json_data['@graph'])) {
                        foreach ($json_data['@graph'] as $graph_item) {
                            $all_data[] = $graph_item;
                        }
                    }
                }
            }
        }
        
        return $all_data;
    }
    
    /**
     * Получение списка категорий из каталога
     */
    public function getCategories() {
        echo "Получение списка категорий...\n";
        
        $html = $this->makeRequest($this->config['catalog_url']);
        $dom = new DOMDocument();
        @$dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($dom);
        
        $categories = [];
        
        // Пробуем найти категории через JSON-LD BreadcrumbList или другие структуры
        $all_json_ld = $this->extractAllJsonLd($html);
        
        foreach ($all_json_ld as $json_data) {
            // Ищем CollectionPage или ItemList с категориями
            if (isset($json_data['@type']) && in_array($json_data['@type'], ['CollectionPage', 'ItemList'])) {
                if (isset($json_data['mainEntity']) && is_array($json_data['mainEntity'])) {
                    foreach ($json_data['mainEntity'] as $entity) {
                        if (isset($entity['@type']) && $entity['@type'] === 'Category') {
                            $categories[] = [
                                'name' => $entity['name'] ?? '',
                                'url' => $entity['url'] ?? '',
                                'slug' => basename(rtrim($entity['url'] ?? '', '/'))
                            ];
                        }
                    }
                }
            }
        }
        
        // Если не нашли через JSON-LD, используем парсинг HTML
        if (empty($categories)) {
            // Ищем ссылки на категории в каталоге
            $category_links = $xpath->query('//a[contains(@href, "/catalog/") and not(contains(@href, "?"))]');
            
            $found_categories = [];
            foreach ($category_links as $link) {
                $href = $link->getAttribute('href');
                $text = trim($link->textContent);
                
                if (!empty($text) && strpos($href, '/catalog/') !== false) {
                    // Исключаем саму страницу каталога и дубли
                    if ($href !== '/catalog/' && !preg_match('#/catalog/$#', $this->config['catalog_url'])) {
                        $full_url = (strpos($href, 'http') === 0) ? $href : rtrim($this->config['base_url'], '/') . '/' . ltrim($href, '/');
                        $slug = basename(rtrim($href, '/'));
                        
                        // Пропускаем если slug пустой или это пагинация
                        if (!empty($slug) && !preg_match('#^page/#', $slug)) {
                            $key = md5($full_url);
                            if (!isset($found_categories[$key])) {
                                $found_categories[$key] = [
                                    'name' => $text,
                                    'url' => $full_url,
                                    'slug' => $slug
                                ];
                            }
                        }
                    }
                }
            }
            
            $categories = array_values($found_categories);
        }
        
        echo "Найдено категорий: " . count($categories) . "\n";
        return $categories;
    }
    
    /**
     * Получение списка URL товаров из категории
     */
    public function getProductUrlsFromCategory($category_url) {
        echo "Сканирование категории: {$category_url}\n";
        
        $html = $this->makeRequest($category_url);
        $dom = new DOMDocument();
        @$dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($dom);
        
        $product_urls = [];
        
        // Ищем ссылки на товары
        $product_links = $xpath->query('//a[contains(@href, "index.php?route=product/product") or contains(@class, "product-item") or contains(@class, "product-block")]');
        
        foreach ($product_links as $link) {
            $href = $link->getAttribute('href');
            if (!empty($href) && (strpos($href, 'route=product/product') !== false || strpos($href, '/product/') !== false)) {
                $full_url = (strpos($href, 'http') === 0) ? $href : rtrim($this->config['base_url'], '/') . '/' . ltrim($href, '/');
                $product_urls[] = $full_url;
            }
        }
        
        // Также ищем через JSON-LD ItemList
        $json_ld = $this->extractJsonLd($html, 'ItemList');
        if ($json_ld && isset($json_ld['itemListElement']) && is_array($json_ld['itemListElement'])) {
            foreach ($json_ld['itemListElement'] as $item) {
                if (isset($item['url']) && (strpos($item['url'], 'product') !== false)) {
                    $product_urls[] = $item['url'];
                }
            }
        }
        
        $product_urls = array_unique($product_urls);
        echo "Найдено ссылок на товары: " . count($product_urls) . "\n";
        
        return $product_urls;
    }
    
    /**
     * Получение детальной информации о товаре
     */
    public function getProductDetails($url) {
        echo "Парсинг товара: {$url}\n";
        
        $html = $this->makeRequest($url);
        
        $dom = new DOMDocument();
        @$dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($dom);
        
        $product = [
            'url' => $url,
            'name' => '',
            'description' => '',
            'price' => '',
            'regular_price' => '',
            'sale_price' => '',
            'images' => [],
            'sku' => '',
            'categories' => [],
            'attributes' => []
        ];
        
        // === 1. Извлекаем данные из JSON-LD Product ===
        $product_json = $this->extractJsonLd($html, 'Product');
        
        if ($product_json) {
            // Название
            if (isset($product_json['name'])) {
                $product['name'] = trim($product_json['name']);
            }
            
            // Описание
            if (isset($product_json['description'])) {
                $product['description'] = trim($product_json['description']);
            } elseif (isset($product_json['aggregateRating'])) {
                // Иногда описание может быть в других полях
            }
            
            // Цена
            if (isset($product_json['offers'])) {
                $offers = $product_json['offers'];
                
                // Если offers это массив (несколько предложений)
                if (is_array($offers) && isset($offers[0])) {
                    $offers = $offers[0];
                }
                
                if (is_array($offers)) {
                    if (isset($offers['price'])) {
                        $product['price'] = str_replace(',', '.', (string)$offers['price']);
                    }
                    if (isset($offers['priceCurrency']) && $offers['priceCurrency'] === 'RUB') {
                        // Цена уже в рублях
                    }
                    if (isset($offers['availability'])) {
                        $product['availability'] = $offers['availability'];
                    }
                }
            }
            
            // Изображения
            if (isset($product_json['image'])) {
                $images = $product_json['image'];
                
                // image может быть строкой или массивом
                if (is_string($images)) {
                    $product['images'][] = $images;
                } elseif (is_array($images)) {
                    foreach ($images as $img) {
                        if (is_string($img)) {
                            $product['images'][] = $img;
                        } elseif (is_array($img) && isset($img['url'])) {
                            $product['images'][] = $img['url'];
                        }
                    }
                }
            }
            
            // Артикул (SKU)
            if (isset($product_json['sku'])) {
                $product['sku'] = trim($product_json['sku']);
            }
            if (isset($product_json['mpn'])) {
                $product['mpn'] = trim($product_json['mpn']);
            }
        }
        
        // === 2. Извлекаем характеристики из таблицы details__specifications-table ===
        $spec_table = $xpath->query('//table[contains(@class, "details__specifications-table")]')->item(0);
        
        if ($spec_table) {
            $rows = $xpath->query('.//tr', $spec_table);
            
            foreach ($rows as $row) {
                $cells = $xpath->query('.//td', $row);
                
                if ($cells->length >= 2) {
                    $attr_name = trim($cells->item(0)->textContent);
                    $attr_value = trim($cells->item(1)->textContent);
                    
                    if (!empty($attr_name) && !empty($attr_value)) {
                        $product['attributes'][] = [
                            'name' => $attr_name,
                            'value' => $attr_value,
                            'visible' => true,
                            'variation' => false
                        ];
                    }
                }
            }
        }
        
        // === 3. Извлекаем категорию из JSON-LD BreadcrumbList ===
        $breadcrumb_json = $this->extractJsonLd($html, 'BreadcrumbList');
        
        if ($breadcrumb_json && isset($breadcrumb_json['itemListElement']) && is_array($breadcrumb_json['itemListElement'])) {
            foreach ($breadcrumb_json['itemListElement'] as $item) {
                if (isset($item['name']) && isset($item['item'])) {
                    $category_name = $item['name'];
                    $category_url = is_array($item['item']) ? ($item['item']['@id'] ?? $item['item']['url'] ?? '') : $item['item'];
                    
                    // Пропускаем главную и текущую страницу товара
                    if (!empty($category_name) && 
                        strpos($category_url, 'product') === false && 
                        $category_name !== 'Главная' &&
                        $category_name !== 'Home') {
                        $product['categories'][] = [
                            'name' => $category_name,
                            'url' => $category_url
                        ];
                    }
                }
            }
        }
        
        // Если не нашли категорию через JSON-LD, пробуем через HTML
        if (empty($product['categories'])) {
            $breadcrumb_links = $xpath->query('//nav[contains(@class, "breadcrumb")]//a | //div[contains(@class, "breadcrumb")]//a');
            
            foreach ($breadcrumb_links as $link) {
                $cat_name = trim($link->textContent);
                $cat_href = $link->getAttribute('href');
                
                if (!empty($cat_name) && !empty($cat_href) && 
                    strpos($cat_href, 'product') === false &&
                    $cat_name !== 'Главная') {
                    $product['categories'][] = [
                        'name' => $cat_name,
                        'url' => (strpos($cat_href, 'http') === 0) ? $cat_href : rtrim($this->config['base_url'], '/') . '/' . ltrim($cat_href, '/')
                    ];
                }
            }
        }
        
        // === 4. Дополнительный парсинг цены из HTML если не нашли в JSON-LD ===
        if (empty($product['price'])) {
            $price_nodes = $xpath->query('//span[contains(@class, "price")] | //div[contains(@class, "price")] | //meta[@property="product:price:amount"]');
            
            foreach ($price_nodes as $node) {
                $price_text = trim($node->textContent);
                if ($node->hasAttribute('content')) {
                    $price_text = $node->getAttribute('content');
                }
                
                // Извлекаем числовое значение
                if (preg_match('/([\d\s]+[,\.]\d{2})/', $price_text, $matches)) {
                    $price_clean = str_replace([' ', ','], ['', '.'], $matches[1]);
                    $product['price'] = $price_clean;
                    break;
                }
            }
        }
        
        // === 5. Дополнительные изображения из HTML ===
        if (empty($product['images'])) {
            $img_nodes = $xpath->query('//div[contains(@class, "product-image")]//img | //div[contains(@class, "gallery")]//img | //meta[@property="og:image"]');
            
            foreach ($img_nodes as $img) {
                $src = $img->getAttribute('src');
                if ($img->hasAttribute('content')) {
                    $src = $img->getAttribute('content');
                }
                
                if (!empty($src) && filter_var($src, FILTER_VALIDATE_URL)) {
                    $product['images'][] = $src;
                }
            }
        }
        
        // Нормализация изображений - добавляем домен если путь относительный
        foreach ($product['images'] as &$img_url) {
            if (strpos($img_url, '//') === 0) {
                $img_url = 'https:' . $img_url;
            } elseif (strpos($img_url, '/') === 0 && strpos($img_url, '//') !== 0) {
                $img_url = rtrim($this->config['base_url'], '/') . $img_url;
            }
        }
        $product['images'] = array_unique($product['images']);
        
        // Очищаем название от лишних символов
        $product['name'] = preg_replace('/\s+/', ' ', $product['name']);
        
        return $product;
    }
    
    /**
     * Создание или обновление категории в WooCommerce
     */
    private function syncCategoryToWooCommerce($category_name) {
        // Проверяем, существует ли уже категория
        $params = ['search' => $category_name, 'per_page' => 5];
        $results = $this->makeWooCommerceRequest('/products/categories', $params, 'GET');
        
        $category_id = null;
        
        if (!empty($results)) {
            foreach ($results as $result) {
                if (strtolower($result['name']) === strtolower($category_name)) {
                    $category_id = $result['id'];
                    break;
                }
            }
        }
        
        // Если категория не найдена, создаем её
        if (!$category_id) {
            $cat_data = ['name' => $category_name];
            $result = $this->makeWooCommerceRequest('/products/categories', $cat_data, 'POST');
            
            if ($result && isset($result['id'])) {
                $category_id = $result['id'];
                echo "Создана категория: {$category_name} (ID: {$category_id})\n";
            }
        }
        
        return $category_id;
    }
    
    /**
     * Создание или обновление товара в WooCommerce
     */
    public function syncProductToWooCommerce($product) {
        if (empty($product['name'])) {
            echo "Пропущен товар без названия\n";
            return false;
        }
        
        // Проверяем, существует ли уже товар по SKU или названию
        $existing_product = $this->findExistingProduct($product);
        
        // Обрабатываем категории
        $category_ids = [];
        if (!empty($product['categories'])) {
            foreach ($product['categories'] as $cat) {
                if (!empty($cat['name'])) {
                    $cat_id = $this->syncCategoryToWooCommerce($cat['name']);
                    if ($cat_id) {
                        $category_ids[] = ['id' => $cat_id];
                    }
                }
            }
        }
        
        $wc_product = [
            'name' => $product['name'],
            'type' => 'simple',
            'regular_price' => !empty($product['price']) ? $product['price'] : '',
            'description' => !empty($product['description']) ? $product['description'] : $product['name'],
            'short_description' => substr(!empty($product['description']) ? $product['description'] : $product['name'], 0, 200),
            'status' => 'publish',
            'catalog_visibility' => 'visible',
        ];
        
        // Добавляем категории
        if (!empty($category_ids)) {
            $wc_product['categories'] = $category_ids;
        }
        
        // Добавляем изображения
        if (!empty($product['images'])) {
            $wc_product['images'] = [];
            foreach (array_slice($product['images'], 0, 5) as $image_url) {
                $wc_product['images'][] = ['src' => $image_url];
            }
        }
        
        // Добавляем SKU
        if (!empty($product['sku'])) {
            $wc_product['sku'] = $product['sku'];
        }
        
        // Добавляем атрибуты (характеристики)
        if (!empty($product['attributes'])) {
            $wc_product['attributes'] = [];
            
            // Группируем атрибуты по имени
            $grouped_attrs = [];
            foreach ($product['attributes'] as $attr) {
                if (!isset($grouped_attrs[$attr['name']])) {
                    $grouped_attrs[$attr['name']] = [];
                }
                $grouped_attrs[$attr['name']][] = $attr['value'];
            }
            
            foreach ($grouped_attrs as $attr_name => $values) {
                $wc_product['attributes'][] = [
                    'name' => $attr_name,
                    'position' => 0,
                    'visible' => true,
                    'variation' => false,
                    'options' => array_unique($values)
                ];
            }
        }
        
        $endpoint = $existing_product ? '/products/' . $existing_product['id'] : '/products';
        $method = $existing_product ? 'PUT' : 'POST';
        
        echo ($existing_product ? "Обновление" : "Создание") . " товара: {$product['name']}\n";
        
        $result = $this->makeWooCommerceRequest($endpoint, $wc_product, $method);
        
        if ($result) {
            $this->processed_products[] = $product['name'];
            return true;
        }
        
        return false;
    }
    
    /**
     * Поиск существующего товара в WooCommerce
     */
    private function findExistingProduct($product) {
        // Поиск по SKU
        if (!empty($product['sku'])) {
            $params = ['sku' => $product['sku'], 'per_page' => 1];
            $results = $this->makeWooCommerceRequest('/products', $params, 'GET');
            if (!empty($results[0])) {
                return $results[0];
            }
        }
        
        // Поиск по названию
        $params = ['search' => $product['name'], 'per_page' => 5];
        $results = $this->makeWooCommerceRequest('/products', $params, 'GET');
        
        if (!empty($results)) {
            foreach ($results as $result) {
                if (strtolower($result['name']) === strtolower($product['name'])) {
                    return $result;
                }
            }
        }
        
        return null;
    }
    
    /**
     * Выполнение запроса к WooCommerce API
     */
    private function makeWooCommerceRequest($endpoint, $data, $method = 'GET') {
        $url = rtrim($this->wc_config['api_url'], '/') . $endpoint;
        
        // Для GET запросов добавляем параметры в URL
        if ($method === 'GET' && !empty($data)) {
            $url .= '?' . http_build_query($data);
            $data = null;
        }
        
        $ch = curl_init();
        
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
        ];
        
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERPWD => $this->wc_config['consumer_key'] . ':' . $this->wc_config['consumer_secret'],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 30,
        ];
        
        if ($data && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            $options[CURLOPT_POSTFIELDS] = json_encode($data);
        }
        
        curl_setopt_array($ch, $options);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_close($ch);
        
        if ($error) {
            echo "Ошибка WooCommerce API: {$error}\n";
            return false;
        }
        
        $result = json_decode($response, true);
        
        if ($http_code >= 400) {
            echo "Ошибка WooCommerce API ({$http_code}): " . json_encode($result) . "\n";
            return false;
        }
        
        return $result;
    }
    
    /**
     * Основная функция запуска парсера
     */
    public function run() {
        echo "=== Запуск парсера pearlpool.ru ===\n";
        echo "Дата: " . date('Y-m-d H:i:s') . "\n\n";
        
        try {
            // Получаем категории
            $categories = $this->getCategories();
            
            $total_products = 0;
            $processed_urls = [];
            
            foreach ($categories as $category) {
                if ($total_products >= $this->config['max_products_per_run']) {
                    echo "Достигнут лимит товаров за один запуск ({$this->config['max_products_per_run']})\n";
                    break;
                }
                
                // Получаем URL товаров из категории
                $product_urls = $this->getProductUrlsFromCategory($category['url']);
                
                foreach ($product_urls as $url) {
                    if ($total_products >= $this->config['max_products_per_run']) {
                        break;
                    }
                    
                    // Пропускаем уже обработанные URL
                    if (in_array($url, $processed_urls)) {
                        continue;
                    }
                    $processed_urls[] = $url;
                    
                    try {
                        // Получаем детальную информацию о товаре
                        $product = $this->getProductDetails($url);
                        
                        if (!empty($product['name'])) {
                            // Добавляем информацию о категории если не была получена из BreadcrumbList
                            if (empty($product['categories'])) {
                                $product['categories'] = [['name' => $category['name']]];
                            }
                            
                            // Синхронизируем с WooCommerce
                            if ($this->syncProductToWooCommerce($product)) {
                                $total_products++;
                            }
                        }
                        
                        // Задержка между запросами
                        usleep($this->config['delay_between_requests'] * 1000000);
                    } catch (Exception $e) {
                        echo "Ошибка при обработке товара {$url}: " . $e->getMessage() . "\n";
                    }
                }
                
                // Небольшая задержка между категориями
                sleep(1);
            }
            
            echo "\n=== Завершение работы парсера ===\n";
            echo "Всего обработано товаров: {$total_products}\n";
            echo "Дата завершения: " . date('Y-m-d H:i:s') . "\n";
            
        } catch (Exception $e) {
            echo "Критическая ошибка: " . $e->getMessage() . "\n";
            return false;
        }
        
        return true;
    }
}

// Запуск парсера
try {
    $parser = new PearlPoolParser($parser_config, $config);
    $success = $parser->run();
    exit($success ? 0 : 1);
} catch (Exception $e) {
    echo "Фатальная ошибка: " . $e->getMessage() . "\n";
    exit(1);
}
