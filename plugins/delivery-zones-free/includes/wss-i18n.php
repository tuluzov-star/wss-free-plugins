<?php
/** Shared, domain-scoped translation bootstrap, bundled with each independent plugin. */
if (!defined('ABSPATH')) { exit; }

if (!class_exists('WSS_Plugin_I18n_202609')) {
    final class WSS_Plugin_I18n_202609 {
        private static $plugins = [];
        private static $defaults = [];

        public static function register($file, $domain) {
            self::$plugins[$domain] = $file;
            if (count(self::$plugins) === 1) {
                add_action('init', [__CLASS__, 'load'], 0);
                add_filter('load_textdomain_mofile', [__CLASS__, 'english_fallback'], 10, 2);
                add_filter('load_script_translation_file', [__CLASS__, 'script_file'], 10, 3);
                foreach (['wp_print_scripts', 'wp_print_footer_scripts', 'admin_print_scripts', 'admin_print_footer_scripts'] as $hook) {
                    add_action($hook, [__CLASS__, 'scripts'], 0);
                }
            }
            // Activation can include a plugin after init has already run.
            if (did_action('init')) { self::load(); }
        }

        public static function load() {
            foreach (self::$plugins as $domain => $file) {
                load_plugin_textdomain($domain, false, dirname(plugin_basename($file)) . '/languages');
            }
        }

        public static function english_fallback($mofile, $domain) {
            if (isset(self::$plugins[$domain]) && !is_readable($mofile) && preg_match('/-en_[A-Za-z_]+\.mo$/', $mofile)) {
                $fallback = dirname(self::$plugins[$domain]) . '/languages/' . $domain . '-en_US.mo';
                if (is_readable($fallback)) { return $fallback; }
            }
            return $mofile;
        }

        public static function scripts() {
            global $wp_scripts;
            if (!$wp_scripts || empty($wp_scripts->registered)) { return; }
            foreach (self::$plugins as $domain => $file) {
                $base = plugin_dir_url($file);
                foreach ($wp_scripts->registered as $handle => $script) {
                    if (!is_string($script->src) || strpos($script->src, $base) !== 0) { continue; }
                    if (!in_array('wp-i18n', $script->deps, true)) { $script->deps[] = 'wp-i18n'; }
                    wp_set_script_translations($handle, $domain, dirname($file) . '/languages');
                }
            }
        }

        public static function script_file($path, $handle, $domain) {
            if (!isset(self::$plugins[$domain])) { return $path; }
            // Respect externally installed translations before using the bundled fallback.
            if (is_string($path) && is_readable($path)) { return $path; }
            $locale = determine_locale();
            if (strpos($locale, 'en_') !== 0 && $locale !== 'en') { return $path; }
            $fallback = dirname(self::$plugins[$domain]) . '/languages/' . $domain . '-en_US.json';
            return is_readable($fallback) ? $fallback : $path;
        }

        /** Translate only known stock labels in memory; never write options or user content. */
        public static function defaults($values, $domain) {
            if (!is_array($values) || !isset(self::$plugins[$domain])) { return $values; }
            if (!isset(self::$defaults[$domain])) {
                $path = dirname(self::$plugins[$domain]) . '/languages/default-labels.json';
                self::$defaults[$domain] = is_readable($path) ? json_decode(file_get_contents($path), true) : [];
            }
            foreach ((array) self::$defaults[$domain] as $key => $labels) {
                if (isset($values[$key]) && in_array($values[$key], $labels, true)) {
                    $values[$key] = translate($labels[0], $domain);
                }
            }
            return $values;
        }
    }
}
