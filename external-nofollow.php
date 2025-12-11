<?php
/**
 * Plugin Name: External Links Nofollow Pro
 * Description: Автоматически добавляет rel="nofollow" ко всем внешним ссылкам на сайте
 * Version: 3.0
 * Author: WordPress Developer
 * License: GPL v2 or later
 * Text Domain: external-links-nofollow
 */

// Предотвращаем прямой доступ
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ============================================
// КОНСТАНТЫ И КОНФИГУРАЦИЯ
// ============================================

define( 'EXTERNAL_NOFOLLOW_VERSION', '3.0' );
define( 'EXTERNAL_NOFOLLOW_DEBUG', defined( 'WP_DEBUG' ) && WP_DEBUG );

// ============================================
// ОСНОВНАЯ ФУНКЦИЯ ОБРАБОТКИ ССЫЛОК
// ============================================

/**
 * Добавляет rel="nofollow" ко всем внешним ссылкам
 * 
 * @param mixed $content Контент для обработки
 * @return mixed Обработанный контент
 */
function add_nofollow_to_external_links( $content ) {
    // Проверяем, что контент — строка и не пустой
    if ( empty( $content ) || ! is_string( $content ) ) {
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
        $full_link = $matches[0];
        $url = $matches[1];
        
        // Проверяем, является ли ссылка внешней
        if ( ! external_links_is_external( $url, $home_domain ) ) {
            return $full_link;
        }
        
        // Проверяем, нет ли уже nofollow
        if ( preg_match( '/rel\s*=\s*["\']([^"\']*)["\']/', $full_link, $rel_match ) ) {
            $existing_rel = $rel_match[1];
            
            // Если nofollow уже есть — ничего не делаем
            if ( preg_match( '/\bnofollow\b/i', $existing_rel ) ) {
                external_links_log( 'Link already has nofollow: ' . $url );
                return $full_link;
            }
            
            // Добавляем nofollow к существующему rel
            $new_rel = trim( $existing_rel . ' nofollow' );
            $full_link = preg_replace( 
                '/rel\s*=\s*["\'][^"\']*["\']/', 
                'rel="' . esc_attr( $new_rel ) . '"', 
                $full_link 
            );
            
            external_links_log( 'Added nofollow to existing rel: ' . $url );
        } else {
            // Добавляем новый rel атрибут перед закрывающей скобкой тега
            $full_link = preg_replace( '/>$/', ' rel="nofollow">', $full_link );
            external_links_log( 'Added new rel="nofollow": ' . $url );
        }
        
        return $full_link;
    }, $content );
    
    // Проверяем на ошибки регулярных выражений
    if ( preg_last_error() !== PREG_NO_ERROR ) {
        external_links_log_error( 'Regex error: ' . preg_last_error() );
        return $content;
    }
    
    return $content;
}

// ============================================
// ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ
// ============================================

/**
 * Проверяет, является ли ссылка внешней
 * 
 * @param string $url URL для проверки
 * @param string $home_domain Домен сайта
 * @return bool True если ссылка внешняя
 */
function external_links_is_external( $url, $home_domain ) {
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
 * Проверяет, нужно ли обрабатывать текущую страницу
 * 
 * @return bool True если нужно обрабатывать
 */
function external_links_should_process() {
    // Исключаем админ-панель
    if ( is_admin() ) {
        return false;
    }
    
    // Исключаем AJAX запросы
    if ( wp_doing_ajax() ) {
        return false;
    }
    
    // Исключаем REST API
    if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
        return false;
    }
    
    // Исключаем определенные страницы (фильтр для кастомизации)
    $excluded_ids = apply_filters( 'external_nofollow_excluded_ids', array() );
    if ( is_singular() && in_array( get_the_ID(), $excluded_ids, true ) ) {
        return false;
    }
    
    // Исключаем определенные типы постов
    $excluded_post_types = apply_filters( 'external_nofollow_excluded_post_types', array() );
    if ( is_singular() && in_array( get_post_type(), $excluded_post_types, true ) ) {
        return false;
    }
    
    return true;
}

/**
 * Логирование информации (только в режиме отладки)
 * 
 * @param string $message Сообщение для логирования
 */
function external_links_log( $message ) {
    if ( ! EXTERNAL_NOFOLLOW_DEBUG ) {
        return;
    }
    
    if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
        error_log( '[External Links Nofollow] ' . $message );
    }
}

/**
 * Логирование ошибок
 * 
 * @param string $message Сообщение об ошибке
 */
function external_links_log_error( $message ) {
    if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
        error_log( '[External Links Nofollow ERROR] ' . $message );
    }
}

// ============================================
// ФИЛЬТРЫ ДЛЯ КОНТЕНТА
// ============================================

// Контент постов и страниц
add_filter( 'the_content', 'add_nofollow_to_external_links', 999 );

// Виджеты
add_filter( 'widget_text_content', 'add_nofollow_to_external_links', 999 );
add_filter( 'widget_text', 'add_nofollow_to_external_links', 999 );

// Выдержки
add_filter( 'the_excerpt', 'add_nofollow_to_external_links', 999 );

// Комментарии
add_filter( 'comment_text', 'add_nofollow_to_external_links', 999 );

// Описания терминов (категории, теги)
add_filter( 'term_description', 'add_nofollow_to_external_links', 999 );

// Описание автора
add_filter( 'get_the_author_description', 'add_nofollow_to_external_links', 999 );

// ============================================
// БУФЕРИЗАЦИЯ ВСЕГО HTML-ВЫВОДА
// ============================================

/**
 * Запуск буферизации на раннем этапе
 */
function external_links_start_buffer() {
    if ( ! external_links_should_process() ) {
        return;
    }
    
    // Проверяем, не запущена ли уже буферизация
    if ( ob_get_level() === 0 ) {
        ob_start();
        external_links_log( 'Buffer started' );
    }
}
add_action( 'template_redirect', 'external_links_start_buffer', 0 );

/**
 * Обработка и вывод буфера
 */
function external_links_end_buffer() {
    if ( ! external_links_should_process() ) {
        return;
    }
    
    if ( ob_get_level() > 0 ) {
        $content = ob_get_clean();
        
        // Обрабатываем контент
        $processed_content = add_nofollow_to_external_links( $content );
        
        echo $processed_content;
        external_links_log( 'Buffer processed and flushed' );
    }
}
add_action( 'shutdown', 'external_links_end_buffer', 0 );

// ============================================
// ПОДДЕРЖКА ПОПУЛЯРНЫХ ПЛАГИНОВ
// ============================================

// WooCommerce
add_filter( 'woocommerce_product_description', 'add_nofollow_to_external_links', 999 );
add_filter( 'woocommerce_short_description', 'add_nofollow_to_external_links', 999 );
add_filter( 'woocommerce_product_tabs', function( $tabs ) {
    foreach ( $tabs as $key => $tab ) {
        if ( isset( $tab['content'] ) ) {
            $tabs[ $key ]['content'] = add_nofollow_to_external_links( $tab['content'] );
        }
    }
    return $tabs;
}, 999 );

// ACF (Advanced Custom Fields)
if ( class_exists( 'ACF' ) ) {
    add_filter( 'acf/format_value', function( $value, $post_id, $field ) {
        if ( is_string( $value ) ) {
            return add_nofollow_to_external_links( $value );
        }
        return $value;
    }, 999, 3 );
}

// Elementor
add_filter( 'elementor/frontend/the_content', 'add_nofollow_to_external_links', 999 );
add_filter( 'elementor_pro/documents/print_elements_content', 'add_nofollow_to_external_links', 999 );

// Beaver Builder
add_filter( 'fl_builder_render_content', 'add_nofollow_to_external_links', 999 );

// Divi
add_filter( 'et_pb_get_processed_content', 'add_nofollow_to_external_links', 999 );

// Gutenberg блоки
add_filter( 'render_block', function( $block_content, $block ) {
    return add_nofollow_to_external_links( $block_content );
}, 999, 2 );

// Oxygen Builder
add_filter( 'oxygen_vsb_output', 'add_nofollow_to_external_links', 999 );

// Brizy
add_filter( 'brizy_content', 'add_nofollow_to_external_links', 999 );

// ============================================
// ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ДЛЯ РАЗРАБОТЧИКОВ
// ============================================

/**
 * Обработка ссылок в кастомных полях ACF
 * 
 * @param string $field_name Имя поля
 * @param int|null $post_id ID поста
 * @return mixed Обработанное значение
 */
function get_external_nofollow_field( $field_name, $post_id = null ) {
    if ( ! function_exists( 'get_field' ) ) {
        return '';
    }
    
    $value = get_field( $field_name, $post_id );
    
    if ( ! is_string( $value ) ) {
        return $value;
    }
    
    return add_nofollow_to_external_links( $value );
}

/**
 * Функция для ручной обработки контента
 * 
 * @param mixed $content Контент для обработки
 * @return mixed Обработанный контент
 */
function process_external_links( $content ) {
    return add_nofollow_to_external_links( $content );
}

/**
 * Получить версию плагина
 * 
 * @return string Версия плагина
 */
function external_links_nofollow_get_version() {
    return EXTERNAL_NOFOLLOW_VERSION;
}

// ============================================
// ХУКИ ДЛЯ КАСТОМИЗАЦИИ
// ============================================

/**
 * Фильтр для исключения определенных доменов
 * 
 * Пример использования:
 * add_filter( 'external_nofollow_excluded_domains', function( $domains ) {
 *     $domains[] = 'example.com';
 *     return $domains;
 * });
 */
apply_filters( 'external_nofollow_excluded_domains', array() );

/**
 * Фильтр для исключения определенных страниц
 * 
 * Пример использования:
 * add_filter( 'external_nofollow_excluded_ids', function( $ids ) {
 *     $ids[] = 123; // ID страницы
 *     return $ids;
 * });
 */
apply_filters( 'external_nofollow_excluded_ids', array() );

/**
 * Фильтр для исключения определенных типов постов
 * 
 * Пример использования:
 * add_filter( 'external_nofollow_excluded_post_types', function( $types ) {
 *     $types[] = 'page';
 *     return $types;
 * });
 */
apply_filters( 'external_nofollow_excluded_post_types', array() );

// ============================================
// ИНИЦИАЛИЗАЦИЯ ПЛАГИНА
// ============================================

/**
 * Инициализация плагина
 */
function external_links_nofollow_init() {
    external_links_log( 'Plugin initialized - Version ' . EXTERNAL_NOFOLLOW_VERSION );
    
    // Загружаем текстовый домен для переводов
    load_plugin_textdomain( 'external-links-nofollow', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
    
    // Запускаем действие инициализации
    do_action( 'external_links_nofollow_loaded' );
}
add_action( 'plugins_loaded', 'external_links_nofollow_init' );

// ============================================
// АКТИВАЦИЯ И ДЕАКТИВАЦИЯ ПЛАГИНА
// ============================================

/**
 * При активации плагина
 */
function external_links_nofollow_activate() {
    external_links_log( 'Plugin activated' );
    do_action( 'external_links_nofollow_activated' );
}
register_activation_hook( __FILE__, 'external_links_nofollow_activate' );

/**
 * При деактивации плагина
 */
function external_links_nofollow_deactivate() {
    external_links_log( 'Plugin deactivated' );
    do_action( 'external_links_nofollow_deactivated' );
}
register_deactivation_hook( __FILE__, 'external_links_nofollow_deactivate' );
