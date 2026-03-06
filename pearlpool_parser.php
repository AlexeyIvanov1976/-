<?php
/**
 * Парсер товаров с сайта pearlpool.ru
 * Отправка данных в WooCommerce через REST API
 * 
 * Настройка cron: */15 * * * * /usr/bin/php /path/to/pearlpool_parser.php >> /path/to/parser.log 2>&1
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
    'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
    'delay_between_requests' => 1, // задержка между запросами в секундах
    'max_products_per_run' => 50, // максимум товаров за один запуск
];

class PearlPoolParser {
    private $config;
    private $wc_config;
    private $processed_products = [];
    
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
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
            'Connection: keep-alive',
        ];
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => array_merge($default_headers, $headers),
            CURLOPT_TIMEOUT => 30,
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
     * Получение списка категорий
     */
    public function getCategories() {
        echo "Получение списка категорий...\n";
        
        $html = $this->makeRequest($this->config['catalog_url']);
        
        $categories = [];
        $dom = new DOMDocument();
        @$dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($dom);
        
        // Ищем ссылки на категории в каталоге
        $category_links = $xpath->query('//a[contains(@href, "/catalog/")]');
        
        foreach ($category_links as $link) {
            $href = $link->getAttribute('href');
            $text = trim($link->textContent);
            
            if (!empty($text) && strpos($href, '/catalog/') !== false) {
                // Исключаем саму страницу каталога
                if ($href !== '/catalog/' && $href !== $this->config['catalog_url']) {
                    $full_url = (strpos($href, 'http') === 0) ? $href : rtrim($this->config['base_url'], '/') . '/' . ltrim($href, '/');
                    $categories[] = [
                        'name' => $text,
                        'url' => $full_url,
                        'slug' => basename(rtrim($href, '/'))
                    ];
                }
            }
        }
        
        // Удаляем дубликаты
        $categories = array_unique($categories, SORT_REGULAR);
        
        echo "Найдено категорий: " . count($categories) . "\n";
        return $categories;
    }
    
    /**
     * Получение списка товаров из категории
     */
    public function getProductsFromCategory($category_url) {
        echo "Парсинг категории: {$category_url}\n";
        
        $html = $this->makeRequest($category_url);
        $products = [];
        
        $dom = new DOMDocument();
        @$dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($dom);
        
        // Ищем карточки товаров (адаптировать под структуру сайта)
        $product_cards = $xpath->query('//div[contains(@class, "product") or contains(@class, "item")]');
        
        foreach ($product_cards as $card) {
            $product_data = $this->parseProductCard($card, $xpath);
            if ($product_data) {
                $products[] = $product_data;
            }
        }
        
        // Если не нашли стандартным способом, пробуем альтернативные селекторы
        if (empty($products)) {
            $product_links = $xpath->query('//a[contains(@href, "/product/") or contains(@class, "product-link")]');
            
            foreach ($product_links as $link) {
                $href = $link->getAttribute('href');
                if (!empty($href)) {
                    $full_url = (strpos($href, 'http') === 0) ? $href : rtrim($this->config['base_url'], '/') . '/' . ltrim($href, '/');
                    
                    try {
                        $product_data = $this->getProductDetails($full_url);
                        if ($product_data) {
                            $products[] = $product_data;
                        }
                    } catch (Exception $e) {
                        echo "Ошибка при парсинге товара {$href}: " . $e->getMessage() . "\n";
                    }
                }
            }
        }
        
        echo "Найдено товаров в категории: " . count($products) . "\n";
        return $products;
    }
    
    /**
     * Парсинг карточки товара
     */
    private function parseProductCard($card, $xpath) {
        $product = [];
        
        // Название
        $title_node = $xpath->query('.//h2/a | .//h3/a | .//a[@class="product-title"] | .//div[@class="name"]/a', $card)->item(0);
        if ($title_node) {
            $product['name'] = trim($title_node->textContent);
            $product['url'] = $title_node->getAttribute('href');
        } else {
            return null;
        }
        
        // Цена
        $price_node = $xpath->query('.//span[@class="price"] | .//div[@class="price"] | .//span[contains(@class, "cost")]', $card)->item(0);
        if ($price_node) {
            $price_text = trim($price_node->textContent);
            $product['price'] = preg_replace('/[^0-9.]/', '', $price_text);
        }
        
        // Изображение
        $img_node = $xpath->query('.//img', $card)->item(0);
        if ($img_node) {
            $product['image'] = $img_node->getAttribute('src');
        }
        
        return $product;
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
            'images' => [],
            'sku' => '',
            'categories' => []
        ];
        
        // Название
        $title_node = $xpath->query('//h1[contains(@class, "product-title") or contains(@class, "name")]')->item(0);
        if ($title_node) {
            $product['name'] = trim($title_node->textContent);
        }
        
        // Описание
        $desc_node = $xpath->query('//div[contains(@class, "description") or contains(@class, "detail")]')->item(0);
        if ($desc_node) {
            $product['description'] = trim($desc_node->textContent);
        }
        
        // Цена
        $price_node = $xpath->query('//span[@class="price"] | //div[@class="price"]')->item(0);
        if ($price_node) {
            $price_text = trim($price_node->textContent);
            $product['price'] = preg_replace('/[^0-9.]/', '', $price_text);
        }
        
        // Изображения
        $img_nodes = $xpath->query('//div[contains(@class, "gallery")]//img | //div[contains(@class, "images")]//img');
        foreach ($img_nodes as $img) {
            $src = $img->getAttribute('src');
            if (!empty($src)) {
                $product['images'][] = $src;
            }
        }
        
        // Артикул
        $sku_node = $xpath->query('//span[contains(@class, "sku") or contains(@class, "article")]')->item(0);
        if ($sku_node) {
            $product['sku'] = trim($sku_node->textContent);
        }
        
        return $product;
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
        
        $wc_product = [
            'name' => $product['name'],
            'type' => 'simple',
            'regular_price' => !empty($product['price']) ? $product['price'] : '',
            'description' => !empty($product['description']) ? $product['description'] : $product['name'],
            'short_description' => substr(!empty($product['description']) ? $product['description'] : $product['name'], 0, 200),
            'status' => 'publish',
            'catalog_visibility' => 'visible',
        ];
        
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
            
            foreach ($categories as $category) {
                if ($total_products >= $this->config['max_products_per_run']) {
                    echo "Достигнут лимит товаров за один запуск ({$this->config['max_products_per_run']})\n";
                    break;
                }
                
                $products = $this->getProductsFromCategory($category['url']);
                
                foreach ($products as $product) {
                    if ($total_products >= $this->config['max_products_per_run']) {
                        break;
                    }
                    
                    // Добавляем информацию о категории
                    $product['categories'] = [['name' => $category['name']]];
                    
                    // Синхронизируем с WooCommerce
                    if ($this->syncProductToWooCommerce($product)) {
                        $total_products++;
                    }
                    
                    // Задержка между запросами
                    sleep($this->config['delay_between_requests']);
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
