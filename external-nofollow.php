<?php
/**
 * Plugin Name: External Links Nofollow
 * Description: Автоматически добавляет rel="nofollow" ко всем внешним ссылкам
 * Version: 2.0
 */

// Предотвращаем прямой доступ
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Основная функция обработки ссылок
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
    
    // ИСПРАВЛЕНО: Регулярное выражение захватывает ПОЛНЫЙ тег <a>
    $pattern = '/<a\s[^>]*href\s*=\s*["\'][^"\']+["\'][^>]*>/i';
    
    $content = preg_replace_callback( $pattern, function( $matches ) use ( $home_domain ) {
        $full_link = $matches[0];
        
        // Извлекаем URL из href
        if ( ! preg_match( '/href\s*=\s*["\']([^"\']+)["\']/', $full_link, $href_match ) ) {
            return $full_link;
        }
        
        $url = $href_match[1];
        $link_domain = wp_parse_url( $url, PHP_URL_HOST );
        
        // Проверяем, является ли ссылка внешней
        if ( ! $link_domain || $link_domain === $home_domain || strpos( $url, 'http' ) !== 0 ) {
            return $full_link;
        }
        
        // ИСПРАВЛЕНО: Проверяем, нет ли уже nofollow
        if ( preg_match( '/rel\s*=\s*["\']([^"\']*)["\']/', $full_link, $rel_match ) ) {
            $existing_rel = $rel_match[1];
            
            // Если nofollow уже есть — ничего не делаем
            if ( preg_match( '/\bnofollow\b/', $existing_rel ) ) {
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
            // ИСПРАВЛЕНО: Добавляем rel перед закрывающей скобкой тега
            $full_link = preg_replace( '/>$/', ' rel="nofollow">', $full_link );
        }
        
        return $full_link;
    }, $content );
    
    return $content;
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

// Описания терминов
add_filter( 'term_description', 'add_nofollow_to_external_links', 999 );

// Описание автора
add_filter( 'get_the_author_description', 'add_nofollow_to_external_links', 999 );

// ============================================
// ИСПРАВЛЕНО: Буферизация всего HTML-вывода
// ============================================

/**
 * Запуск буферизации на раннем этапе
 */
function external_links_start_buffer() {
    if ( ! is_admin() && ! wp_doing_ajax() && ! defined( 'REST_REQUEST' ) ) {
        ob_start();
    }
}
add_action( 'template_redirect', 'external_links_start_buffer', 0 );

/**
 * Обработка и вывод буфера
 */
function external_links_end_buffer() {
    if ( ! is_admin() && ! wp_doing_ajax() && ! defined( 'REST_REQUEST' ) ) {
        if ( ob_get_level() > 0 ) {
            $content = ob_get_clean();
            echo add_nofollow_to_external_links( $content );
        }
    }
}
add_action( 'shutdown', 'external_links_end_buffer', 0 );

// ============================================
// ПОДДЕРЖКА ПОПУЛЯРНЫХ ПЛАГИНОВ
// ============================================

// WooCommerce
add_filter( 'woocommerce_product_description', 'add_nofollow_to_external_links', 999 );
add_filter( 'woocommerce_short_description', 'add_nofollow_to_external_links', 999 );

// ACF (Advanced Custom Fields) — с проверкой существования
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

// Beaver Builder
add_filter( 'fl_builder_render_content', 'add_nofollow_to_external_links', 999 );

// Divi
add_filter( 'et_pb_get_processed_content', 'add_nofollow_to_external_links', 999 );

// Gutenberg блоки
add_filter( 'render_block', function( $block_content, $block ) {
    return add_nofollow_to_external_links( $block_content );
}, 999, 2 );

// ============================================
// ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ
// ============================================

/**
 * Обработка ссылок в кастомных полях ACF
 * ИСПРАВЛЕНО: Добавлена проверка существования функции
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
