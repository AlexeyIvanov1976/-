#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Парсер товаров с сайта pearlpool.ru для импорта в WooCommerce через REST API.
Запускается по cron, собирает все товары и отправляет их в базу данных WooCommerce.
"""

import requests
from bs4 import BeautifulSoup
from urllib.parse import urljoin, urlparse, parse_qs
import time
import logging
from datetime import datetime
import re
import json

# Конфигурация
CONFIG = {
    'source_url': 'https://pearlpool.ru/',
    'api_url': 'https://9677018686.myjino.ru/basik/wp-json/wc/v3',
    'consumer_key': 'ck_351a30e18d90c3db83151bd7eda51c971eb7d510',
    'consumer_secret': 'cs_8ffea5d680b70c3b38123ef59071367bc1ba28a8',
    'delay_between_requests': 1,  # задержка между запросами в секундах
    'batch_size': 50,  # количество товаров для отправки за один раз
}

# Настройка логирования
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s',
    handlers=[
        logging.FileHandler('parser.log', encoding='utf-8'),
        logging.StreamHandler()
    ]
)
logger = logging.getLogger(__name__)


class PearlPoolParser:
    """Парсер для сайта pearlpool.ru"""
    
    def __init__(self, config):
        self.config = config
        self.session = requests.Session()
        self.session.headers.update({
            'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept-Language': 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
        })
        self.product_ids_found = set()
        self.products = []
        
    def get_soup(self, url):
        """Получить BeautifulSoup объект для URL"""
        try:
            response = self.session.get(url, timeout=30)
            response.raise_for_status()
            time.sleep(self.config['delay_between_requests'])
            return BeautifulSoup(response.text, 'lxml')
        except Exception as e:
            logger.error(f"Ошибка при получении {url}: {e}")
            return None
    
    def extract_product_id_from_url(self, url):
        """Извлечь product_id из URL"""
        parsed = urlparse(url)
        params = parse_qs(parsed.query)
        if 'product_id' in params:
            return params['product_id'][0]
        return None
    
    def find_all_product_links(self):
        """Найти все ссылки на товары на сайте"""
        logger.info("Поиск всех ссылок на товары...")
        product_urls = set()
        
        # Главная страница
        soup = self.get_soup(self.config['source_url'])
        if not soup:
            return product_urls
        
        # Ищем ссылки содержащие product_id
        for link in soup.find_all('a', href=True):
            href = link['href']
            product_id = self.extract_product_id_from_url(href)
            if product_id:
                full_url = urljoin(self.config['source_url'], href)
                product_urls.add(full_url)
                self.product_ids_found.add(product_id)
        
        # Также ищем ссылки на категории и проходим по ним
        category_urls = set()
        for link in soup.find_all('a', href=True):
            href = link['href']
            if 'route=product/category' in href or '/catalog' in href.lower():
                full_url = urljoin(self.config['source_url'], href)
                if full_url not in category_urls:
                    category_urls.add(full_url)
        
        # Проходим по категориям
        for cat_url in list(category_urls)[:20]:  # ограничим количество категорий для теста
            logger.info(f"Обработка категории: {cat_url}")
            cat_soup = self.get_soup(cat_url)
            if cat_soup:
                for link in cat_soup.find_all('a', href=True):
                    href = link['href']
                    product_id = self.extract_product_id_from_url(href)
                    if product_id:
                        full_url = urljoin(self.config['source_url'], href)
                        product_urls.add(full_url)
                        self.product_ids_found.add(product_id)
        
        logger.info(f"Найдено {len(product_urls)} уникальных ссылок на товары")
        return product_urls
    
    def parse_product(self, url):
        """Спарсить данные товара со страницы"""
        logger.debug(f"Парсинг товара: {url}")
        soup = self.get_soup(url)
        if not soup:
            return None
        
        try:
            # Название товара (из h1)
            title_tag = soup.find('h1', class_='breadcrumbs__title')
            name = title_tag.get_text(strip=True) if title_tag else "Без названия"
            
            # Артикул
            sku = None
            for span in soup.find_all('span', class_='sku__id'):
                text = span.get_text(strip=True)
                if 'Артикул:' in text:
                    sku = text.replace('Артикул:', '').strip()
                    break
            
            # Код товара
            product_code = None
            for span in soup.find_all('span', class_='sku__id'):
                text = span.get_text(strip=True)
                if 'Код:' in text:
                    product_code = text.replace('Код:', '').strip()
                    break
            
            # Цена
            price = None
            price_tag = soup.find('p', class_='sku__price')
            if price_tag:
                price_text = price_tag.get_text(strip=True)
                # Извлекаем числовое значение
                price_match = re.search(r'([\d\s]+\.?\d*)\s*₽', price_text)
                if price_match:
                    price_str = price_match.group(1).replace(' ', '')
                    try:
                        price = float(price_str)
                    except ValueError:
                        pass
            
            # Описание
            description = ""
            desc_div = soup.find('div', class_='sku__desc')
            if desc_div:
                # Ищем блок с описанием
                description_tag = desc_div.find('div', class_='description')
                if description_tag:
                    description = description_tag.get_text(' ', strip=True)[:5000]
            
            # Изображения
            images = []
            for img in soup.find_all('img'):
                src = img.get('src') or img.get('data-src')
                if src and ('image/cache' in src or 'image/catalog' in src):
                    full_src = urljoin(self.config['source_url'], src)
                    if full_src not in images:
                        images.append(full_src)
            
            # Категория
            categories = []
            breadcrumb = soup.find('ul', class_='breadcrumbs__menu')
            if breadcrumb:
                for li in breadcrumb.find_all('li')[1:-1]:  # пропускаем "Главная" и текущую страницу
                    cat_link = li.find('a')
                    if cat_link:
                        categories.append(cat_link.get_text(strip=True))
            
            # Производитель
            manufacturer = None
            brand_link = soup.find('a', class_='sku__brand-link')
            if brand_link:
                manufacturer = brand_link.get_text(strip=True)
            
            # Наличие (по умолчанию true, если есть кнопка купить)
            in_stock = bool(soup.find('button', id='button-cart'))
            
            product_data = {
                'name': name,
                'type': 'simple',
                'regular_price': str(price) if price else "",
                'price': str(price) if price else "",
                'sku': sku or product_code or "",
                'description': description,
                'short_description': name[:250],
                'categories': [{'name': cat} for cat in categories] if categories else [],
                'images': [{'src': img} for img in images[:5]] if images else [],
                'manage_stock': False,
                'stock_status': 'instock' if in_stock else 'outofstock',
                'status': 'publish',
                'meta_data': [
                    {'key': 'source_url', 'value': url},
                    {'key': 'parsed_at', 'value': datetime.now().isoformat()},
                    {'key': 'manufacturer', 'value': manufacturer} if manufacturer else {},
                ]
            }
            
            # Удаляем пустые meta_data
            product_data['meta_data'] = [m for m in product_data['meta_data'] if m]
            
            return product_data
            
        except Exception as e:
            logger.error(f"Ошибка при парсинге товара {url}: {e}")
            return None
    
    def upload_to_woocommerce(self, products):
        """Отправить товары в WooCommerce через REST API"""
        logger.info(f"Отправка {len(products)} товаров в WooCommerce...")
        
        auth = (self.config['consumer_key'], self.config['consumer_secret'])
        
        success_count = 0
        error_count = 0
        
        for i, product in enumerate(products):
            try:
                # Проверяем, существует ли товар с таким SKU
                existing = self.session.get(
                    f"{self.config['api_url']}/products",
                    auth=auth,
                    params={'sku': product['sku'], 'per_page': 1}
                )
                
                if existing.status_code == 200 and existing.json():
                    # Товар существует, обновляем его
                    existing_product = existing.json()[0]
                    product_id = existing_product['id']
                    
                    response = self.session.put(
                        f"{self.config['api_url']}/products/{product_id}",
                        auth=auth,
                        json=product
                    )
                    
                    if response.status_code in [200, 201]:
                        logger.info(f"Товар обновлен: {product['name']} (ID: {product_id})")
                        success_count += 1
                    else:
                        logger.error(f"Ошибка обновления товара {product['name']}: {response.text}")
                        error_count += 1
                else:
                    # Создаем новый товар
                    response = self.session.post(
                        f"{self.config['api_url']}/products",
                        auth=auth,
                        json=product
                    )
                    
                    if response.status_code in [200, 201]:
                        result = response.json()
                        logger.info(f"Товар создан: {product['name']} (ID: {result.get('id', 'N/A')})")
                        success_count += 1
                    else:
                        logger.error(f"Ошибка создания товара {product['name']}: {response.text}")
                        error_count += 1
                
                # Небольшая задержка между запросами к API
                time.sleep(0.5)
                
            except Exception as e:
                logger.error(f"Исключение при отправке товара {product.get('name', 'N/A')}: {e}")
                error_count += 1
        
        logger.info(f"Готово! Успешно: {success_count}, Ошибок: {error_count}")
        return success_count, error_count
    
    def run(self):
        """Основной метод запуска парсера"""
        logger.info("=" * 50)
        logger.info("Запуск парсера pearlpool.ru")
        logger.info("=" * 50)
        
        # Шаг 1: Найти все товары
        product_urls = self.find_all_product_links()
        
        if not product_urls:
            logger.warning("Товары не найдены!")
            return
        
        # Шаг 2: Спарсить каждый товар
        logger.info(f"Начинаем парсинг {len(product_urls)} товаров...")
        
        for i, url in enumerate(product_urls, 1):
            logger.info(f"[{i}/{len(product_urls)}] Обработка: {url}")
            product = self.parse_product(url)
            if product:
                self.products.append(product)
                logger.info(f"  -> Спаршено: {product['name'][:50]}...")
        
        logger.info(f"Всего спаршено товаров: {len(self.products)}")
        
        # Шаг 3: Отправить в WooCommerce
        if self.products:
            self.upload_to_woocommerce(self.products)
        
        logger.info("Парсер завершил работу")


def main():
    parser = PearlPoolParser(CONFIG)
    parser.run()


if __name__ == '__main__':
    main()
