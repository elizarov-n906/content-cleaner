<?php
/**
 * Plugin Name: Content Cleaner (Stopword Drafts)
 * Plugin URI: https://github.com/elizarov-n906/content-cleaner
 * Description: Переносит записи в черновики, если в заголовке или тексте есть стоп-слова. Настройка через админку.
 * Version: 3.0.0
 * Author: Elizarov_n906
 * Author URI: https://github.com/elizarov-n906
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: content-cleaner
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Content_Cleaner_Stopwords {

    public function __construct() {
        add_action( 'save_post', [ $this, 'check_and_draft_on_save' ], 20, 3 );
        add_action( 'admin_init', [ $this, 'handle_bulk_cleanup' ] );
        add_action( 'admin_menu', [ $this, 'add_admin_page' ] );
        add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), [ $this, 'add_settings_link' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_notices', [ $this, 'show_save_notice' ] );
    }

    public function show_save_notice() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( isset( $_GET['page'] ) && sanitize_text_field( wp_unslash( $_GET['page'] ) ) === 'content-cleaner' ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if ( isset( $_GET['settings-updated'] ) ) {
                echo '<div class="notice notice-success is-dismissible" style="margin-top: 20px;"><p><strong>' . esc_html__( 'Готово:', 'content-cleaner' ) . '</strong> ' . esc_html__( 'Настройки успешно сохранены!', 'content-cleaner' ) . '</p></div>';
            }
        }
    }

    public function register_settings() {
        register_setting( 'content_cleaner_group', 'content_cleaner_list', [ 'sanitize_callback' => 'sanitize_textarea_field' ] );
        register_setting( 'content_cleaner_group', 'content_cleaner_location', [ 'sanitize_callback' => 'sanitize_text_field' ] );
    }

    private function get_stop_words() {
        $list = get_option( 'content_cleaner_list', '' );
        if ( empty( $list ) ) return [];
        
        $words = preg_split( '/\r\n|\r|\n/', $list );
        $words = array_map( 'trim', $words );
        return array_filter( $words );
    }

    private function get_search_location() {
        $location = get_option( 'content_cleaner_location', 'title' );
        if ( ! in_array( $location, [ 'title', 'content', 'both' ], true ) ) {
            $location = 'title';
        }
        return $location;
    }

    public function check_and_draft_on_save( $post_id, $post, $update ) {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( wp_is_post_revision( $post_id ) ) return;
        if ( $post->post_status === 'draft' ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        $stop_words = $this->get_stop_words();
        if ( empty( $stop_words ) ) return;

        $location = $this->get_search_location();
        $found_word = $this->find_stop_word( $post->post_title, $post->post_content, $stop_words, $location );
        
        if ( $found_word ) {
            remove_action( 'save_post', [ $this, 'check_and_draft_on_save' ], 20 );
            
            wp_update_post( [
                'ID'          => $post_id,
                'post_status' => 'draft'
            ] );
            
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            update_post_meta( $post_id, '_moved_to_draft_by_stopword', 'Auto-draft by plugin. Found: ' . $found_word );
            
            add_action( 'save_post', [ $this, 'check_and_draft_on_save' ], 20, 3 );
        }
    }

    private function find_stop_word( $title, $content, $words, $location = 'title' ) {
        $title_l   = mb_strtolower( $title );
        $content_l = mb_strtolower( wp_strip_all_tags( $content ) );

        foreach ( $words as $word ) {
            $word_l = mb_strtolower( trim( $word ) );
            if ( empty( $word_l ) ) continue;

            $found_in_title   = ( $location === 'title' || $location === 'both' ) && mb_strpos( $title_l, $word_l ) !== false;
            $found_in_content = ( $location === 'content' || $location === 'both' ) && mb_strpos( $content_l, $word_l ) !== false;

            if ( $found_in_title || $found_in_content ) {
                return $word;
            }
        }
        return false;
    }

    public function handle_bulk_cleanup() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! isset( $_GET['run_stopword_cleanup'] ) || $_GET['run_stopword_cleanup'] !== 'yes' ) return;

        // КРИТИЧЕСКИ ВАЖНО: Проверка nonce для защиты от CSRF-атак
        check_admin_referer( 'content_cleaner_cleanup_action' );

        $stop_words = $this->get_stop_words();
        $location = $this->get_search_location();
        
        $location_labels = [
            'title'   => esc_html__( 'Только в заголовке', 'content-cleaner' ),
            'content' => esc_html__( 'Только в тексте', 'content-cleaner' ),
            'both'    => esc_html__( 'В заголовке и тексте', 'content-cleaner' ),
        ];
        
        $posts = get_posts([
            'post_type'      => 'any',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'fields'         => 'ids',
        ]);

        $count = 0;
        $checked_count = 0;
        $debug_log = [];
        
        foreach ( $posts as $post_id ) {
            $post = get_post( $post_id );
            $checked_count++;
            
            $found_word = $this->find_stop_word( $post->post_title, $post->post_content, $stop_words, $location );
            
            if ( $found_word ) {
                wp_update_post( [
                    'ID'          => $post_id,
                    'post_status' => 'draft'
                ] );
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                update_post_meta( $post_id, '_moved_to_draft_by_stopword', 'Bulk cleanup. Found: ' . $found_word );
                $count++;
                
                $debug_log[] = [
                    'post_id'    => $post_id,
                    'title'      => mb_substr( $post->post_title, 0, 50 ),
                    'found_word' => $found_word
                ];
            }
        }

        $report = "<h1>" . esc_html__( 'Детальный отчет', 'content-cleaner' ) . "</h1>";
        $report .= "<p><strong>" . esc_html__( 'Место поиска:', 'content-cleaner' ) . "</strong> " . esc_html( $location_labels[$location] ) . "</p>";
        $report .= "<p><strong>" . esc_html__( 'Загружено стоп-слов:', 'content-cleaner' ) . "</strong> " . count( $stop_words ) . "</p>";
        $report .= "<p><strong>" . esc_html__( 'Список слов:', 'content-cleaner' ) . "</strong></p><pre>" . esc_html( print_r( $stop_words, true ) ) . "</pre>";
        $report .= "<hr>";
        $report .= "<p><strong>" . esc_html__( 'Проверено записей:', 'content-cleaner' ) . "</strong> {$checked_count}</p>";
        $report .= "<p><strong>" . esc_html__( 'Переведено в черновики:', 'content-cleaner' ) . "</strong> <b>{$count}</b></p>";
        
        if ( ! empty( $debug_log ) ) {
            $report .= "<hr><h2>" . esc_html__( 'Детали по каждой записи:', 'content-cleaner' ) . "</h2>";
            $report .= "<table border='1' cellpadding='5' style='border-collapse: collapse; width: 100%;'>";
            $report .= "<tr><th>ID</th><th>" . esc_html__( 'Заголовок', 'content-cleaner' ) . "</th><th>" . esc_html__( 'Найденное слово', 'content-cleaner' ) . "</th></tr>";
            foreach ( $debug_log as $log ) {
                $report .= "<tr>";
                $report .= "<td>" . intval( $log['post_id'] ) . "</td>";
                $report .= "<td>" . esc_html( $log['title'] ) . "...</td>";
                $report .= "<td><strong>" . esc_html( $log['found_word'] ) . "</strong></td>";
                $report .= "</tr>";
            }
            $report .= "</table>";
        }
        
        $report .= "<hr><p><a href='" . esc_url( admin_url( 'index.php?page=content-cleaner' ) ) . "' class='button'>" . esc_html__( 'Вернуться к настройкам плагина', 'content-cleaner' ) . "</a></p>";

        wp_die( $report ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    public function add_admin_page() {
        add_submenu_page(
            'index.php',
            esc_html__( 'Настройки Стоп-слов', 'content-cleaner' ),
            esc_html__( 'Стоп-слова', 'content-cleaner' ),
            'manage_options',
            'content-cleaner',
            [ $this, 'render_page' ]
        );
    }

    public function render_page() {
        $current_location = get_option( 'content_cleaner_location', 'title' );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Настройки плагина Content Cleaner', 'content-cleaner' ); ?></h1>
            
            <form method="post" action="options.php">
                <?php settings_fields( 'content_cleaner_group' ); ?>
                <?php do_settings_sections( 'content_cleaner_group' ); ?>
                
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e( 'Список стоп-слов', 'content-cleaner' ); ?></th>
                        <td>
                            <textarea name="content_cleaner_list" rows="10" cols="50" class="large-text code" placeholder="нейросеть&#10;языковая модель&#10;chatgpt"><?php echo esc_textarea( get_option( 'content_cleaner_list' ) ); ?></textarea>
                            <p class="description"><?php esc_html_e( 'Каждое слово или фраза с новой строки. Фразы с пробелами работают.', 'content-cleaner' ); ?></p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e( 'Где искать стоп-слова', 'content-cleaner' ); ?></th>
                        <td>
                            <fieldset>
                                <label>
                                    <input type="radio" name="content_cleaner_location" value="title" <?php checked( $current_location, 'title' ); ?>>
                                    <strong><?php esc_html_e( 'Только в заголовке', 'content-cleaner' ); ?></strong> <em>(<?php esc_html_e( 'по умолчанию', 'content-cleaner' ); ?>)</em>
                                </label>
                                <br>
                                <label>
                                    <input type="radio" name="content_cleaner_location" value="content" <?php checked( $current_location, 'content' ); ?>>
                                    <?php esc_html_e( 'Только в тексте записи', 'content-cleaner' ); ?>
                                </label>
                                <br>
                                <label>
                                    <input type="radio" name="content_cleaner_location" value="both" <?php checked( $current_location, 'both' ); ?>>
                                    <?php esc_html_e( 'В заголовке и в тексте', 'content-cleaner' ); ?>
                                </label>
                            </fieldset>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button( esc_html__( 'Сохранить изменения', 'content-cleaner' ) ); ?>
            </form>

            <hr style="margin: 40px 0;">

            <h2><?php esc_html_e( 'Массовая проверка старых записей', 'content-cleaner' ); ?></h2>
            <p><?php esc_html_e( 'Покажет детальную информацию: какая запись, какое слово найдено.', 'content-cleaner' ); ?></p>
            
            <?php 
            // Генерируем безопасную ссылку с nonce
            $cleanup_url = wp_nonce_url( 
                admin_url( '?run_stopword_cleanup=yes' ), 
                'content_cleaner_cleanup_action' 
            );
            ?>
            <a href="<?php echo esc_url( $cleanup_url ); ?>" 
               class="button button-primary button-hero" 
               onclick="return confirm('<?php echo esc_js( __( 'Все записи со стоп-словами будут переведены в черновики. Продолжить?', 'content-cleaner' ) ); ?>');">
               <?php esc_html_e( 'Запустить массовую проверку', 'content-cleaner' ); ?>
            </a>

            <p style="margin-top: 50px; color: #666; font-size: 14px; border-top: 1px solid #ccc; padding-top: 15px;">
                <?php printf( esc_html__( 'Автор плагина: %s', 'content-cleaner' ), 'elizarov_n906' ); ?>
            </p>
        </div>
        <?php
    }

    public function add_settings_link( $links ) {
        $url = admin_url( 'index.php?page=content-cleaner' );
        $link = '<a href="' . esc_url( $url ) . '" style="font-weight: bold;">' . esc_html__( 'Настройки', 'content-cleaner' ) . '</a>';
        array_unshift( $links, $link );
        return $links;
    }
}

new Content_Cleaner_Stopwords();