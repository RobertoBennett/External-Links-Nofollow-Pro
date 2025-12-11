<?php
/**
 * Comprehensive External Links NoFollow Script
 * Обрабатывает внешние ссылки во всех местах сайта
 */

// Основная функция обработки ссылок
function add_nofollow_to_external_links( $content ) {
    if ( empty( $content ) ) {
        return $content;
    }
    
    // Получаем домен сайта
    $home_url = home_url();
    $home_domain = wp_parse_url( $home_url, PHP_URL_HOST );
    
    // Регулярное выражение для поиска всех ссылок
    $pattern = '/<a\s+(?:[^>]*?\s+)?href=(["\'])([^"\']+)\1/i';
    
    $content = preg_replace_callback( $pattern, function( $matches ) use ( $home_domain ) {
        $url = $matches[2];
        $link_domain = wp_parse_url( $url, PHP_URL_HOST );
        
        // Проверяем, является ли ссылка внешней
        if ( $link_domain && $link_domain !== $home_domain && strpos( $url, 'http' ) === 0 ) {
            $full_link = $matches[0];
            
            // Проверяем, есть ли уже rel атрибут
            if ( preg_match( '/rel=["\']([^"\']*)["\']/', $full_link ) ) {
                // Добавляем nofollow к существующему rel
                $full_link = preg_replace( '/rel=(["\'])([^"\']*)\1/', 'rel=$1$2 nofollow$1', $full_link );
            } else {
                // Добавляем новый rel атрибут
                $full_link = preg_replace( '/href=/', 'rel="nofollow" href=', $full_link );
            }
            
            return $full_link;
        }
        
        return $matches[0];
    }, $content );
    
    return $content;
}

// Применяем фильтр к контенту постов и страниц
add_filter( 'the_content', 'add_nofollow_to_external_links', 999 );

// Применяем фильтр к контенту виджетов
add_filter( 'widget_text_content', 'add_nofollow_to_external_links', 999 );
add_filter( 'widget_text', 'add_nofollow_to_external_links', 999 );

// Применяем фильтр к выводу плагинов (общий фильтр)
add_filter( 'the_excerpt', 'add_nofollow_to_external_links', 999 );

// Применяем фильтр к комментариям
add_filter( 'comment_text', 'add_nofollow_to_external_links', 999 );

// Применяем фильтр к описанию категорий и тегов
add_filter( 'term_description', 'add_nofollow_to_external_links', 999 );

// Применяем фильтр к описанию автора
add_filter( 'get_the_author_description', 'add_nofollow_to_external_links', 999 );

// Применяем фильтр к пользовательским полям (meta)
add_filter( 'the_meta', 'add_nofollow_to_external_links', 999 );

// Обработка ссылок в футере и других местах
add_filter( 'wp_footer', function() {
    ob_start();
}, 0 );

add_filter( 'wp_footer', function() {
    $content = ob_get_clean();
    echo add_nofollow_to_external_links( $content );
}, 999 );

// Обработка ссылок в хедере
add_filter( 'wp_head', function() {
    ob_start();
}, 0 );

add_filter( 'wp_head', function() {
    $content = ob_get_clean();
    echo add_nofollow_to_external_links( $content );
}, 999 );

// Специальная обработка для популярных плагинов
// WooCommerce
add_filter( 'woocommerce_product_description', 'add_nofollow_to_external_links', 999 );
add_filter( 'woocommerce_short_description', 'add_nofollow_to_external_links', 999 );

// ACF (Advanced Custom Fields)
add_filter( 'acf/format_value', function( $value, $post_id, $field ) {
    if ( is_string( $value ) ) {
        return add_nofollow_to_external_links( $value );
    }
    return $value;
}, 999, 3 );

// Elementor
add_filter( 'elementor/frontend/the_content', 'add_nofollow_to_external_links', 999 );

// Beaver Builder
add_filter( 'fl_builder_render', 'add_nofollow_to_external_links', 999 );

// Divi
add_filter( 'et_pb_get_processed_content', 'add_nofollow_to_external_links', 999 );

// Gutenberg блоки
add_filter( 'render_block', function( $block_content, $block ) {
    return add_nofollow_to_external_links( $block_content );
}, 999, 2 );

// Обработка всего HTML вывода (последняя линия защиты)
add_action( 'wp_footer', function() {
    if ( ! is_admin() ) {
        ob_start( 'add_nofollow_to_external_links' );
    }
}, 0 );

// Дополнительная функция для обработки ссылок в кастомных полях
function get_external_nofollow_field( $field_name, $post_id = null ) {
    $value = get_field( $field_name, $post_id );
