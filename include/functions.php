<?php
require_once __DIR__ . '/config.php';

// clean user input, also accepts command line key=value pairs for cron jobs
function get_input($id, $default="", $html=false) {
  if(!isset($_REQUEST[$id]) && php_sapi_name() === "cli") {
    global $argv;
    foreach((array)$argv as $arg) {
      if(is_string($arg) && preg_match("/^" . preg_quote($id, "/") . "=(.*)$/s", $arg, $matches)) {
        $_REQUEST[$id] = $matches[1];
        break;
      }
    }
  }
  if(!$html)
    $input = isset($_REQUEST[$id]) ? $_REQUEST[$id] : $default;
  else
    $input = isset($_REQUEST[$id]) ? htmlspecialchars($_REQUEST[$id]) : htmlspecialchars($default);
  $input = strip_tags( $input );
  return $input;
}

// clean user input
function get_source($id, $default="") {
  $input = isset($_REQUEST[$id]) ? $_REQUEST[$id] : $default;
  return $input;
}

function console_log($data) {
  global $g_debug_mode;
  if($g_debug_mode == false)
    return;
  $myfile = fopen(".\log.txt", "a");
  $data = __DIR__.": ".$data."\r";
  fwrite($myfile, $data);
  fclose($myfile);
}

function EF($result, $field) {
    return htmlspecialchars($result[$field], ENT_QUOTES);
}

function ES($string) {
    return htmlspecialchars($string, ENT_QUOTES);
}

function DS($string) {
  return htmlspecialchars_decode($string, ENT_QUOTES);
}

function get_color_definitions() {
    $defs = [
        'tab_strip_bg'         => ['label' => 'Tab Strip Background',         'default' => 'rgb(161, 161, 161)'],
        'tab_inactive_bg'      => ['label' => 'Inactive Tab Background',      'default' => 'rgb(210, 210, 210)'],
        'tab_active_bg'        => ['label' => 'Active Tab Background',        'default' => 'white'],
        'tab_border'           => ['label' => 'Tab Border',                   'default' => 'rgb(200, 200, 200)'],
        'tab_hover'            => ['label' => 'Tab Button Hover',             'default' => 'rgb(200, 200, 200)'],
        'tab_close_hover'      => ['label' => 'Tab Close Hover',              'default' => 'rgb(200, 200, 200)'],
        'spinner_border'       => ['label' => 'Loading Spinner Track',        'default' => '#d0d0d0'],
        'spinner_top'          => ['label' => 'Loading Spinner Arc',          'default' => '#606060'],
        'win_btn_hover'        => ['label' => 'Window Button Hover',          'default' => 'rgba(255, 255, 255, 0.1)'],
        'win_btn_close_hover'  => ['label' => 'Close Button Hover',           'default' => 'rgb(196, 43, 28)'],
        'win_btn_icon'         => ['label' => 'Window Button Icon',           'default' => 'white'],
        'controls_bg'          => ['label' => 'Toolbar Background',           'default' => 'white'],
        'btn_hover'            => ['label' => 'Toolbar Button Hover',         'default' => 'rgb(200, 200, 200)'],
        'btn_primary_bg'       => ['label' => 'Primary Button',               'default' => 'rgb(0, 112, 198)'],
        'btn_primary_text'     => ['label' => 'Primary Button Text',          'default' => 'white'],
        'btn_primary_hover'    => ['label' => 'Primary Button Hover',         'default' => 'rgb(0, 97, 171)'],
        'addr_bg'              => ['label' => 'Address Bar Background',       'default' => 'white'],
        'addr_border'          => ['label' => 'Address Bar Border',           'default' => 'gray'],
        'addr_focus'           => ['label' => 'Address Bar Focus Ring',       'default' => 'dodgerblue'],
        'addr_sec_text'        => ['label' => 'Security Label Text',          'default' => 'gray'],
        'addr_sec_insecure'    => ['label' => 'Insecure Text Color',          'default' => 'rgb(192, 0, 0)'],
        'addr_fav_hover'       => ['label' => 'Favorite Button Hover',        'default' => 'rgb(230, 230, 230)'],
        'option_bg'            => ['label' => 'Dropdown Item Background',     'default' => 'rgb(240, 240, 240)'],
        'option_hover'         => ['label' => 'Dropdown Item Hover',          'default' => 'rgb(220, 220, 220)'],
        'page_bg'              => ['label' => 'Content Page Background',      'default' => 'rgb(240, 240, 242)'],
        'text_primary'         => ['label' => 'Primary Text Color',           'default' => 'rgb(16, 16, 16)'],
        'text_secondary'       => ['label' => 'Secondary Text Color',         'default' => 'rgb(115, 115, 115)'],
        'link_color'           => ['label' => 'Link / Action Color',          'default' => 'rgb(0, 97, 171)'],
        'card_bg'              => ['label' => 'Card / Entry Background',      'default' => 'rgb(255, 255, 255)'],
        'card_shadow_dark'     => ['label' => 'Card Shadow (Dark)',           'default' => 'rgba(0, 0, 0, 0.13)'],
        'card_shadow_light'    => ['label' => 'Card Shadow (Light)',          'default' => 'rgba(0, 0, 0, 0.11)'],
        'drag_color'           => ['label' => 'Drag Handle Color',            'default' => 'rgb(180, 180, 180)'],
        'drag_hover'           => ['label' => 'Drag Handle Hover',            'default' => 'rgb(100, 100, 100)'],
        'close_btn_hover'      => ['label' => 'Close Button Hover',           'default' => 'rgb(243, 243, 243)'],
        'overlay_bg'           => ['label' => 'Modal Overlay',                'default' => 'rgba(0, 0, 0, 0.2)'],
        'prompt_bg'            => ['label' => 'Prompt Box Background',        'default' => 'white'],
        'prompt_confirm_bg'    => ['label' => 'Confirm Button',               'default' => 'rgb(0, 112, 198)'],
        'prompt_cancel_bg'     => ['label' => 'Cancel Button',                'default' => 'rgb(210, 210, 210)'],
        'settings_hover'       => ['label' => 'Settings Entry Hover',         'default' => 'rgb(220, 220, 220)'],
        'drag_over_border'     => ['label' => 'Drag Over Border',             'default' => 'rgb(0, 112, 198)'],
    ];
    return load_defaults_from_sources($defs);
}

function load_defaults_from_sources($defs) {
    global $g_base_dir;
    // Get source mappings: [key => [rel_path, context_before, prop_name]]
    $color_sources = get_color_source_map();
    $is_color = false;
    foreach ($defs as $key => $v) {
        if (isset($color_sources[$key])) { $is_color = true; break; }
    }

    foreach ($defs as $key => &$def) {
        if ($is_color) {
            if (!isset($color_sources[$key])) continue;
            list($rel_path, $context, $prop) = $color_sources[$key];
        } else {
            if (!isset($def['source'])) continue;
            list($rel_path, $context, $prop) = $def['source'];
        }
        $val = extract_css_value($rel_path, $context, $prop);
        if ($val !== null) $def['default'] = $val;
    }
    unset($def);
    return $defs;
}

function extract_css_value($rel_path, $context, $prop) {
    global $g_base_dir;
    $full = $g_base_dir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, substr($rel_path, strlen('browser_ui/')));
    if (!file_exists($full)) return null;
    $content = @file_get_contents($full);
    if ($content === false) return null;
    $content = str_replace("\r\n", "\n", $content);

    // Find context in content
    $pos = strpos($content, $context);
    if ($pos === false) return null;

    // Look for "prop: value;" after context
    $after = substr($content, $pos + strlen($context));
    if (preg_match('/' . preg_quote($prop, '/') . '\s*:\s*([^;]+);/', $after, $m)) {
        return trim($m[1]);
    }
    return null;
}

function get_color_source_map() {
    return [
        'tab_strip_bg'      => ['browser_ui/controls_ui/strip.css',      '#tabs-strip',              'background-color'],
        'tab_inactive_bg'   => ['browser_ui/controls_ui/strip.css',      'tab, .tab-active',         'background-color'],
        'tab_active_bg'     => ['browser_ui/controls_ui/strip.css',      "}\n\n.tab-active",         'background-color'],
        'tab_border'        => ['browser_ui/controls_ui/strip.css',      'tab, .tab-active',         'border-right'],
        'tab_hover'         => ['browser_ui/controls_ui/strip.css',      'btn-tab-close:hover',      'background-color'],
        'tab_close_hover'   => ['browser_ui/controls_ui/strip.css',      'btn-tab-close:hover',      'background-color'],
        'spinner_border'    => ['browser_ui/controls_ui/strip.css',      'tab-favicon.loading',      'border'],
        'spinner_top'       => ['browser_ui/controls_ui/strip.css',      'tab-favicon.loading',      'border-top-color'],
        'win_btn_hover'     => ['browser_ui/controls_ui/strip.css',      '.btn-window:hover',        'background-color'],
        'win_btn_close_hover' => ['browser_ui/controls_ui/strip.css',    'btn-window-close:hover',   'background-color'],
        'win_btn_icon'      => ['browser_ui/controls_ui/strip.css',      ".btn-window {\n    display", 'color'],
        'controls_bg'       => ['browser_ui/controls_ui/controls.css',   '#controls-bar',            'background-color'],
        'btn_hover'         => ['browser_ui/controls_ui/controls.css',   'btn:hover,',               'background-color'],
        'btn_primary_text'  => ['browser_ui/content_ui/favorites.css',   '#prompt-true',             'color'],
        'addr_bg'           => ['browser_ui/controls_ui/address-bar.css', 'address-bar-container',    'background-color'],
        'addr_border'       => ['browser_ui/controls_ui/address-bar.css', 'address-bar-container',    'border'],
        'addr_focus'        => ['browser_ui/controls_ui/address-bar.css', 'address-bar-container:focus-within', 'box-shadow'],
        'addr_sec_text'     => ['browser_ui/controls_ui/address-bar.css', 'security-label span',     'color'],
        'addr_sec_insecure' => ['browser_ui/controls_ui/address-bar.css', 'label-insecure span',     'color'],
        'addr_fav_hover'    => ['browser_ui/controls_ui/address-bar.css', '#btn-fav:hover',          'background-color'],
        'option_bg'         => ['browser_ui/controls_ui/options.css',     'dropdown-item',            'background-color'],
        'option_hover'      => ['browser_ui/controls_ui/options.css',     'dropdown-item:hover',      'background-color'],
        'page_bg'           => ['browser_ui/content_ui/styles.css',       'html, body',               'background-color'],
        'text_primary'      => ['browser_ui/content_ui/styles.css',       'main-title',               'color'],
        'text_secondary'    => ['browser_ui/content_ui/items.css',        'label-uri,',               'color'],
        'link_color'        => ['browser_ui/content_ui/favorites.css',    'btn-clear-favorites',      'color'],
        'card_bg'           => ['browser_ui/content_ui/items.css',        'box-shadow: rgba',         'background'],
        'card_shadow_dark'  => ['browser_ui/content_ui/items.css',        'box-shadow: rgba',         'box-shadow'],
        'card_shadow_light' => ['browser_ui/content_ui/items.css',        'box-shadow: rgba',         'box-shadow'],
        'drag_color'        => ['browser_ui/content_ui/items.css',        'drag-handle',              'color'],
        'drag_hover'        => ['browser_ui/content_ui/items.css',        'drag-handle:hover',        'color'],
        'close_btn_hover'   => ['browser_ui/content_ui/items.css',        'btn-close:hover',          'background-color'],
        'overlay_bg'        => ['browser_ui/content_ui/history.css',      '#overlay',                 'background-color'],
        'prompt_bg'         => ['browser_ui/content_ui/favorites.css',    '#prompt-box',              'background-color'],
        'prompt_confirm_bg' => ['browser_ui/content_ui/favorites.css',    '#prompt-true',             'background-color'],
        'prompt_cancel_bg'  => ['browser_ui/content_ui/favorites.css',    '#prompt-false',            'background-color'],
        'settings_hover'    => ['browser_ui/content_ui/settings.css',     'entry:hover',              'background-color'],
        'drag_over_border'  => ['browser_ui/content_ui/items.css',        'drag-over .item',          'box-shadow'],
    ];
}

function get_color_replacements($colors) {
    $defs = get_color_definitions();

    $c = [];
    foreach ($defs as $key => $def) {
        $c[$key] = isset($colors[$key]) && trim($colors[$key]) !== '' ? trim($colors[$key]) : $def['default'];
    }

    return [
        // strip.css
        ['browser_ui/controls_ui/strip.css', 'rgb(161, 161, 161)', $c['tab_strip_bg']],
        ['browser_ui/controls_ui/strip.css', 'rgb(210, 210, 210)', $c['tab_inactive_bg']],
        // active tab bg: "background-color: white;" with space before closing
        ['browser_ui/controls_ui/strip.css', '.tab-active {', ".tab-active {"],
        ['browser_ui/controls_ui/strip.css', "background-color: white;\n    border-top-left-radius", "background-color: {$c['tab_active_bg']};\n    border-top-left-radius"],
        // tab border
        ['browser_ui/controls_ui/strip.css', 'border-right: 1px solid rgb(200, 200, 200)', 'border-right: 1px solid ' . $c['tab_border']],
        // tab hover states
        ['browser_ui/controls_ui/strip.css', '#btn-new-tab:hover, .btn-tab-close:hover {', '#btn-new-tab:hover, .btn-tab-close:hover {'],
        ['browser_ui/controls_ui/strip.css', 'background-color: rgb(200, 200, 200);' . "\n}", 'background-color: ' . $c['tab_hover'] . ";\n}"],
        // spinner
        ['browser_ui/controls_ui/strip.css', 'border: 2px solid #d0d0d0', 'border: 2px solid ' . $c['spinner_border']],
        ['browser_ui/controls_ui/strip.css', 'border-top-color: #606060', 'border-top-color: ' . $c['spinner_top']],
        // window buttons
        ['browser_ui/controls_ui/strip.css', "background: transparent;\n    color: white;", "background: transparent;\n    color: {$c['win_btn_icon']};"],
        ['browser_ui/controls_ui/strip.css', "fill: white;\n    stroke: white;", "fill: {$c['win_btn_icon']};\n    stroke: {$c['win_btn_icon']};"],
        ['browser_ui/controls_ui/strip.css', 'rgba(255, 255, 255, 0.1)', $c['win_btn_hover']],
        ['browser_ui/controls_ui/strip.css', 'rgb(196, 43, 28)', $c['win_btn_close_hover']],
        // tab-active ::before/::after bg
        ['browser_ui/controls_ui/strip.css', "background: white;\n}\n\n.tab-active::before", "background: {$c['tab_active_bg']};\n}\n\n.tab-active::before"],

        // controls.css
        ['browser_ui/controls_ui/controls.css', 'background-color: white;', 'background-color: ' . $c['controls_bg'] . ';'],
        ['browser_ui/controls_ui/controls.css', 'background-color: rgb(200, 200, 200);', 'background-color: ' . $c['btn_hover'] . ';'],

        // options.css
        ['browser_ui/controls_ui/options.css', 'rgb(240, 240, 240)', $c['option_bg']],
        ['browser_ui/controls_ui/options.css', 'rgb(220, 220, 220)', $c['option_hover']],

        // address-bar.css
        ['browser_ui/controls_ui/address-bar.css', "background-color: white;\n    border: 1px solid gray;", "background-color: {$c['addr_bg']};\n    border: 1px solid {$c['addr_border']};"],
        ['browser_ui/controls_ui/address-bar.css', 'dodgerblue', $c['addr_focus']],
        ['browser_ui/controls_ui/address-bar.css', "color: gray;\n    vertical-align", "color: {$c['addr_sec_text']};\n    vertical-align"],
        ['browser_ui/controls_ui/address-bar.css', 'rgb(192, 0, 0)', $c['addr_sec_insecure']],
        ['browser_ui/controls_ui/address-bar.css', 'rgb(230, 230, 230)', $c['addr_fav_hover']],

        // content_ui/styles.css
        ['browser_ui/content_ui/styles.css', 'rgb(240, 240, 242)', $c['page_bg']],
        ['browser_ui/content_ui/styles.css', 'rgb(16, 16, 16)', $c['text_primary']],

        // items.css
        ['browser_ui/content_ui/items.css', 'rgb(0, 112, 198)', $c['drag_over_border']],
        ['browser_ui/content_ui/items.css', 'rgba(0, 0, 0, 0.13)', $c['card_shadow_dark']],
        ['browser_ui/content_ui/items.css', 'rgba(0, 0, 0, 0.11)', $c['card_shadow_light']],
        ['browser_ui/content_ui/items.css', 'rgb(255, 255, 255)', $c['card_bg']],
        ['browser_ui/content_ui/items.css', 'rgb(180, 180, 180)', $c['drag_color']],
        ['browser_ui/content_ui/items.css', 'rgb(100, 100, 100)', $c['drag_hover']],
        ['browser_ui/content_ui/items.css', 'rgb(16, 16, 16)', $c['text_primary']],
        ['browser_ui/content_ui/items.css', 'rgb(115, 115, 115)', $c['text_secondary']],
        ['browser_ui/content_ui/items.css', 'rgb(243, 243, 243)', $c['close_btn_hover']],

        // favorites.css
        ['browser_ui/content_ui/favorites.css', 'rgb(0, 97, 171)', $c['link_color']],
        ['browser_ui/content_ui/favorites.css', 'rgba(0, 0, 0, 0.2)', $c['overlay_bg']],
        ['browser_ui/content_ui/favorites.css', "background-color: white;\n\n    box-shadow", "background-color: {$c['prompt_bg']};\n\n    box-shadow"],
        ['browser_ui/content_ui/favorites.css', 'rgba(0, 0, 0, 0.13) 0px 1.6px 20px', $c['card_shadow_dark'] . ' 0px 1.6px 20px'],
        ['browser_ui/content_ui/favorites.css', 'rgba(0, 0, 0, 0.11) 0px 0.3px 10px', $c['card_shadow_light'] . ' 0px 0.3px 10px'],
        ['browser_ui/content_ui/favorites.css', 'background-color: rgb(0, 112, 198);', 'background-color: ' . $c['prompt_confirm_bg'] . ';'],
        ['browser_ui/content_ui/favorites.css', 'color: white;', 'color: ' . $c['btn_primary_text'] . ';'],
        ['browser_ui/content_ui/favorites.css', 'background-color: rgb(210, 210, 210);', 'background-color: ' . $c['prompt_cancel_bg'] . ';'],

        // history.css
        ['browser_ui/content_ui/history.css', 'rgb(0, 97, 171)', $c['link_color']],
        ['browser_ui/content_ui/history.css', 'rgba(0, 0, 0, 0.2)', $c['overlay_bg']],
        ['browser_ui/content_ui/history.css', "background-color: white;\n\n    box-shadow", "background-color: {$c['prompt_bg']};\n\n    box-shadow"],
        ['browser_ui/content_ui/history.css', 'rgba(0, 0, 0, 0.13) 0px 1.6px 20px', $c['card_shadow_dark'] . ' 0px 1.6px 20px'],
        ['browser_ui/content_ui/history.css', 'rgba(0, 0, 0, 0.11) 0px 0.3px 10px', $c['card_shadow_light'] . ' 0px 0.3px 10px'],
        ['browser_ui/content_ui/history.css', 'background-color: rgb(0, 112, 198);', 'background-color: ' . $c['prompt_confirm_bg'] . ';'],
        ['browser_ui/content_ui/history.css', 'background-color: rgb(210, 210, 210);', 'background-color: ' . $c['prompt_cancel_bg'] . ';'],
        ['browser_ui/content_ui/history.css', 'rgb(16, 16, 16)', $c['text_primary']],

        // settings.css
        ['browser_ui/content_ui/settings.css', 'rgb(220, 220, 220)', $c['settings_hover']],
    ];
}

function get_base_files() {
    global $g_base_dir;
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($g_base_dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $relative = 'browser_ui/' . str_replace('\\', '/', $iterator->getSubPathname());
            $files[$relative] = $file->getPathname();
        }
    }
    return $files;
}

function get_size_definitions() {
    $defs = [
        'small_font'        => ['label' => 'Small Text',           'default' => '0.8em',   'hint' => 'em, px, rem',  'source' => ['browser_ui/controls_ui/strip.css',      '.tab-label span',      'font-size']],
        'label_font'        => ['label' => 'Label Text',           'default' => '12px',    'hint' => 'px, em, rem',  'source' => ['browser_ui/content_ui/items.css',       '.label-title a',       'font-size']],
        'button_font'       => ['label' => 'Button / Header',      'default' => '14px',    'hint' => 'px, em, rem',  'source' => ['browser_ui/content_ui/items.css',       '.drag-handle',         'font-size']],
        'title_font'        => ['label' => 'Page Title',           'default' => '24px',    'hint' => 'px, em, rem',  'source' => ['browser_ui/content_ui/styles.css',      '.main-title',          'font-size']],
        'addr_font'         => ['label' => 'URL Field',            'default' => '1em',     'hint' => 'em, px, rem',  'source' => ['browser_ui/controls_ui/address-bar.css','#address-field',       'font-size']],
        'radius_base'       => ['label' => 'Container / Button',   'default' => '5px',     'hint' => 'px',           'source' => ['browser_ui/controls_ui/controls.css',   '.btn,',                'border-radius']],
        'radius_small'      => ['label' => 'Small Element',        'default' => '3px',     'hint' => 'px',           'source' => ['browser_ui/controls_ui/address-bar.css','#zoom-field',          'border-radius']],
        'radius_tab'        => ['label' => 'Tab Top Corners',      'default' => '8px',     'hint' => 'px',           'source' => ['browser_ui/controls_ui/strip.css',      'tab, .tab-active',     'border-top-left-radius']],
        'item_row_height'   => ['label' => 'Item Row',             'default' => '40px',    'hint' => 'px',           'source' => ['browser_ui/content_ui/items.css',       '.item {',              'height']],
        'entry_height'      => ['label' => 'Entry Row',            'default' => '48px',    'hint' => 'px',           'source' => ['browser_ui/content_ui/settings.css',    'settings-entry',       'height']],
        'toolbar_btn_size'  => ['label' => 'Toolbar Button',       'default' => '30px',    'hint' => 'px',           'source' => ['browser_ui/controls_ui/controls.css',   '.btn,',                'height']],
        'tab_max_width'     => ['label' => 'Tab Max Width',        'default' => '250px',   'hint' => 'px',           'source' => ['browser_ui/controls_ui/strip.css',      'tab, .tab-active',     'max-width']],
        'option_width'      => ['label' => 'Dropdown Width',       'default' => '200px',   'hint' => 'px',           'source' => ['browser_ui/controls_ui/options.css',    'html, body',           'width']],
        'max_content_width' => ['label' => 'Content Max Width',    'default' => '820px',   'hint' => 'px',           'source' => ['browser_ui/content_ui/items.css',       '.item {',              'max-width']],
        'page_padding'      => ['label' => 'Page Padding',         'default' => '32px 50px', 'hint' => 'top/bottom left/right', 'source' => ['browser_ui/content_ui/styles.css', "}\n\nbody",             'padding']],
        'prompt_padding'    => ['label' => 'Dialog Padding',       'default' => '20px',    'hint' => 'px',           'source' => ['browser_ui/content_ui/favorites.css',   '#prompt-box',          'padding']],
    ];
    return load_defaults_from_sources($defs);
}

function get_size_replacements($sizes) {
    $defs = get_size_definitions();
    $s = [];
    foreach ($defs as $key => $def) {
        $s[$key] = isset($sizes[$key]) && trim($sizes[$key]) !== '' ? trim($sizes[$key]) : $def['default'];
    }

    return [
        // --- Text sizes ---
        // 0.8em: tab label, dropdown item, zoom, context menu, settings value
        ['browser_ui/controls_ui/strip.css',      'font-size: 0.8em', 'font-size: ' . $s['small_font']],
        ['browser_ui/controls_ui/options.css',     'font-size: 0.8em', 'font-size: ' . $s['small_font']],
        ['browser_ui/controls_ui/address-bar.css', 'font-size: 0.8em', 'font-size: ' . $s['small_font']],
        ['browser_ui/content_ui/settings.css',     'font-size: 0.8em', 'font-size: ' . $s['small_font']],
        // 12px: item titles and URI/timestamp labels
        ['browser_ui/content_ui/items.css',     'font-size: 12px', 'font-size: ' . $s['label_font']],
        // 14px: action buttons, dialog buttons, date headers, drag handle
        ['browser_ui/content_ui/items.css',     'font-size: 14px', 'font-size: ' . $s['button_font']],
        ['browser_ui/content_ui/favorites.css', 'font-size: 14px', 'font-size: ' . $s['button_font']],
        ['browser_ui/content_ui/history.css',   'font-size: 14px', 'font-size: ' . $s['button_font']],
        // 24px: page title
        ['browser_ui/content_ui/styles.css',    'font-size: 24px', 'font-size: ' . $s['title_font']],
        // 1em: URL field
        ['browser_ui/controls_ui/address-bar.css', 'font-size: 1em', 'font-size: ' . $s['addr_font']],

        // --- Border radius ---
        ['browser_ui/controls_ui/controls.css',     'border-radius: 5px', 'border-radius: ' . $s['radius_base']],
        ['browser_ui/controls_ui/address-bar.css',  'border-radius: 5px', 'border-radius: ' . $s['radius_base']],
        ['browser_ui/content_ui/favorites.css',     'border-radius: 5px', 'border-radius: ' . $s['radius_base']],
        ['browser_ui/content_ui/history.css',       'border-radius: 5px', 'border-radius: ' . $s['radius_base']],
        ['browser_ui/content_ui/settings.css',      'border-radius: 5px', 'border-radius: ' . $s['radius_base']],
        ['browser_ui/controls_ui/address-bar.css',  'border-radius: 3px', 'border-radius: ' . $s['radius_small']],
        ['browser_ui/content_ui/favorites.css',     'border-radius: 3px', 'border-radius: ' . $s['radius_small']],
        ['browser_ui/content_ui/history.css',       'border-radius: 3px', 'border-radius: ' . $s['radius_small']],
        ['browser_ui/controls_ui/strip.css',  'border-top-left-radius: 8px',  'border-top-left-radius: ' . $s['radius_tab']],
        ['browser_ui/controls_ui/strip.css',  'border-top-right-radius: 8px', 'border-top-right-radius: ' . $s['radius_tab']],

        // --- Heights ---
        ['browser_ui/content_ui/items.css',     'height: 40px', 'height: ' . $s['item_row_height']],
        ['browser_ui/content_ui/items.css',    'height: 48px', 'height: ' . $s['entry_height']],
        ['browser_ui/content_ui/settings.css', 'height: 48px', 'height: ' . $s['entry_height']],
        ['browser_ui/controls_ui/controls.css', 'height: 30px', 'height: ' . $s['toolbar_btn_size']],
        ['browser_ui/controls_ui/controls.css', 'width: 30px',  'width: ' . $s['toolbar_btn_size']],

        // --- Widths ---
        ['browser_ui/controls_ui/strip.css',  'max-width: 250px', 'max-width: ' . $s['tab_max_width']],
        ['browser_ui/controls_ui/options.css', 'width: 200px',    'width: ' . $s['option_width']],
        ['browser_ui/content_ui/items.css',    'max-width: 820px', 'max-width: ' . $s['max_content_width']],

        // --- Padding ---
        ['browser_ui/content_ui/styles.css', 'padding: 32px 50px', 'padding: ' . $s['page_padding']],
        ['browser_ui/content_ui/favorites.css', 'padding: 20px', 'padding: ' . $s['prompt_padding']],
        ['browser_ui/content_ui/history.css',   'padding: 20px', 'padding: ' . $s['prompt_padding']],
    ];
}

function apply_theme($colors, $sizes = []) {
    global $g_base_dir;
    $replacements = get_color_replacements($colors);
    if (!empty($sizes)) {
        $replacements = array_merge($replacements, get_size_replacements($sizes));
    }
    $grouped = [];
    foreach ($replacements as $r) {
        $grouped[$r[0]][] = $r;
    }

    $modified = [];
    foreach ($grouped as $file_rel => $file_repl) {
        $relative = substr($file_rel, strlen('browser_ui/'));
        $src = $g_base_dir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);

        if (!file_exists($src)) continue;

        $content = file_get_contents($src);
        if ($content === false) continue;
        $content = str_replace("\r\n", "\n", $content);

        foreach ($file_repl as $r) {
            list(, $search, $replace) = $r;
            if (strpos($file_rel, '.css') !== false) {
                $content = str_replace($search, $replace, $content);
            }
        }
        $modified[$file_rel] = $content;
    }

    return $modified;
}

function get_all_files_content($colors, $sizes = []) {
    global $g_base_dir;
    $base_files = get_base_files();
    $modified_css = apply_theme($colors, $sizes);
    $result = [];

    foreach ($base_files as $relative => $fullpath) {
        if (isset($modified_css[$relative])) {
            $result[$relative] = $modified_css[$relative];
        } else {
            $result[$relative] = file_get_contents($fullpath);
        }
    }
    return $result;
}

function create_theme_zip($colors, $theme_name, $sizes = []) {
    global $g_download_dir;
    if (!is_dir($g_download_dir)) {
        mkdir($g_download_dir, 0777, true);
    }

    $safe_name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $theme_name);
    $zip_path = $g_download_dir . DIRECTORY_SEPARATOR . $safe_name . '.zip';

    $zip = new ZipArchive();
    if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
        return false;
    }

    $files = get_all_files_content($colors, $sizes);
    foreach ($files as $relative => $content) {
        $zip->addFromString($relative, $content);
    }

    $zip->close();
    return $zip_path;
}

function hex_to_rgb($hex) {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    return "rgb($r, $g, $b)";
}
?>
