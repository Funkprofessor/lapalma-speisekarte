<?php
/**
 * Plugin Name: La Palma Menu Importer
 * Description: Import weekly menu from PDF/text and render via shortcode.
 * Version: 0.1.0
 * Author: La Palma
 */

if (!defined('ABSPATH')) {
    exit;
}

define('LAPALMA_MENU_OPTION', 'lapalma_menu_html');
define('LAPALMA_MENU_DATA_OPTION', 'lapalma_menu_data');
define('LAPALMA_MENU_UPDATED_OPTION', 'lapalma_menu_updated_at');
define('LAPALMA_MENU_DATE_OPTION', 'lapalma_menu_date');

add_action('admin_menu', 'lapalma_menu_importer_admin_menu');
function lapalma_menu_importer_admin_menu()
{
    add_menu_page(
        'La Palma Menu',
        'La Palma Menu',
        'manage_options',
        'lapalma-menu-importer',
        'lapalma_menu_importer_render_admin',
        'dashicons-media-document'
    );
}

add_action('wp_enqueue_scripts', 'lapalma_menu_enqueue_styles');
function lapalma_menu_enqueue_styles()
{
    wp_register_style(
        'lapalma-menu',
        plugins_url('assets/lapalma-menu.css', __FILE__),
        array(),
        '0.1.0'
    );
    wp_enqueue_style('lapalma-menu');
}

add_action('admin_enqueue_scripts', 'lapalma_menu_enqueue_admin_styles');
function lapalma_menu_enqueue_admin_styles($hook)
{
    if ($hook !== 'toplevel_page_lapalma-menu-importer') {
        return;
    }

    wp_enqueue_style(
        'lapalma-menu',
        plugins_url('assets/lapalma-menu.css', __FILE__),
        array(),
        '0.1.0'
    );
}

add_shortcode('lapalma_menu', 'lapalma_menu_shortcode');
function lapalma_menu_shortcode($atts = array())
{
    $data = get_option(LAPALMA_MENU_DATA_OPTION, '');
    if ($data === '') {
        return '<p>Keine Speisekarte vorhanden.</p>';
    }
    $decoded = json_decode($data, true);
    if (!is_array($decoded)) {
        return '<p>Keine Speisekarte vorhanden.</p>';
    }
    $atts = shortcode_atts(
        array(
            'section' => '',
            'show_date' => 'no',
            'show_title' => 'yes',
        ),
        array_change_key_case((array) $atts, CASE_LOWER)
    );

    $section = trim((string) $atts['section']);
    $show_date = strtolower((string) $atts['show_date']) === 'yes';
    $show_title = strtolower((string) $atts['show_title']) === 'yes';
    if ($section !== '' && !isset($atts['show_title'])) {
        $show_title = false;
    }
    return lapalma_menu_render_html($decoded, $section, $show_date, $show_title);
}

add_shortcode('lapalma_menu_date', 'lapalma_menu_date_shortcode');
function lapalma_menu_date_shortcode()
{
    $date = (string) get_option(LAPALMA_MENU_DATE_OPTION, '');
    if ($date === '') {
        return '';
    }
    return '<div class="lapalma-menu-date">' . esc_html($date) . '</div>';
}

add_shortcode('lapalma_menu_h1', 'lapalma_menu_h1_shortcode');
function lapalma_menu_h1_shortcode()
{
    $updated_at = (string) get_option(LAPALMA_MENU_UPDATED_OPTION, '');
    if ($updated_at === '') {
        return '';
    }

    $import_timestamp = strtotime($updated_at);
    if ($import_timestamp === false) {
        return '';
    }

    $import_date = date_i18n('j. F Y', $import_timestamp);
    return '<h1 class="lapalma-menu-h1">Vom ' . esc_html($import_date) . '</h1>';
}

function lapalma_menu_importer_render_admin()
{
    $notice = '';
    $notice_type = 'updated';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['lapalma_menu_import_submit'])) {
        if (!current_user_can('manage_options')) {
            wp_die('Keine Berechtigung.');
        }
        check_admin_referer('lapalma_menu_import');

        $text = '';
        $uploaded_filename = '';
        if (!empty($_FILES['lapalma_menu_pdf']) && isset($_FILES['lapalma_menu_pdf']['tmp_name'])) {
            $upload = lapalma_menu_handle_upload($_FILES['lapalma_menu_pdf']);
            if (isset($upload['error'])) {
                $notice = $upload['error'];
                $notice_type = 'error';
            } else {
                $text = lapalma_menu_extract_text_from_pdf($upload['file']);
                $uploaded_filename = basename($upload['file']);
                if ($text === '') {
                    $notice = 'PDF konnte nicht gelesen werden. Bitte Text einfügen.';
                    $notice_type = 'error';
                }
            }
        }

        if ($text === '') {
            $text = trim((string) wp_unslash($_POST['lapalma_menu_text'] ?? ''));
        }

        if ($text !== '' && $notice_type !== 'error') {
            $data = lapalma_menu_parse_text($text);
            update_option(LAPALMA_MENU_DATA_OPTION, wp_json_encode($data));
            update_option(LAPALMA_MENU_UPDATED_OPTION, current_time('mysql'));
            $date = lapalma_menu_format_date_from_import();
            update_option(LAPALMA_MENU_DATE_OPTION, $date);

            $notice = 'Speisekarte aktualisiert.';
            $notice_type = 'updated';
        } elseif ($notice === '') {
            $notice = 'Bitte eine PDF hochladen oder Text einfügen.';
            $notice_type = 'error';
        }
    }

    $last_updated = (string) get_option(LAPALMA_MENU_UPDATED_OPTION, '');
    $current_html = '';
    $stored = get_option(LAPALMA_MENU_DATA_OPTION, '');
    if ($stored !== '') {
        $decoded = json_decode($stored, true);
        if (is_array($decoded)) {
            $current_html = lapalma_menu_h1_shortcode() . lapalma_menu_render_html($decoded, '', false);
        }
    }

    if ($notice !== '') {
        echo '<div class="notice ' . esc_attr($notice_type) . '"><p>' . esc_html($notice) . '</p></div>';
    }
    echo '<div class="wrap">';
    echo '<h1>La Palma Menü Import</h1>';
    echo '<form method="post" enctype="multipart/form-data">';
    wp_nonce_field('lapalma_menu_import');
    echo '<table class="form-table" role="presentation">';
    echo '<tr><th scope="row"><label for="lapalma_menu_pdf">PDF Upload</label></th>';
    echo '<td><input type="file" id="lapalma_menu_pdf" name="lapalma_menu_pdf" accept="application/pdf"></td></tr>';
    echo '<tr><th scope="row"><label for="lapalma_menu_text">Oder Text einfügen</label></th>';
    echo '<td><textarea id="lapalma_menu_text" name="lapalma_menu_text" rows="12" class="large-text" placeholder="Hier Text aus der PDF einfügen..."></textarea></td></tr>';
    echo '</table>';
    submit_button('Importieren', 'primary', 'lapalma_menu_import_submit');
    echo '</form>';

    if ($last_updated !== '') {
        echo '<p>Letztes Update: <strong>' . esc_html($last_updated) . '</strong></p>';
    }
    if ($current_html !== '') {
        echo '<h2>Vorschau</h2>';
        echo $current_html;
    }
    echo '<hr>';
    echo '<p>Shortcodes: <code>[lapalma_menu]</code> (komplette Karte), <code>[lapalma_menu section="Vorspeisen" show_title="no"]</code> (ein Abschnitt ohne Überschrift), <code>[lapalma_menu_date]</code> (Datum), <code>[lapalma_menu_h1]</code> (H1: "Vom 27. Februar 2026")</p>';
    echo '</div>';
}

function lapalma_menu_handle_upload($file)
{
    if (!function_exists('wp_handle_upload')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    $overrides = array(
        'test_form' => false,
        'mimes' => array('pdf' => 'application/pdf'),
    );
    return wp_handle_upload($file, $overrides);
}

function lapalma_menu_extract_text_from_pdf($path)
{
    $text = '';
    if (function_exists('shell_exec')) {
        $pdftotext = trim((string) shell_exec('command -v pdftotext'));
        if ($pdftotext !== '') {
            $tmp = wp_tempnam('lapalma_menu');
            $cmd = escapeshellcmd($pdftotext) . ' -layout ' . escapeshellarg($path) . ' ' . escapeshellarg($tmp);
            @shell_exec($cmd);
            if (file_exists($tmp)) {
                $text = (string) file_get_contents($tmp);
                @unlink($tmp);
            }
        }
    }
    return trim($text);
}

function lapalma_menu_parse_text($text)
{
    $text = str_replace(array("\r\n", "\r"), "\n", $text);
    $lines = array_map('trim', explode("\n", $text));
    $lines = array_values(array_filter($lines, function ($line) {
        return $line !== '';
    }));

    $section_titles = array(
        'Vorspeisen',
        'Nudelgerichte',
        'Hauptgerichte',
        'Dolci',
        'Desserts',
        'Antipasti',
        'Pasta',
        'Secondi',
    );

    $sections = array();
    $current_section = null;
    $buffer = '';
    $legend_lines = array();

    $line_count = count($lines);
    for ($i = 0; $i < $line_count; $i++) {
        $line = $lines[$i];
        if (preg_match('/--\s*\d+\s*of\s*\d+--/i', $line)) {
            continue;
        }
        if (preg_match('/^A\b.*:\s*/', $line) && strpos($line, 'Glutenhaltiges') !== false) {
            continue;
        }
        if (in_array($line, $section_titles, true)) {
            if ($buffer !== '') {
                $sections = lapalma_menu_push_item($sections, $current_section, $buffer);
                $buffer = '';
            }
            $current_section = $line;
            if (!isset($sections[$current_section])) {
                $sections[$current_section] = array();
            }
            continue;
        }

        if ($current_section === null) {
            $current_section = 'Speisekarte';
            if (!isset($sections[$current_section])) {
                $sections[$current_section] = array();
            }
        }

        $buffer = $buffer === '' ? $line : $buffer . ' ' . $line;
        if (strpos($buffer, '€') !== false) {
            $sections = lapalma_menu_push_item($sections, $current_section, $buffer);
            $buffer = '';
        }
    }

    if ($buffer !== '') {
        if (strpos($buffer, '€') !== false) {
            $sections = lapalma_menu_push_item($sections, $current_section, $buffer);
        }
    }

    return array(
        'sections' => $sections,
        'legend' => '',
    );
}

function lapalma_menu_push_item($sections, $section, $line)
{
    $item = lapalma_menu_parse_item_line($line);
    if ($section === null) {
        $section = 'Speisekarte';
    }
    if (!isset($sections[$section])) {
        $sections[$section] = array();
    }
    $sections[$section][] = $item;
    return $sections;
}

function lapalma_menu_parse_item_line($line)
{
    $line = trim(preg_replace('/\s+/', ' ', $line));
    $price = '';
    $allergens = '';

    if (preg_match('/€\s*([0-9]+(?:[.,][0-9]{2})?)/', $line, $match)) {
        $price = $match[1];
    }
    if (preg_match('/\s([A-Z](?:\.[A-Z])+\.?)\s*€/', $line, $match)) {
        $allergens = $match[1];
    }

    $title = $line;
    if ($price !== '') {
        $title = preg_replace('/\s*[A-Z](?:\.[A-Z])+\.?\s*€\s*[0-9]+(?:[.,][0-9]{2})?/', '', $title);
        $title = preg_replace('/\s*€\s*[0-9]+(?:[.,][0-9]{2})?/', '', $title);
        $title = trim($title);
    }

    $detail = '';
    if ($title !== '') {
        if (strpos($title, '::') !== false) {
            $parts = array_map('trim', explode('::', $title, 2));
            $title = $parts[0] ?? '';
            $detail = $parts[1] ?? '';
        } else {
            $separators = array(' mit ', ' auf ', ' in ', ' con ', ' vom ', ' zur ', ' zu ', ' im ', ' alla ', ' al ');
            $best_pos = null;
            $best_sep = null;
            foreach ($separators as $sep) {
                $pos = lapalma_menu_find_separator($title, $sep);
                if ($pos !== null && ($best_pos === null || $pos < $best_pos)) {
                    $best_pos = $pos;
                    $best_sep = $sep;
                }
            }
            if ($best_pos !== null) {
                $detail = trim(lapalma_menu_substr($title, $best_pos));
                $title = trim(lapalma_menu_substr($title, 0, $best_pos));
            }
        }
    }

    if ($detail !== '') {
        $detail_allergens = lapalma_menu_extract_allergens($detail);
        if ($detail_allergens !== '') {
            $detail = trim(preg_replace('/\s+' . preg_quote($detail_allergens, '/') . '$/', '', $detail));
            $allergens = $detail_allergens;
        }
    }

    $price_formatted = '';
    if ($price !== '') {
        $value = (float) str_replace(',', '.', $price);
        $price_formatted = number_format($value, 2, ',', '.') . ' €';
        if (abs($value - round($value)) < 0.0001) {
            $price_formatted = number_format($value, 0, ',', '.') . ' €';
        }
    }

    return array(
        'title' => $title,
        'detail' => $detail,
        'price' => $price_formatted,
        'allergens' => $allergens,
    );
}

function lapalma_menu_find_separator($text, $separator)
{
    if (function_exists('mb_stripos')) {
        $pos = mb_stripos(' ' . $text . ' ', $separator);
        return $pos !== false ? $pos - 1 : null;
    }
    $pos = stripos(' ' . $text . ' ', $separator);
    return $pos !== false ? $pos - 1 : null;
}

function lapalma_menu_substr($text, $start, $length = null)
{
    if (function_exists('mb_substr')) {
        return $length === null ? mb_substr($text, $start) : mb_substr($text, $start, $length);
    }
    return $length === null ? substr($text, $start) : substr($text, $start, $length);
}

function lapalma_menu_strlen($text)
{
    return function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
}

function lapalma_menu_render_html($data, $section_filter = '', $show_date = false, $show_title = true)
{
    $sections = $data['sections'] ?? array();
    $date = $show_date ? (string) get_option(LAPALMA_MENU_DATE_OPTION, '') : '';

    $html = '<div class="lapalma-menu">';
    if ($date !== '') {
        $html .= '<div class="lapalma-menu-date">' . esc_html($date) . '</div>';
    }
    foreach ($sections as $title => $items) {
        if ($section_filter !== '' && strcasecmp($section_filter, $title) !== 0) {
            continue;
        }
        if ($show_title) {
            $html .= '<h2 class="lapalma-menu-section-title">' . esc_html($title) . '</h2>';
        }
        $html .= '<div class="lapalma-menu-section">';
        foreach ($items as $item) {
            $html .= '<div class="lapalma-menu-item">';
            $html .= '<div class="lapalma-menu-item-title">' . esc_html($item['title']) . '</div>';
            if (!empty($item['detail'])) {
                $html .= '<div class="lapalma-menu-item-detail">' . esc_html($item['detail']) . '</div>';
            }
            if (!empty($item['allergens'])) {
                $html .= '<div class="lapalma-menu-item-allergens">' . esc_html($item['allergens']) . '</div>';
            }
            if (!empty($item['price'])) {
                $html .= '<div class="lapalma-menu-item-price">' . esc_html($item['price']) . '</div>';
            }
            $html .= '</div>';
        }
        $html .= '</div>';
    }
    $html .= '</div>';

    return $html;
}

function lapalma_menu_extract_allergens($text)
{
    if (preg_match('/([A-Z](?:\.[A-Z])+\.?)$/', trim($text), $match)) {
        return $match[1];
    }
    return '';
}

function lapalma_menu_format_date_from_import()
{
    $timestamp = current_time('timestamp');
    return 'vom ' . date_i18n('j. F Y', $timestamp);
}
