<?php
/**
 * Plugin Name: Universal Nofollow Pro
 * Description: Добавляет rel="nofollow" ко всем внешним ссылкам с админ-панелью и управлением списками
 * Version: 4.0
 * Author: WordPress Developer
 * License: GPL v2 or later
 * Text Domain: universal-nofollow
 * Domain Path: /languages
 */

// Предотвращаем прямой доступ
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ============================================
// КОНСТАНТЫ И КОНФИГУРАЦИЯ
// ============================================

define( 'UNIVERSAL_NOFOLLOW_VERSION', '4.0' );
define( 'UNIVERSAL_NOFOLLOW_DEBUG', defined( 'WP_DEBUG' ) && WP_DEBUG );
define( 'UNIVERSAL_NOFOLLOW_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'UNIVERSAL_NOFOLLOW_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// ============================================
// SEO-PLUGIN INTEGRATION
// ============================================

/**
 * Интеграция с популярными SEO-плагинами
 */
function universal_seo_integration() {
    // Yoast SEO
    if ( defined( 'WPSEO_VERSION' ) ) {
        add_filter( 'wpseo_external_links_output', '__return_false' );
    }
    // Rank Math
    if ( defined( 'RANK_MATH_VERSION' ) ) {
        add_filter( 'rank_math/frontend/external_links', '__return_false' );
    }
    // All in One SEO Pack
    if ( defined( 'AIOSEOP_VERSION' ) ) {
        add_filter( 'aioseo_external_links_output', '__return_false' );
    }
}
add_action( 'plugins_loaded', 'universal_seo_integration' );

// ============================================
// STATISTICS HELPERS (с кешированием)
// ============================================

/**
 * Буфер для статистики (обновляется один раз в конце)
 */
$GLOBALS['universal_nofollow_stats_buffer'] = array(
    'processed'    => 0,
    'added'        => 0,
    'excluded'     => 0,
    'geo_excluded' => 0,
    'error'        => 0,
);

/**
 * Увеличивает счетчик статистики (в буфере)
 * 
 * @param string $key Ключ счетчика
 */
function universal_increment_stat( $key ) {
    if ( isset( $GLOBALS['universal_nofollow_stats_buffer'][ $key ] ) ) {
        $GLOBALS['universal_nofollow_stats_buffer'][ $key ]++;
    }
}

/**
 * Получает статистику (с кешированием)
 * 
 * @return array Массив статистики
 */
function universal_get_stats() {
    // Проверяем кеш (обновляется каждый час)
    $cached = get_transient( 'universal_nofollow_stats_cache' );
    if ( $cached !== false ) {
        return $cached;
    }
    
    $stats = get_option( 'universal_nofollow_stats', array(
        'processed'    => 0,
        'added'        => 0,
        'excluded'     => 0,
        'geo_excluded' => 0,
        'error'        => 0,
    ) );
    
    // Кешируем на 1 час
    set_transient( 'universal_nofollow_stats_cache', $stats, HOUR_IN_SECONDS );
    
    return $stats;
}

/**
 * Сохраняет статистику из буфера
 */
function universal_save_stats_buffer() {
    $buffer = $GLOBALS['universal_nofollow_stats_buffer'];
    
    // Если буфер пуст — не обновляем
    if ( array_sum( $buffer ) === 0 ) {
        return;
    }
    
    $stats = get_option( 'universal_nofollow_stats', array(
        'processed'    => 0,
        'added'        => 0,
        'excluded'     => 0,
        'geo_excluded' => 0,
        'error'        => 0,
    ) );
    
    // Добавляем значения из буфера
    foreach ( $buffer as $key => $count ) {
        $stats[ $key ] = ( $stats[ $key ] ?? 0 ) + $count;
    }
    
    update_option( 'universal_nofollow_stats', $stats );
    
    // Очищаем кеш
    delete_transient( 'universal_nofollow_stats_cache' );
}

// Сохраняем статистику при завершении страницы
add_action( 'shutdown', 'universal_save_stats_buffer', 999 );

// ============================================
// GEO-TARGETING HELPERS (с кешированием)
// ============================================

/**
 * Получает страну посетителя (с кешированием)
 * 
 * @return string|null Код страны (ISO-2) или null
 */
function universal_get_visitor_country() {
    static $country = null;
    if ( $country !== null ) {
        return $country;
    }
    
    // Получаем IP адрес
    $ip = universal_get_client_ip();
    if ( ! $ip ) {
        return null;
    }
    
    // Проверяем кеш (24 часа)
    $cache_key = 'universal_visitor_country_' . md5( $ip );
    $cached = get_transient( $cache_key );
    if ( $cached !== false ) {
        $country = $cached;
        return $country;
    }
    
    // Получаем страну через API
    $response = wp_remote_get( 'https://ip-api.com/json/' . $ip, array(
        'timeout'   => 2,
        'sslverify' => false,
    ) );
    
    if ( is_wp_error( $response ) ) {
        universal_log_error( 'Failed to get country from IP API: ' . $response->get_error_message() );
        return null;
    }
    
    $data = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( ! is_array( $data ) || empty( $data['countryCode'] ) ) {
        universal_log_error( 'Invalid response from IP API' );
        return null;
    }
    
    $country = strtoupper( $data['countryCode'] );
    
    // Кешируем на 24 часа
    set_transient( $cache_key, $country, DAY_IN_SECONDS );
    
    universal_log( 'Country detected: ' . $country . ' for IP: ' . $ip );
    
    return $country;
}

/**
 * Получает IP адрес клиента
 * 
 * @return string|null IP адрес или null
 */
function universal_get_client_ip() {
    // Проверяем различные источники IP
    if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
        // Cloudflare
        return sanitize_text_field( $_SERVER['HTTP_CF_CONNECTING_IP'] );
    } elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
        // Proxy
        $ips = explode( ',', sanitize_text_field( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
        return trim( $ips[0] );
    } elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
        // Прямое подключение
        return sanitize_text_field( $_SERVER['REMOTE_ADDR'] );
    }
    
    return null;
}

/**
 * Проверяет, исключена ли страна
 * 
 * @param string $url URL для проверки (не используется, но оставлен для совместимости)
 * @return bool True если страна исключена
 */
function universal_is_geo_excluded( $url = '' ) {
    $settings = get_option( 'universal_nofollow_settings', array() );
    $blocked = isset( $settings['blocked_countries'] ) ? $settings['blocked_countries'] : array();
    
    if ( empty( $blocked ) ) {
        return false;
    }
    
    $visitor_country = universal_get_visitor_country();
    if ( ! $visitor_country ) {
        return false;
    }
    
    return in_array( $visitor_country, $blocked, true );
}

// ============================================
// СПИСОК СТРАН (автоматическое обновление)
// ============================================

/**
 * Получает актуальный список стран ISO-2
 * 
 * @return array Массив стран (код => название)
 */
function universal_get_countries_list() {
    // Проверяем кеш (обновляется раз в неделю)
    $cached = get_transient( 'universal_countries_list' );
    if ( $cached !== false ) {
        return $cached;
    }
    
    // Пытаемся получить список из API
    $countries = universal_fetch_countries_from_api();
    
    // Если API не доступен, используем встроенный список
    if ( empty( $countries ) ) {
        $countries = universal_get_default_countries();
    }
    
    // Кешируем на 7 дней
    set_transient( 'universal_countries_list', $countries, 7 * DAY_IN_SECONDS );
    
    return $countries;
}

/**
 * Получает список стран из открытого API
 * 
 * @return array Массив стран или пустой массив
 */
function universal_fetch_countries_from_api() {
    $response = wp_remote_get( 'https://restcountries.com/v3.1/all', array(
        'timeout'   => 5,
        'sslverify' => false,
    ) );
    
    if ( is_wp_error( $response ) ) {
        universal_log_error( 'Failed to fetch countries from API: ' . $response->get_error_message() );
        return array();
    }
    
    $data = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( ! is_array( $data ) ) {
        universal_log_error( 'Invalid response from countries API' );
        return array();
    }
    
    $countries = array();
    foreach ( $data as $country ) {
        if ( isset( $country['cca2'] ) && isset( $country['name']['common'] ) ) {
            $countries[ $country['cca2'] ] = $country['name']['common'];
        }
    }
    
    // Сортируем по названию
    asort( $countries );
    
    universal_log( 'Fetched ' . count( $countries ) . ' countries from API' );
    
    return $countries;
}

/**
 * Встроенный список стран (на случай если API недоступен)
 * 
 * @return array Массив стран
 */
function universal_get_default_countries() {
    return array(
        'RU' => 'Россия',
        'US' => 'США',
        'GB' => 'Великобритания',
        'DE' => 'Германия',
        'FR' => 'Франция',
        'IT' => 'Италия',
        'ES' => 'Испания',
        'CN' => 'Китай',
        'JP' => 'Япония',
        'IN' => 'Индия',
        'BR' => 'Бразилия',
        'CA' => 'Канада',
        'AU' => 'Австралия',
        'MX' => 'Мексика',
        'KR' => 'Южная Корея',
        'NL' => 'Нидерланды',
        'SE' => 'Швеция',
        'CH' => 'Швейцария',
        'PL' => 'Польша',
        'UA' => 'Украина',
        'TR' => 'Турция',
        'ZA' => 'ЮАР',
        'SG' => 'Сингапур',
        'HK' => 'Гонконг',
        'NZ' => 'Новая Зеландия',
    );
}

// ============================================
// ОСНОВНАЯ ФУНКЦИЯ ОБРАБОТКИ ССЫЛОК
// ============================================

/**
 * Добавляет rel="nofollow" ко всем внешним ссылкам
 * 
 * @param mixed $content Контент для обработки
 * @return mixed Обработанный контент
 */
function universal_add_nofollow_to_links( $content ) {
    if ( empty( $content ) || ! is_string( $content ) ) {
        return $content;
    }
    
    // Проверяем, нужно ли обрабатывать текущий тип записи
    if ( ! universal_should_process_current_post_type() ) {
        return $content;
    }
    
    // Получаем домен сайта (кешируем для производительности)
    static $home_domain = null;
    if ( $home_domain === null ) {
        $home_url = home_url();
        $home_domain = wp_parse_url( $home_url, PHP_URL_HOST );
    }
    
    // Улучшенное регулярное выражение для захвата полного тега <a>
    $pattern = '/<a\s+[^>]*?href\s*=\s*["\']([^"\']+)["\'][^>]*?>/i';
    
    $content = preg_replace_callback( $pattern, function( $matches ) use ( $home_domain ) {
        universal_increment_stat( 'processed' );
        
        $full_link = $matches[0];
        $url = $matches[1];
        
        // Проверяем, является ли ссылка внешней
        if ( ! universal_is_external( $url, $home_domain ) ) {
            universal_increment_stat( 'excluded' );
            return $full_link;
        }
        
        // Проверяем, в списке ли ссылка для блокировки
        if ( universal_is_link_in_blocklist( $url ) ) {
            universal_log( 'Found link in blocklist: ' . $url );
            return universal_add_nofollow_to_link( $full_link );
        }
        
        // Проверяем, исключена ли ссылка
        if ( universal_is_link_excluded( $url ) ) {
            universal_increment_stat( 'excluded' );
            return $full_link;
        }
        
        // Проверяем, это ли Яндекс реклама (исключаем из обработки)
        if ( universal_is_yandex_ads( $url ) ) {
            universal_increment_stat( 'excluded' );
            universal_log( 'Yandex ads link excluded: ' . $url );
            return $full_link;
        }
        
        // Проверяем гео-таргетинг
        if ( universal_is_geo_excluded( $url ) ) {
            universal_increment_stat( 'geo_excluded' );
            return $full_link;
        }
        
        universal_log( 'Found external link: ' . $url );
        
        return universal_add_nofollow_to_link( $full_link );
    }, $content );
    
    // Проверяем на ошибки регулярных выражений
    if ( preg_last_error() !== PREG_NO_ERROR ) {
        universal_log_error( 'Regex error: ' . preg_last_error() );
        universal_increment_stat( 'error' );
        return $content;
    }
    
    return $content;
}

/**
 * Добавляет rel="nofollow" к одной ссылке
 * 
 * @param string $full_link Полный HTML тег ссылки
 * @return string Обновленный тег ссылки
 */
function universal_add_nofollow_to_link( $full_link ) {
    // Проверяем, есть ли уже rel атрибут
    if ( preg_match( '/rel\s*=\s*["\']([^"\']*)["\']/', $full_link, $rel_match ) ) {
        $existing_rel = $rel_match[1];
        
        // Если nofollow уже есть — ничего не делаем
        if ( preg_match( '/\bnofollow\b/i', $existing_rel ) ) {
            return $full_link;
        }
        
        // Добавляем nofollow к существующему rel
        $new_rel = trim( $existing_rel . ' nofollow' );
        $full_link = preg_replace( 
            '/rel\s*=\s*["\'][^"\']*["\']/', 
            'rel="' . esc_attr( $new_rel ) . '"', 
            $full_link 
        );
    } else {
        // Добавляем новый rel атрибут перед закрывающей скобкой тега
        $full_link = preg_replace( '/>$/', ' rel="nofollow">', $full_link );
    }
    
    universal_log( 'Added nofollow to external link' );
    universal_increment_stat( 'added' );
    
    return $full_link;
}

// ============================================
// ПРОВЕРКА НАСТРОЕК И УСЛОВИЙ
// ============================================

/**
 * Проверяет, нужно ли обрабатывать текущий тип записи
 * 
 * @return bool True если нужно обрабатывать
 */
function universal_should_process_current_post_type() {
    $settings = get_option( 'universal_nofollow_settings', array() );
    $enabled_post_types = isset( $settings['post_types'] ) ? $settings['post_types'] : array();
    
    // Если нет выбранных типов — обрабатываем все
    if ( empty( $enabled_post_types ) ) {
        return true;
    }
    
    // Проверяем текущий тип записи
    if ( is_singular() ) {
        $current_post_type = get_post_type();
        return in_array( $current_post_type, $enabled_post_types, true );
    }
    
    // Для главной страницы
    if ( is_home() || is_front_page() ) {
        return in_array( 'home', $enabled_post_types, true );
    }
    
    // Для архивов
    if ( is_archive() ) {
        return in_array( 'archive', $enabled_post_types, true );
    }
    
    return true;
}

/**
 * Проверяет, является ли ссылка внешней
 * 
 * @param string $url URL для проверки
 * @param string $home_domain Домен сайта
 * @return bool True если ссылка внешняя
 */
function universal_is_external( $url, $home_domain ) {
    // Пропускаем якоры
    if ( strpos( $url, '#' ) === 0 ) {
        return false;
    }
    
    // Пропускаем относительные ссылки
    if ( strpos( $url, '/' ) === 0 && strpos( $url, '//' ) !== 0 ) {
        return false;
    }
    
    // Пропускаем mailto, tel и другие протоколы
    if ( preg_match( '/^(mailto|tel|javascript|data|ftp):/i', $url ) ) {
        return false;
    }
    
    // Проверяем, что это HTTP(S) ссылка
    if ( strpos( $url, 'http' ) !== 0 && strpos( $url, '//' ) !== 0 ) {
        return false;
    }
    
    // Получаем домен ссылки
    $link_domain = wp_parse_url( $url, PHP_URL_HOST );
    
    // Проверяем, является ли ссылка внешней
    if ( ! $link_domain || $link_domain === $home_domain ) {
        return false;
    }
    
    return true;
}

/**
 * Проверяет, это ли Яндекс реклама (исключаем из обработки)
 * 
 * @param string $url URL для проверки
 * @return bool True если это Яндекс реклама
 */
function universal_is_yandex_ads( $url ) {
    // Яндекс реклама (RTB, Direct)
    if ( strpos( $url, 'yandex.ru/clck' ) !== false ) {
        return true;
    }
    
    // Яндекс Маркет (если включена опция)
    $settings = get_option( 'universal_nofollow_settings', array() );
    if ( isset( $settings['exclude_yandex_market'] ) && $settings['exclude_yandex_market'] === '1' ) {
        if ( strpos( $url, 'market.yandex.ru' ) !== false ) {
            return true;
        }
    }
    
    return false;
}

/**
 * Получает исключенные ссылки
 * 
 * @return array Массив исключенных ссылок
 */
function universal_get_excluded_links() {
    $raw = get_option( 'universal_nofollow_excluded_links', '' );
    if ( empty( $raw ) ) {
        return array();
    }
    
    // Разбиваем по строкам и очищаем
    $links = array_map( 'trim', explode( "\n", $raw ) );
    $links = array_filter( $links );
    
    return $links;
}

/**
 * Проверяет, исключена ли ссылка
 * 
 * @param string $url URL для проверки
 * @return bool True если исключена
 */
function universal_is_link_excluded( $url ) {
    $excluded_links = universal_get_excluded_links();
    
    foreach ( $excluded_links as $excluded ) {
        // Полное совпадение
        if ( $url === $excluded ) {
            universal_log( 'Link excluded (full match): ' . $url );
            return true;
        }
        
        // Частичное совпадение
        if ( strpos( $url, $excluded ) !== false ) {
            universal_log( 'Link excluded (partial match): ' . $url );
            return true;
        }
    }
    
    return false;
}

/**
 * Получает список ссылок для блокировки
 * 
 * @return array Массив ссылок для блокировки
 */
function universal_get_blocklist_links() {
    $raw = get_option( 'universal_nofollow_blocklist_links', '' );
    if ( empty( $raw ) ) {
        return array();
    }
    
    // Разбиваем по строкам и очищаем
    $links = array_map( 'trim', explode( "\n", $raw ) );
    $links = array_filter( $links );
    
    return $links;
}

/**
 * Проверяет, в списке ли ссылка для блокировки
 * 
 * @param string $url URL для проверки
 * @return bool True если в списке
 */
function universal_is_link_in_blocklist( $url ) {
    $blocklist = universal_get_blocklist_links();
    
    foreach ( $blocklist as $blocked ) {
        // Полное совпадение
        if ( $url === $blocked ) {
            universal_log( 'Link found in blocklist (full match): ' . $url );
            return true;
        }
        
        // Частичное совпадение
        if ( strpos( $url, $blocked ) !== false ) {
            universal_log( 'Link found in blocklist (partial match): ' . $url );
            return true;
        }
    }
    
    return false;
}

// ============================================
// ЛОГИРОВАНИЕ
// ============================================

/**
 * Логирование информации (только в режиме отладки)
 * 
 * @param string $message Сообщение для логирования
 */
function universal_log( $message ) {
    if ( ! UNIVERSAL_NOFOLLOW_DEBUG ) {
        return;
    }
    
    if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
        error_log( '[Universal Nofollow] ' . $message );
    }
}

/**
 * Логирование ошибок
 * 
 * @param string $message Сообщение об ошибке
 */
function universal_log_error( $message ) {
    if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
        error_log( '[Universal Nofollow ERROR] ' . $message );
    }
}

// ============================================
// ФИЛЬТРЫ ДЛЯ КОНТЕНТА (БЕЗ БУФЕРИЗАЦИИ)
// ============================================

// Контент постов и страниц
add_filter( 'the_content', 'universal_add_nofollow_to_links', 999 );

// Виджеты
add_filter( 'widget_text_content', 'universal_add_nofollow_to_links', 999 );
add_filter( 'widget_text', 'universal_add_nofollow_to_links', 999 );

// Выдержки
add_filter( 'the_excerpt', 'universal_add_nofollow_to_links', 999 );

// Комментарии
add_filter( 'comment_text', 'universal_add_nofollow_to_links', 999 );

// Описания терминов (категории, теги)
add_filter( 'term_description', 'universal_add_nofollow_to_links', 999 );

// Описание автора
add_filter( 'get_the_author_description', 'universal_add_nofollow_to_links', 999 );

// ============================================
// ПОДДЕРЖКА ПОПУЛЯРНЫХ ПЛАГИНОВ
// ============================================

// WooCommerce
add_filter( 'woocommerce_product_description', 'universal_add_nofollow_to_links', 999 );
add_filter( 'woocommerce_short_description', 'universal_add_nofollow_to_links', 999 );
add_filter( 'woocommerce_product_tabs', function( $tabs ) {
    foreach ( $tabs as $key => $tab ) {
        if ( isset( $tab['content'] ) ) {
            $tabs[ $key ]['content'] = universal_add_nofollow_to_links( $tab['content'] );
        }
    }
    return $tabs;
}, 999 );

// ACF (Advanced Custom Fields)
if ( class_exists( 'ACF' ) ) {
    add_filter( 'acf/format_value', function( $value, $post_id, $field ) {
        if ( is_string( $value ) ) {
            return universal_add_nofollow_to_links( $value );
        }
        return $value;
    }, 999, 3 );
}

// Elementor
add_filter( 'elementor/frontend/the_content', 'universal_add_nofollow_to_links', 999 );
add_filter( 'elementor_pro/documents/print_elements_content', 'universal_add_nofollow_to_links', 999 );

// Beaver Builder
add_filter( 'fl_builder_render_content', 'universal_add_nofollow_to_links', 999 );

// Divi
add_filter( 'et_pb_get_processed_content', 'universal_add_nofollow_to_links', 999 );

// Gutenberg блоки
add_filter( 'render_block', function( $block_content, $block ) {
    return universal_add_nofollow_to_links( $block_content );
}, 999, 2 );

// Oxygen Builder
add_filter( 'oxygen_vsb_output', 'universal_add_nofollow_to_links', 999 );

// Brizy
add_filter( 'brizy_content', 'universal_add_nofollow_to_links', 999 );

// ============================================
// АДМИН-ПАНЕЛЬ
// ============================================

/**
 * Регистрирует меню админ-панели
 */
function universal_nofollow_add_admin_menu() {
    add_options_page(
        'Universal Nofollow Pro',
        'Nofollow Pro',
        'manage_options',
        'universal-nofollow-settings',
        'universal_nofollow_settings_page'
    );
}
add_action( 'admin_menu', 'universal_nofollow_add_admin_menu' );

/**
 * Страница настроек плагина
 */
function universal_nofollow_settings_page() {
    // Проверяем права доступа
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'У вас нет прав доступа к этой странице.' );
    }
    
    // Обрабатываем сохранение настроек
    if ( isset( $_POST['universal_nofollow_nonce'] ) && wp_verify_nonce( $_POST['universal_nofollow_nonce'], 'universal_nofollow_save' ) ) {
        $settings = array();
        
        // Сохраняем выбранные типы записей
        if ( isset( $_POST['post_types'] ) && is_array( $_POST['post_types'] ) ) {
            $settings['post_types'] = array_map( 'sanitize_text_field', $_POST['post_types'] );
        }
        
        // Сохраняем чекбокс блокировки соцсетей
        $settings['block_social'] = isset( $_POST['block_social'] ) ? '1' : '0';
        
        // Сохраняем чекбокс исключения Яндекс Маркета
        $settings['exclude_yandex_market'] = isset( $_POST['exclude_yandex_market'] ) ? '1' : '0';
        
        // Сохраняем исключенные ссылки
        if ( isset( $_POST['excluded_links'] ) ) {
            $clean_links = sanitize_textarea_field( $_POST['excluded_links'] );
            $settings['excluded_links'] = $clean_links;
            update_option( 'universal_nofollow_excluded_links', $clean_links );
        }
        
        // Сохраняем заблокированные страны (с проверкой)
        if ( isset( $_POST['blocked_countries'] ) && is_array( $_POST['blocked_countries'] ) ) {
            $settings['blocked_countries'] = array_map( 'sanitize_text_field', $_POST['blocked_countries'] );
        } else {
            $settings['blocked_countries'] = array();
        }
        
        update_option( 'universal_nofollow_settings', $settings );
        
        // Очищаем кеш статистики
        delete_transient( 'universal_nofollow_stats_cache' );
        
        echo '<div class="notice notice-success"><p>✓ Настройки сохранены успешно!</p></div>';
    }
    
    // Обрабатываем загрузку CSV для исключений
    if ( isset( $_POST['universal_nofollow_csv_nonce'] ) && wp_verify_nonce( $_POST['universal_nofollow_csv_nonce'], 'universal_nofollow_csv' ) ) {
        if ( ! empty( $_FILES['csv_file'] ) ) {
            $result = universal_import_csv( $_FILES['csv_file'], 'excluded' );
            if ( $result['success'] ) {
                echo '<div class="notice notice-success"><p>✓ ' . esc_html( $result['message'] ) . '</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>✗ ' . esc_html( $result['message'] ) . '</p></div>';
            }
        }
    }
    
    // Обрабатываем загрузку CSV для блокировки
    if ( isset( $_POST['universal_nofollow_blocklist_csv_nonce'] ) && wp_verify_nonce( $_POST['universal_nofollow_blocklist_csv_nonce'], 'universal_nofollow_blocklist_csv' ) ) {
        if ( ! empty( $_FILES['blocklist_csv_file'] ) ) {
            $result = universal_import_csv( $_FILES['blocklist_csv_file'], 'blocklist' );
            if ( $result['success'] ) {
                echo '<div class="notice notice-success"><p>✓ ' . esc_html( $result['message'] ) . '</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>✗ ' . esc_html( $result['message'] ) . '</p></div>';
            }
        }
    }
    
    // Обрабатываем экспорт CSV для исключений
    if ( isset( $_GET['action'] ) && $_GET['action'] === 'export_csv' && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'universal_nofollow_export' ) ) {
        universal_export_csv( 'excluded' );
        exit;
    }
    
    // Обрабатываем экспорт CSV для блокировки
    if ( isset( $_GET['action'] ) && $_GET['action'] === 'export_blocklist_csv' && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'universal_nofollow_export_blocklist' ) ) {
        universal_export_csv( 'blocklist' );
        exit;
    }
    
    // Обрабатываем очистку статистики
    if ( isset( $_POST['universal_nofollow_reset_stats_nonce'] ) && wp_verify_nonce( $_POST['universal_nofollow_reset_stats_nonce'], 'universal_nofollow_reset_stats' ) ) {
        delete_option( 'universal_nofollow_stats' );
        delete_transient( 'universal_nofollow_stats_cache' );
        echo '<div class="notice notice-success"><p>✓ Статистика очищена!</p></div>';
    }
    
    // Получаем текущие настройки
    $settings = get_option( 'universal_nofollow_settings', array() );
    $enabled_post_types = isset( $settings['post_types'] ) ? $settings['post_types'] : array();
    $block_social = isset( $settings['block_social'] ) ? $settings['block_social'] : '0';
    $exclude_yandex_market = isset( $settings['exclude_yandex_market'] ) ? $settings['exclude_yandex_market'] : '0';
    $blocked_countries = isset( $settings['blocked_countries'] ) ? $settings['blocked_countries'] : array();
    $excluded_links = isset( $settings['excluded_links'] ) ? $settings['excluded_links'] : '';
    
    // Получаем все типы записей
    $post_types = get_post_types( array( 'public' => true ), 'objects' );
    
    // Получаем список стран
    $countries = universal_get_countries_list();
    
    // Получаем статистику
    $stats = universal_get_stats();
    
    // Получаем списки ссылок
    $excluded_list = universal_get_excluded_links();
    $blocklist = universal_get_blocklist_links();
    
    ?>
    <div class="wrap">
        <h1>🔗 Universal Nofollow Pro</h1>
        <p style="font-size: 14px; color: #666;">Версия <?php echo esc_html( UNIVERSAL_NOFOLLOW_VERSION ); ?> | Автоматическое добавление rel="nofollow" ко всем внешним ссылкам</p>
        
        <h2 class="nav-tab-wrapper">
            <a href="#" class="nav-tab universal-tab nav-tab-active" data-target="universal-panel-general">⚙️ Основные настройки</a>
            <a href="#" class="nav-tab universal-tab" data-target="universal-panel-exclusions">🚫 Исключения</a>
            <a href="#" class="nav-tab universal-tab" data-target="universal-panel-blocklist">✅ Список блокировки</a>
            <a href="#" class="nav-tab universal-tab" data-target="universal-panel-stats">📊 Статистика</a>
        </h2>
        
        <!-- ====================== ОСНОВНЫЕ НАСТРОЙКИ ====================== -->
        <div id="universal-panel-general" class="universal-panel" style="display:block;">
            <form method="post" action="">
                <?php wp_nonce_field( 'universal_nofollow_save', 'universal_nofollow_nonce' ); ?>
                
                <table class="form-table">
                    <!-- ТИПЫ ЗАПИСЕЙ -->
                    <tr>
                        <th scope="row">
                            <label for="post_types">📄 Типы записей для обработки:</label>
                        </th>
                        <td>
                            <fieldset>
                                <legend class="screen-reader-text">Типы записей</legend>
                                
                                <label style="display: block; margin-bottom: 8px;">
                                    <input type="checkbox" name="post_types[]" value="home" 
                                        <?php checked( in_array( 'home', $enabled_post_types, true ) ); ?> />
                                    <strong>Главная страница</strong>
                                </label>
                                
                                <label style="display: block; margin-bottom: 8px;">
                                    <input type="checkbox" name="post_types[]" value="archive" 
                                        <?php checked( in_array( 'archive', $enabled_post_types, true ) ); ?> />
                                    <strong>Архивы</strong> (категории, теги, авторы)
                                </label>
                                
                                <?php foreach ( $post_types as $post_type ) : ?>
                                    <label style="display: block; margin-bottom: 8px;">
                                        <input type="checkbox" name="post_types[]" value="<?php echo esc_attr( $post_type->name ); ?>" 
                                            <?php checked( in_array( $post_type->name, $enabled_post_types, true ) ); ?> />
                                        <strong><?php echo esc_html( $post_type->label ); ?></strong>
                                    </label>
                                <?php endforeach; ?>
                                
                                <p class="description">Выберите типы записей, на которых нужно блокировать индексацию ссылок. Если ничего не выбрано, плагин будет работать везде.</p>
                            </fieldset>
                        </td>
                    </tr>
                    
                    <!-- БЛОКИРОВКА СОЦСЕТЕЙ -->
                    <tr>
                        <th scope="row">
                            <label for="block_social">📱 Блокировка ссылок соцсетей:</label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" name="block_social" value="1" 
                                    <?php checked( $block_social, '1' ); ?> />
                                Добавлять rel="nofollow" к ссылкам на социальные сети
                            </label>
                            <p class="description">Включите эту опцию, чтобы добавлять rel="nofollow" к ссылкам на Facebook, Twitter, Instagram, YouTube, TikTok и другие социальные сети.</p>
                        </td>
                    </tr>
                    
                    <!-- ИСКЛЮЧЕНИЕ ЯНДЕКС МАРКЕТА -->
                    <tr>
                        <th scope="row">
                            <label for="exclude_yandex_market">🛍️ Исключить Яндекс Маркет:</label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" name="exclude_yandex_market" value="1" 
                                    <?php checked( $exclude_yandex_market, '1' ); ?> />
                                Не добавлять rel="nofollow" к ссылкам Яндекс Маркета
                            </label>
                            <p class="description">По умолчанию плагин добавляет rel="nofollow" ко всем внешним ссылкам, включая Яндекс Маркет. Включите эту опцию, если хотите исключить ссылки market.yandex.ru из обработки.</p>
                        </td>
                    </tr>
                    
                    <!-- БЛОКИРОВКА ПО СТРАНАМ -->
                    <tr>
                        <th scope="row">
                            <label for="blocked_countries">🌍 Блокировать страны:</label>
                        </th>
                        <td>
                            <select name="blocked_countries[]" id="blocked_countries" multiple style="width: 100%; max-width: 400px; height: 200px;">
                                <?php foreach ( $countries as $code => $name ) : ?>
                                    <option value="<?php echo esc_attr( $code ); ?>" 
                                        <?php selected( in_array( $code, $blocked_countries, true ) ); ?>>
                                        <?php echo esc_html( $name . ' (' . $code . ')' ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">
                                Выберите страны, для которых ссылки НЕ будут получать rel="nofollow".<br>
                                (полезно, если ваш сервис ориентирован только на определённый регион)<br>
                                <strong>Совет:</strong> Используйте Ctrl+Click для выбора нескольких стран.
                            </p>
                        </td>
                    </tr>
                    
                    <!-- ИСКЛЮЧЕННЫЕ ССЫЛКИ -->
                    <tr>
                        <th scope="row">
                            <label for="excluded_links">🚫 Исключенные ссылки:</label>
                        </th>
                        <td>
                            <textarea name="excluded_links" id="excluded_links" rows="10" cols="50" class="large-text code"><?php echo esc_textarea( $excluded_links ); ?></textarea>
                            <p class="description">
                                Введите ссылки, которые нужно исключить из обработки. Одна ссылка на строку.<br />
                                Поддерживается как полное совпадение, так и частичное (например, можно указать только домен).<br />
                                <strong>Примеры:</strong><br />
                                - Полное совпадение: <code>https://example.com/page</code><br />
                                - Частичное совпадение: <code>example.com</code><br />
                                - Только домен: <code>partner-site.ru</code>
                            </p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button( 'Сохранить настройки', 'primary', 'submit', true ); ?>
            </form>
        </div>
        
        <!-- ====================== ИСКЛЮЧЕНИЯ (CSV) ====================== -->
        <div id="universal-panel-exclusions" class="universal-panel" style="display: none;">
            <h2>🚫 Управление исключёнными ссылками</h2>
            
            <div style="background: #f9f9f9; padding: 20px; border-radius: 5px; margin-bottom: 20px;">
                <h3>📥 Загрузить CSV файл</h3>
                <form method="post" enctype="multipart/form-data">
                    <?php wp_nonce_field( 'universal_nofollow_csv', 'universal_nofollow_csv_nonce' ); ?>
                    <input type="file" name="csv_file" accept=".csv" required />
                    <?php submit_button( 'Загрузить', 'secondary', 'submit', false ); ?>
                    <p class="description">
                        Загрузите CSV файл с исключенными ссылками (одна ссылка на строку).<br>
                        <strong>Формат:</strong> Первая колонка должна содержать URL.
                    </p>
                </form>
            </div>
            
            <div style="background: #f9f9f9; padding: 20px; border-radius: 5px; margin-bottom: 20px;">
                <h3>📋 Текущие исключения</h3>
                <p>Всего исключений: <strong><?php echo count( $excluded_list ); ?></strong></p>
                
                <?php if ( ! empty( $excluded_list ) ) : ?>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th>Исключённый URL / часть URL</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $excluded_list as $link ) : ?>
                                <tr>
                                    <td><?php echo esc_html( $link ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else : ?>
                    <p style="color: #999;">Нет исключенных ссылок</p>
                <?php endif; ?>
            </div>
            
            <div style="background: #f9f9f9; padding: 20px; border-radius: 5px;">
                <h3>📤 Экспортировать CSV</h3>
                <p>
                    <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'export_csv' ), admin_url( 'options-general.php?page=universal-nofollow-settings' ) ), 'universal_nofollow_export' ) ); ?>" class="button button-secondary">
                        Скачать CSV
                    </a>
                </p>
            </div>
        </div>
        
        <!-- ====================== СПИСОК БЛОКИРОВКИ (CSV) ====================== -->
        <div id="universal-panel-blocklist" class="universal-panel" style="display: none;">
            <h2>✅ Управление списком блокировки ссылок</h2>
            
            <div style="background: #f9f9f9; padding: 20px; border-radius: 5px; margin-bottom: 20px;">
                <h3>📥 Загрузить CSV файл</h3>
                <form method="post" enctype="multipart/form-data">
                    <?php wp_nonce_field( 'universal_nofollow_blocklist_csv', 'universal_nofollow_blocklist_csv_nonce' ); ?>
                    <input type="file" name="blocklist_csv_file" accept=".csv" required />
                    <?php submit_button( 'Загрузить', 'secondary', 'submit', false ); ?>
                    <p class="description">
                        Загрузите CSV файл со ссылками для блокировки (одна ссылка на строку).<br>
                        <strong>Формат:</strong> Первая колонка должна содержать URL.<br>
                        <strong>Примечание:</strong> Ссылки из этого списка будут получать rel="nofollow" независимо от других настроек.
                    </p>
                </form>
            </div>
            
            <div style="background: #f9f9f9; padding: 20px; border-radius: 5px; margin-bottom: 20px;">
                <h3>🖊️ Добавить новую ссылку</h3>
                <form method="post">
                    <?php wp_nonce_field( 'universal_nofollow_add_blocklist', 'universal_nofollow_add_blocklist_nonce' ); ?>
                    <input type="text" name="new_blocklist_link" placeholder="https://example.com/..." style="width: 60%;" required />
                    <?php submit_button( 'Добавить', 'secondary', 'add_blocklist', false ); ?>
                </form>
                
                <?php
                // Обработка добавления новой ссылки в список блокировки
                if ( isset( $_POST['add_blocklist'] )
                    && isset( $_POST['new_blocklist_link'] )
                    && wp_verify_nonce( $_POST['universal_nofollow_add_blocklist_nonce'], 'universal_nofollow_add_blocklist' )
                ) {
                    $new = trim( sanitize_text_field( $_POST['new_blocklist_link'] ) );
                    if ( $new ) {
                        $raw = get_option( 'universal_nofollow_blocklist_links', '' );
                        $lines = array_filter( array_map( 'trim', explode( "\n", $raw ) ) );
                        if ( ! in_array( $new, $lines, true ) ) {
                            $lines[] = $new;
                            update_option( 'universal_nofollow_blocklist_links', implode( "\n", $lines ) );
                            echo '<div class="notice notice-success"><p>✅ Ссылка добавлена в список блокировки.</p></div>';
                            // Обновляем список
                            $blocklist = universal_get_blocklist_links();
                        }
                    }
                }
                ?>
            </div>
            
            <div style="background: #f9f9f9; padding: 20px; border-radius: 5px; margin-bottom: 20px;">
                <h3>📋 Текущий список блокировки</h3>
                <p>Всего ссылок в списке: <strong><?php echo count( $blocklist ); ?></strong></p>
                
                <?php if ( ! empty( $blocklist ) ) : ?>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th>URL для блокировки</th>
                                <th style="width: 100px;">Действие</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $blocklist as $link ) : ?>
                                <tr>
                                    <td><?php echo esc_html( $link ); ?></td>
                                    <td>
                                        <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'page' => 'universal-nofollow-settings', 'action' => 'delete_blocklist', 'blocklist_link' => base64_encode( $link ) ), admin_url( 'options-general.php' ) ), 'universal_nofollow_delete_blocklist' ) ); ?>" class="button button-small button-link-delete">Удалить</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else : ?>
                    <p style="color: #999;">Список блокировки пуст</p>
                <?php endif; ?>
            </div>
            
            <div style="background: #f9f9f9; padding: 20px; border-radius: 5px;">
                <h3>📤 Экспортировать CSV</h3>
                <p>
                    <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'export_blocklist_csv' ), admin_url( 'options-general.php?page=universal-nofollow-settings' ) ), 'universal_nofollow_export_blocklist' ) ); ?>" class="button button-secondary">
                        Скачать CSV
                    </a>
                </p>
            </div>
        </div>
        
        <!-- ====================== СТАТИСТИКА ====================== -->
        <div id="universal-panel-stats" class="universal-panel" style="display: none;">
            <h2>📊 Статистика обработки ссылок</h2>
            
            <div style="background: #f9f9f9; padding: 20px; border-radius: 5px; margin-bottom: 20px;">
                <table class="widefat striped">
                    <tbody>
                        <tr>
                            <td><strong>Обработано ссылок:</strong></td>
                            <td><?php echo intval( $stats['processed'] ); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Добавлено rel="nofollow":</strong></td>
                            <td><?php echo intval( $stats['added'] ); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Исключено (по списку):</strong></td>
                            <td><?php echo intval( $stats['excluded'] ); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Исключено (по гео):</strong></td>
                            <td><?php echo intval( $stats['geo_excluded'] ); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Ошибок регекс-парсинга:</strong></td>
                            <td><?php echo intval( $stats['error'] ); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <form method="post" action="">
                <?php wp_nonce_field( 'universal_nofollow_reset_stats', 'universal_nofollow_reset_stats_nonce' ); ?>
                <?php submit_button( 'Очистить статистику', 'delete', 'submit', true ); ?>
            </form>
        </div>
        
        <hr style="margin: 30px 0;" />
        
        <!-- ====================== ИНФОРМАЦИЯ О ПЛАГИНЕ ====================== -->
        <div style="background: #f5f5f5; padding: 20px; border-radius: 5px;">
            <h2>ℹ️ Информация о плагине</h2>
            
            <h3>✅ Что обрабатывает плагин:</h3>
            <ul style="list-style: none; padding-left: 0;">
                <li>✓ <strong>Все внешние ссылки</strong> (по умолчанию)</li>
                <li>✓ <strong>Ссылки из списка блокировки</strong> (приоритет выше)</li>
                <li>✓ <strong>Яндекс Маркет</strong> (market.yandex.ru) — можно исключить</li>
                <li>✓ <strong>Яндекс Реклама</strong> (yandex.ru/clck) — всегда исключается</li>
                <li>✓ <strong>Социальные сети</strong> (если включено)</li>
                <li>✓ <strong>Динамические ссылки</strong> в скриптах</li>
            </ul>
            
            <h3>🔧 Поддерживаемые page builders:</h3>
            <ul style="list-style: none; padding-left: 0;">
                <li>✓ Elementor</li>
                <li>✓ Beaver Builder</li>
                <li>✓ Divi</li>
                <li>✓ Gutenberg</li>
                <li>✓ Oxygen Builder</li>
                <li>✓ Brizy</li>
                <li>✓ WooCommerce</li>
                <li>✓ ACF (Advanced Custom Fields)</li>
            </ul>
            
            <h3>🎯 Особенности:</h3>
            <ul style="list-style: none; padding-left: 0;">
                <li>✓ <strong>Без буферизации</strong> — не конфликтует с другими плагинами</li>
                <li>✓ <strong>Два списка управления</strong> — исключения и блокировка</li>
                <li>✓ <strong>CSV импорт/экспорт</strong> — для обоих списков</li>
                <li>✓ <strong>Гео-таргетинг</strong> — исключение по странам с кешированием</li>
                <li>✓ <strong>REST API</strong> — stats & blocked-countries</li>
                <li>✓ <strong>Интеграция с SEO-плагинами</strong></li>
                <li>✓ <strong>Логирование</strong> — для отладки в режиме WP_DEBUG</li>
                <li>✓ <strong>Производительность</strong> — кеширование и оптимизация</li>
            </ul>
        </div>
    </div>
    
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const tabs = document.querySelectorAll('.universal-tab');
        const panels = document.querySelectorAll('.universal-panel');
        
        tabs.forEach(tab => {
            tab.addEventListener('click', function (e) {
                e.preventDefault();
                const target = this.dataset.target;
                
                tabs.forEach(t => t.classList.remove('nav-tab-active'));
                panels.forEach(p => p.style.display = 'none');
                
                this.classList.add('nav-tab-active');
                document.getElementById(target).style.display = 'block';
            });
        });
    });
    </script>
    <?php
    
    // Обработка удаления ссылки из списка блокировки
    if ( isset( $_GET['action'] ) && $_GET['action'] === 'delete_blocklist'
        && isset( $_GET['_wpnonce'] )
        && wp_verify_nonce( $_GET['_wpnonce'], 'universal_nofollow_delete_blocklist' )
        && isset( $_GET['blocklist_link'] )
    ) {
        $link_to_delete = base64_decode( $_GET['blocklist_link'] );
        $raw = get_option( 'universal_nofollow_blocklist_links', '' );
        $lines = array_filter( array_map( 'trim', explode( "\n", $raw ) ) );
        $lines = array_filter( $lines, function( $l ) use ( $link_to_delete ) {
            return $l !== $link_to_delete;
        } );
        update_option( 'universal_nofollow_blocklist_links', implode( "\n", $lines ) );
    }
}

// ============================================
// CSV ИМПОРТ/ЭКСПОРТ
// ============================================

/**
 * Импортирует ссылки из CSV файла
 * 
 * @param array $file Информация о загруженном файле
 * @param string $type Тип списка ('excluded' или 'blocklist')
 * @return array Результат импорта
 */
function universal_import_csv( $file, $type = 'excluded' ) {
    // Проверяем тип файла
    if ( $file['type'] !== 'text/csv' && $file['type'] !== 'application/vnd.ms-excel' ) {
        return array(
            'success' => false,
            'message' => 'Неверный формат файла. Используйте CSV.',
        );
    }
    
    // Проверяем размер файла (максимум 5 МБ)
    if ( $file['size'] > 5 * 1024 * 1024 ) {
        return array(
            'success' => false,
            'message' => 'Файл слишком большой. Максимум 5 МБ.',
        );
    }
    
    // Читаем файл
    $handle = fopen( $file['tmp_name'], 'r' );
    if ( ! $handle ) {
        return array(
            'success' => false,
            'message' => 'Не удалось открыть файл.',
        );
    }
    
    $links = array();
    $count = 0;
    
    while ( ( $row = fgetcsv( $handle ) ) !== false ) {
        if ( ! empty( $row[0] ) ) {
            $link = trim( $row[0] );
            if ( ! in_array( $link, $links, true ) ) {
                $links[] = $link;
                $count++;
            }
        }
    }
    
    fclose( $handle );
    
    // Определяем опцию в зависимости от типа
    $option_key = ( $type === 'blocklist' ) ? 'universal_nofollow_blocklist_links' : 'universal_nofollow_excluded_links';
    
    // Получаем существующие ссылки
    $existing = ( $type === 'blocklist' ) ? universal_get_blocklist_links() : universal_get_excluded_links();
    
    // Объединяем и удаляем дубликаты
    $all_links = array_unique( array_merge( $existing, $links ) );
    
    // Сохраняем
    update_option( $option_key, implode( "\n", $all_links ) );
    
    $type_name = ( $type === 'blocklist' ) ? 'блокировки' : 'исключений';
    
    return array(
        'success' => true,
        'message' => 'Загружено ' . $count . ' новых ' . $type_name . '. Всего: ' . count( $all_links ),
    );
}

/**
 * Экспортирует ссылки в CSV файл
 * 
 * @param string $type Тип списка ('excluded' или 'blocklist')
 */
function universal_export_csv( $type = 'excluded' ) {
    // Получаем ссылки в зависимости от типа
    $links = ( $type === 'blocklist' ) ? universal_get_blocklist_links() : universal_get_excluded_links();
    
    // Определяем имя файла
    $filename = ( $type === 'blocklist' ) ? 'blocklist-links-' : 'excluded-links-';
    
    // Устанавливаем заголовки
    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="' . $filename . date( 'Y-m-d' ) . '.csv"' );
    
    // Открываем вывод
    $output = fopen( 'php://output', 'w' );
    
    // Пишем заголовок
    $header = ( $type === 'blocklist' ) ? 'URL для блокировки' : 'Исключённый URL';
    fputcsv( $output, array( $header ) );
    
    // Пишем ссылки
    foreach ( $links as $link ) {
        fputcsv( $output, array( $link ) );
    }
    
    fclose( $output );
}

// ============================================
// REST API ROUTES
// ============================================

/**
 * Регистрирует REST API маршруты
 */
function universal_register_rest_routes() {
    // Маршрут для получения статистики
    register_rest_route( 'universal-nofollow/v1', '/stats', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => function() {
            return rest_ensure_response( universal_get_stats() );
        },
        'permission_callback' => function() {
            return current_user_can( 'manage_options' );
        },
    ) );
    
    // Маршрут для получения заблокированных стран
    register_rest_route( 'universal-nofollow/v1', '/blocked-countries', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => function() {
            $settings = get_option( 'universal_nofollow_settings', array() );
            $blocked = isset( $settings['blocked_countries'] ) ? $settings['blocked_countries'] : array();
            return rest_ensure_response( $blocked );
        },
        'permission_callback' => function() {
            return current_user_can( 'manage_options' );
        },
    ) );
    
    // Маршрут для получения списка стран
    register_rest_route( 'universal-nofollow/v1', '/countries', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => function() {
            return rest_ensure_response( universal_get_countries_list() );
        },
        'permission_callback' => function() {
            return current_user_can( 'manage_options' );
        },
    ) );
    
    // Маршрут для получения исключенных ссылок
    register_rest_route( 'universal-nofollow/v1', '/excluded-links', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => function() {
            return rest_ensure_response( universal_get_excluded_links() );
        },
        'permission_callback' => function() {
            return current_user_can( 'manage_options' );
        },
    ) );
    
    // Маршрут для получения списка блокировки
    register_rest_route( 'universal-nofollow/v1', '/blocklist-links', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => function() {
            return rest_ensure_response( universal_get_blocklist_links() );
        },
        'permission_callback' => function() {
            return current_user_can( 'manage_options' );
        },
    ) );
}
add_action( 'rest_api_init', 'universal_register_rest_routes' );

// ============================================
// ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ
// ============================================

/**
 * Функция для ручной обработки контента
 * 
 * @param mixed $content Контент для обработки
 * @return mixed Обработанный контент
 */
function process_universal_links( $content ) {
    return universal_add_nofollow_to_links( $content );
}

/**
 * Получить версию плагина
 * 
 * @return string Версия плагина
 */
function universal_nofollow_get_version() {
    return UNIVERSAL_NOFOLLOW_VERSION;
}

// ============================================
// ИНИЦИАЛИЗАЦИЯ ПЛАГИНА
// ============================================

/**
 * Инициализация плагина
 */
function universal_nofollow_init() {
    universal_log( 'Plugin initialized - Version ' . UNIVERSAL_NOFOLLOW_VERSION );
    
    // Загружаем текстовый домен для переводов
    load_plugin_textdomain( 'universal-nofollow', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
    
    // Запускаем действие инициализации
    do_action( 'universal_nofollow_loaded' );
}
add_action( 'plugins_loaded', 'universal_nofollow_init' );

// ============================================
// АКТИВАЦИЯ И ДЕАКТИВАЦИЯ ПЛАГИНА
// ============================================

/**
 * При активации плагина
 */
function universal_nofollow_activate() {
    universal_log( 'Plugin activated' );
    
    // Устанавливаем настройки по умолчанию
    if ( ! get_option( 'universal_nofollow_settings' ) ) {
        $default_settings = array(
            'post_types'            => array(),
            'block_social'          => '0',
            'exclude_yandex_market' => '0',
            'blocked_countries'     => array(),
            'excluded_links'        => '',
        );
        add_option( 'universal_nofollow_settings', $default_settings );
    }
    
    // Инициализируем список исключений
    if ( false === get_option( 'universal_nofollow_excluded_links' ) ) {
        add_option( 'universal_nofollow_excluded_links', '' );
    }
    
    // Инициализируем список блокировки
    if ( false === get_option( 'universal_nofollow_blocklist_links' ) ) {
        add_option( 'universal_nofollow_blocklist_links', '' );
    }
    
    // Инициализируем статистику
    if ( false === get_option( 'universal_nofollow_stats' ) ) {
        add_option( 'universal_nofollow_stats', array(
            'processed'    => 0,
            'added'        => 0,
            'excluded'     => 0,
            'geo_excluded' => 0,
            'error'        => 0,
        ) );
    }
    
    // Загружаем список стран (для кеширования)
    universal_get_countries_list();
    
    do_action( 'universal_nofollow_activated' );
}
register_activation_hook( __FILE__, 'universal_nofollow_activate' );

/**
 * При деактивации плагина
 */
function universal_nofollow_deactivate() {
    universal_log( 'Plugin deactivated' );
    do_action( 'universal_nofollow_deactivated' );
}
register_deactivation_hook( __FILE__, 'universal_nofollow_deactivate' );

/**
 * При удалении плагина
 */
function universal_nofollow_uninstall() {
    // Удаляем все опции и кеши
    delete_option( 'universal_nofollow_settings' );
    delete_option( 'universal_nofollow_excluded_links' );
    delete_option( 'universal_nofollow_blocklist_links' );
    delete_option( 'universal_nofollow_stats' );
    delete_transient( 'universal_nofollow_stats_cache' );
    delete_transient( 'universal_countries_list' );
    
    // Удаляем кеши IP адресов
    global $wpdb;
    $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'universal_visitor_country_%'" );
}
register_uninstall_hook( __FILE__, 'universal_nofollow_uninstall' );
