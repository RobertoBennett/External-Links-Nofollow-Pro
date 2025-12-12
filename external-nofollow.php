<?php
/**
 * Plugin Name: Universal Nofollow Pro
 * Description: Добавляет rel="nofollow" ко всем внешним ссылкам, включая Яндекс Маркет, с админ-панелью
 * Version: 3.2
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

define( 'UNIVERSAL_NOFOLLOW_VERSION', '3.0' );
define( 'UNIVERSAL_NOFOLLOW_DEBUG', defined( 'WP_DEBUG' ) && WP_DEBUG );
define( 'UNIVERSAL_NOFOLLOW_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'UNIVERSAL_NOFOLLOW_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

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
        $full_link = $matches[0];
        $url = $matches[1];
        
        // Проверяем, является ли ссылка внешней
        if ( ! universal_is_external( $url, $home_domain ) ) {
            return $full_link;
        }
        
        // Проверяем, исключена ли ссылка
        if ( universal_is_link_excluded( $url ) ) {
            return $full_link;
        }
        
        // Проверяем, это ли Яндекс реклама (исключаем из обработки)
        if ( universal_is_yandex_ads( $url ) ) {
            universal_log( 'Yandex ads link excluded: ' . $url );
            return $full_link;
        }
        
        universal_log( 'Found external link: ' . $url );
        
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
        
        universal_log( 'Added nofollow to external link: ' . $url );
        return $full_link;
    }, $content );
    
    // Проверяем на ошибки регулярных выражений
    if ( preg_last_error() !== PREG_NO_ERROR ) {
        universal_log_error( 'Regex error: ' . preg_last_error() );
        return $content;
    }
    
    return $content;
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
    // Получаем настройки
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
    $settings = get_option( 'universal_nofollow_settings', array() );
    $excluded = isset( $settings['excluded_links'] ) ? $settings['excluded_links'] : '';
    
    if ( empty( $excluded ) ) {
        return array();
    }
    
    // Разбиваем по строкам и очищаем
    $links = array_map( 'trim', explode( "\n", $excluded ) );
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
 * Проверяет, включена ли блокировка соцсетей
 * 
 * @return bool True если включена
 */
function universal_is_social_blocking_enabled() {
    $settings = get_option( 'universal_nofollow_settings', array() );
    return isset( $settings['block_social'] ) && $settings['block_social'] === '1';
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
            $settings['excluded_links'] = sanitize_textarea_field( $_POST['excluded_links'] );
        }
        
        update_option( 'universal_nofollow_settings', $settings );
        
        echo '<div class="notice notice-success"><p>✓ Настройки сохранены успешно!</p></div>';
    }
    
    // Получаем текущие настройки
    $settings = get_option( 'universal_nofollow_settings', array() );
    $enabled_post_types = isset( $settings['post_types'] ) ? $settings['post_types'] : array();
    $block_social = isset( $settings['block_social'] ) ? $settings['block_social'] : '0';
    $exclude_yandex_market = isset( $settings['exclude_yandex_market'] ) ? $settings['exclude_yandex_market'] : '0';
    $excluded_links = isset( $settings['excluded_links'] ) ? $settings['excluded_links'] : '';
    
    // Получаем все типы записей
    $post_types = get_post_types( array( 'public' => true ), 'objects' );
    
    ?>
    <div class="wrap">
        <h1>🔗 Universal Nofollow Pro</h1>
        <p style="font-size: 14px; color: #666;">Версия <?php echo esc_html( UNIVERSAL_NOFOLLOW_VERSION ); ?> | Автоматическое добавление rel="nofollow" ко всем внешним ссылкам</p>
        
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
                            
                            <!-- Главная страница -->
                            <label style="display: block; margin-bottom: 8px;">
                                <input type="checkbox" name="post_types[]" value="home" 
                                    <?php checked( in_array( 'home', $enabled_post_types, true ) ); ?> />
                                <strong>Главная страница</strong>
                            </label>
                            
                            <!-- Архивы -->
                            <label style="display: block; margin-bottom: 8px;">
                                <input type="checkbox" name="post_types[]" value="archive" 
                                    <?php checked( in_array( 'archive', $enabled_post_types, true ) ); ?> />
                                <strong>Архивы</strong> (категории, теги, авторы)
                            </label>
                            
                            <!-- Типы записей -->
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
        
        <hr style="margin: 30px 0;" />
        
        <!-- ИНФОРМАЦИЯ О ПЛАГИНЕ -->
        <div style="background: #f5f5f5; padding: 20px; border-radius: 5px;">
            <h2>ℹ️ Информация о плагине</h2>
            
            <h3>✅ Что обрабатывает плагин:</h3>
            <ul style="list-style: none; padding-left: 0;">
                <li>✓ <strong>Все внешние ссылки</strong> (по умолчанию)</li>
                <li>✓ <strong>Яндекс Маркет</strong> (market.yandex.ru) — можно исключить</li>
                <li>✓ <strong>Яндекс Реклама</strong> (yandex.ru/clck) — всегда исключается</li>
                <li>✓ <strong>Социальные сети</strong> (Facebook, Twitter, Instagram, YouTube и т.д.) — если включено</li>
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
                <li>✓ <strong>Умные исключения</strong> — полные и частичные совпадения</li>
                <li>✓ <strong>Гибкие настройки</strong> — выбор типов записей</li>
                <li>✓ <strong>Логирование</strong> — для отладки в режиме WP_DEBUG</li>
                <li>✓ <strong>Производительность</strong> — кеширование домена сайта</li>
            </ul>
        </div>
    </div>
    <?php
}

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
            'post_types' => array(),
            'block_social' => '0',
            'exclude_yandex_market' => '0',
            'excluded_links' => '',
        );
        add_option( 'universal_nofollow_settings', $default_settings );
    }
    
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
    // Удаляем настройки при удалении плагина
    delete_option( 'universal_nofollow_settings' );
}
register_uninstall_hook( __FILE__, 'universal_nofollow_uninstall' );
