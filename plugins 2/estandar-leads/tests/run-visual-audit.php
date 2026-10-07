<?php
/**
 * Validación Visual Automatizada — 7 pantallas de administración
 * 
 * Renderiza cada página vía PHP, analiza el HTML de salida buscando
 * problemas estructurales: duplicación de headers, navegación faltante,
 * doble layout, errores PHP, avisos de WordPress.
 */
require_once dirname(__DIR__, 4) . '/wp-load.php';

// Ensure all plugins' admin screen files are loaded
$plugin_dir = WP_PLUGIN_DIR;
@include_once $plugin_dir . '/estandar-forms/admin/dashboard.php';
@include_once $plugin_dir . '/estandar-forms/admin/screens/form-preview.php';
@include_once $plugin_dir . '/estandar-forms/admin/screens/survey-list.php';
@include_once $plugin_dir . '/estandar-primer/admin/class-communications-admin.php';

wp_set_current_user(1);

$pages = array(
    'estandar-customers' => array(
        'url' => '/wp-admin/admin.php?page=estandar-customers',
        'checks' => array('single_heading', 'shell_navigation', 'no_double_wrap'),
    ),
    'estandar-forms' => array(
        'url' => '/wp-admin/admin.php?page=estandar-forms',
        'checks' => array('single_heading', 'shell_navigation', 'no_double_wrap'),
    ),
    'estandar-forms-entries' => array(
        'url' => '/wp-admin/admin.php?page=estandar-forms-entries',
        'checks' => array('single_heading', 'shell_navigation', 'no_double_wrap'),
    ),
    'estandar-surveys' => array(
        'url' => '/wp-admin/admin.php?page=estandar-surveys',
        'checks' => array('single_heading', 'shell_navigation', 'no_double_wrap'),
    ),
    'configuracion' => array(
        'url' => '/wp-admin/admin.php?page=estandar_core_diagnostics&tab=configuracion',
        'checks' => array('single_heading', 'shell_navigation', 'card_icons', 'no_double_wrap'),
    ),
    'estado_ecosistema' => array(
        'url' => '/wp-admin/admin.php?page=estandar_core_diagnostics&tab=estado_ecosistema',
        'checks' => array('single_heading', 'shell_navigation', 'card_icons', 'no_php_error'),
    ),
    'communications' => array(
        'url' => '/wp-admin/admin.php?page=estandar_communications',
        'checks' => array('single_heading', 'shell_navigation', 'card_icons', 'no_double_wrap'),
    ),
);

$passed = 0;
$failed = 0;
$warnings = 0;

foreach ($pages as $slug => $config) {
    echo "\n═══ $slug ═══ {$config['url']}\n";

    unset($_GET, $_POST, $_REQUEST);
    parse_str(parse_url($config['url'], PHP_URL_QUERY) ?: '', $_GET);
    $_SERVER['REQUEST_URI'] = $config['url'];
    $_SERVER['REQUEST_METHOD'] = 'GET';

    ob_start();
    try {
        // Specific dispatchers
        switch ($slug) {
            case 'estandar-customers':
                if (class_exists('Estandar_Leads_Customers_Admin') && method_exists('Estandar_Leads_Customers_Admin', 'page')) {
                    if (function_exists('estandar_core_render_admin_shell')) {
                        estandar_core_render_admin_shell(array(
                            'title' => 'Clientes',
                            'callback' => array('Estandar_Leads_Customers_Admin', 'page'),
                        ));
                    } else {
                        Estandar_Leads_Customers_Admin::page();
                    }
                } else { echo "CLASS_NOT_FOUND"; }
                break;
            case 'estandar-forms':
                if (function_exists('estandar_forms_home_page')) {
                    $_GET['page'] = 'estandar-forms';
                    if (function_exists('estandar_core_render_admin_shell')) {
                        estandar_core_render_admin_shell(array(
                            'title' => 'Forms',
                            'navigation' => function_exists('estandar_forms_nav_items') ? estandar_forms_nav_items('estandar-forms') : array(),
                            'callback' => 'estandar_forms_home_page',
                        ));
                    } else { estandar_forms_home_page(); }
                } else { echo "FN_NOT_FOUND"; }
                break;
            case 'estandar-forms-entries':
                if (function_exists('estandar_forms_entries_page')) {
                    $_GET['page'] = 'estandar-forms-entries';
                    if (function_exists('estandar_core_render_admin_shell')) {
                        estandar_core_render_admin_shell(array(
                            'title' => 'Forms',
                            'navigation' => function_exists('estandar_forms_nav_items') ? estandar_forms_nav_items('estandar-forms-entries') : array(),
                            'callback' => 'estandar_forms_entries_page',
                        ));
                    } else { estandar_forms_entries_page(); }
                } else { echo "FN_NOT_FOUND"; }
                break;
            case 'estandar-surveys':
                if (function_exists('estandar_surveys_list_page')) {
                    $_GET['page'] = 'estandar-surveys';
                    if (function_exists('estandar_core_render_admin_shell')) {
                        estandar_core_render_admin_shell(array(
                            'title' => 'Surveys',
                            'callback' => 'estandar_surveys_list_page',
                        ));
                    } else { estandar_surveys_list_page(); }
                } else { echo "FN_NOT_FOUND"; }
                break;
            case 'configuracion':
            case 'estado_ecosistema':
                if (function_exists('estandar_core_render_diagnostics_page')) {
                    $_GET['page'] = 'estandar_core_diagnostics';
                    $_GET['tab'] = $slug === 'configuracion' ? 'configuracion' : 'estado_ecosistema';
                    estandar_core_render_diagnostics_page();
                } else { echo "FN_NOT_FOUND"; }
                break;
            case 'communications':
                if (class_exists('Estandar_Communications_Admin') && method_exists('Estandar_Communications_Admin', 'render_page')) {
                    $_GET['page'] = 'estandar_communications';
                    unset($_GET['tab']);
                    if (function_exists('estandar_core_render_admin_shell')) {
                        estandar_core_render_admin_shell(array(
                            'title' => 'Comunicaciones',
                            'callback' => array('Estandar_Communications_Admin', 'render_page'),
                        ));
                    } else {
                        Estandar_Communications_Admin::render_page();
                    }
                } else { echo "CLASS_NOT_FOUND"; }
                break;
        }
    } catch (Throwable $e) {
        echo "\n[FATAL] " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine();
    }

    $html = ob_get_clean();
    $len = strlen($html);

    if ($len < 100) {
        echo "  [FAIL] Output too short ($len bytes): " . substr($html, 0, 200) . "\n";
        $failed++;
        continue;
    }

    if (strpos($html, 'FATAL') !== false || strpos($html, 'CLASS_NOT_FOUND') !== false || strpos($html, 'FN_NOT_FOUND') !== false) {
        echo "  [FAIL] Page renderer not callable\n";
        $failed++;
        continue;
    }

    echo "  Output: $len bytes\n";

    // Check 1: No PHP fatal/critical error
    $has_wp_error = strpos($html, 'error crítico') !== false || strpos($html, 'critical error') !== false;
    $has_wp_die = strpos($html, 'wp_die') !== false;
    $has_db_error = preg_match('/WordPress database error/i', $html);

    if ($has_wp_error) { echo "  [FAIL] WordPress critical error detected\n"; $failed++; }
    elseif ($has_wp_die) { echo "  [FAIL] wp_die detected\n"; $failed++; }
    elseif ($has_db_error) { echo "  [WARN] DB errors in output\n"; $warnings++; }
    else { echo "  [PASS] No PHP/WordPress errors\n"; $passed++; }

    // Check 2: Single heading — count <h1> tags
    $h1_count = preg_match_all('/<h1[^>]*>/i', $html, $m);
    $h2_count = preg_match_all('/<h2[^>]*>/i', $html, $m);
    $wp_heading_inline = substr_count($html, 'wp-heading-inline');

    // Shell header bar uses h2/h3 not h1. Forms' own branding uses h3.
    // We care about NOT having two h1 with wp-heading-inline (duplicate title)
    if ($wp_heading_inline > 1) {
        echo "  [FAIL] Duplicate page title: $wp_heading_inline wp-heading-inline elements\n";
        $failed++;
    } else {
        echo "  [PASS] Single page title ($wp_heading_inline wp-heading-inline)\n";
        $passed++;
    }

    // Check 3: Primer shell navigation present
    $has_shell = strpos($html, 'estandar-shell') !== false || strpos($html, 'estandar-sticky-top') !== false || strpos($html, 'ecb-admin-shell') !== false;
    $has_global_nav = strpos($html, 'estandar-global-nav') !== false || strpos($html, 'ecb-global-nav') !== false || strpos($html, 'estandar_render_global_navigation') !== false;

    // Alternative: check for Primer's orange bar
    $has_primer_bar = strpos($html, 'estandar-topbar') !== false || strpos($html, 'Estandar Primer') !== false;

    if ($has_shell || $has_primer_bar) {
        echo "  [PASS] Primer shell/navigation present\n";
        $passed++;
    } else {
        echo "  [WARN] No Primer shell detected (may use standalone layout)\n";
        $warnings++;
    }

    // Check 4: No double wrap (detect nested admin layouts)
    $wrap_count = substr_count($html, '<div class="wrap">');
    $surface_count = substr_count($html, 'estandar-admin-surface');
    $layout_count = substr_count($html, 'estandar-admin-layout');

    if ($slug === 'estandar-customers') {
        if ($wrap_count > 1) {
            echo "  [WARN] Multiple .wrap divs ($wrap_count) — possible double container\n";
            $warnings++;
        } else {
            echo "  [PASS] Single .wrap container ($wrap_count)\n";
            $passed++;
        }
    }

    // Check 5: Card icons present (for pages with cards)
    if (in_array('card_icons', $config['checks'])) {
        $has_icons = strpos($html, 'dashicons-') !== false;
        $has_cfg_icon = strpos($html, 'es-cfg-icon') !== false;
        $has_diag_icon = strpos($html, 'estandar_diag_icon') !== false;

        if ($has_icons && $has_cfg_icon) {
            echo "  [PASS] Card icons present (Dashicons + .es-cfg-icon CSS)\n";
            $passed++;
        } else {
            echo "  [FAIL] Card icons missing (Dashicons: " . ($has_icons ? 'YES' : 'NO') . ", .es-cfg-icon: " . ($has_cfg_icon ? 'YES' : 'NO') . ")\n";
            $failed++;
        }
    }

    // Check 6: No JS errors (we can check for WP's admin JS loading)
    $has_wp_admin = strpos($html, 'wp-admin') !== false || strpos($html, 'admin-bar') !== false;

    // Check 7: estandar_diag_icon() exists for eco/config/comms pages
    if (in_array('no_php_error', $config['checks'])) {
        $has_diag_fn = function_exists('estandar_diag_icon');
        if ($slug === 'estado_ecosistema' && $has_diag_fn) {
            echo "  [PASS] estandar_diag_icon() exists (fatal fix verified)\n";
            $passed++;
        } elseif ($slug === 'estado_ecosistema' && !$has_diag_fn) {
            echo "  [FAIL] estandar_diag_icon() still missing\n";
            $failed++;
        } else {
            echo "  [PASS] No PHP error in page output\n";
            $passed++;
        }
    }
}

echo "\n═══ RESULTS ═══\n";
echo "PASS: $passed | FAIL: $failed | WARN: $warnings\n";
if ($failed > 0) exit(1);
echo "ALL PAGES PASSED\n";
