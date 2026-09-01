<?php
/**
 * TranslatePress rendered-content mutation handling for UltraCache.
 *
 * TranslatePress stores shared translation dictionaries rather than one
 * translated WordPress object per language. A changed string can therefore
 * render on multiple public pages. UltraCache treats these save hooks as a
 * rendered-HTML correctness boundary, while any editor URL is only a bounded
 * warm hint and never proof of exclusive page ownership.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Engine_TranslatePress_Mutation_Trait
{
    /** @var bool */
    private $translatepress_rendered_mutation_purged = false;

    /** @var array<string,string> */
    private $translatepress_mutation_warm_urls = array();

    /**
     * Handle regular-string dictionary saves from the TranslatePress visual editor.
     *
     * @param array $update_strings Changed strings keyed by TranslatePress language.
     * @param array $settings       TranslatePress runtime settings.
     * @return void
     */
    public function handle_translatepress_regular_translation_save($update_strings, $settings = array())
    {
        unset($settings);
        $this->handle_translatepress_dictionary_mutation($update_strings, 'translatepress_translation_change');
    }

    /**
     * Handle gettext dictionary saves from the TranslatePress visual editor.
     *
     * @param array $update_strings Changed strings keyed by TranslatePress language.
     * @param array $settings       TranslatePress runtime settings.
     * @return void
     */
    public function handle_translatepress_gettext_translation_save($update_strings, $settings = array())
    {
        unset($settings);
        $this->handle_translatepress_dictionary_mutation($update_strings, 'translatepress_translation_change');
    }

    /**
     * Handle TranslatePress SEO Pack translated-slug saves.
     *
     * Current SEO Pack emits ($slug, $language, $update_slugs); its legacy post
     * slug editor emits ($slug, $post, $language, $update_slugs). The handler
     * accepts both contracts without depending on private SEO Pack classes.
     *
     * @param mixed $slug  Changed slug payload.
     * @param mixed $arg2  Language code or legacy WP_Post.
     * @param mixed $arg3  Update payload or legacy language code.
     * @param mixed $arg4  Legacy update payload.
     * @return void
     */
    public function handle_translatepress_slug_update($slug = null, $arg2 = null, $arg3 = null, $arg4 = null)
    {
        if (!$this->is_translatepress_mutation_runtime()) {
            return;
        }

        $language_code = '';
        $explicit_url = '';

        if (is_object($arg2) && isset($arg2->ID)) {
            // Legacy post-slug API: ($slug, $post, $language, $update_slugs).
            $language_code = $this->normalize_translatepress_mutation_language($arg3);
            $post_id = absint($arg2->ID);
            if ($post_id > 0) {
                $permalink = get_permalink($post_id);
                $explicit_url = is_string($permalink) ? trim($permalink) : '';
            }
        } else {
            // Current SEO Pack API: ($slug, $language, $update_slugs).
            $language_code = $this->normalize_translatepress_mutation_language($arg2);
        }

        if ('' === $language_code || !$this->translatepress_slug_payload_has_change($slug, $arg3, $arg4)) {
            return;
        }

        if (!$this->invalidate_translatepress_rendered_content('translatepress_url_change')) {
            return;
        }

        $this->collect_translatepress_mutation_warm_hints(array($language_code), $explicit_url);
    }

    /**
     * Flush one request's accumulated TranslatePress editor warm hints.
     *
     * The full rendered-cache invalidation has already happened synchronously.
     * This only reuses the existing optional "Warm affected pages after save"
     * pipeline for URLs that can be proven from the editor/request context.
     *
     * @return void
     */
    public function flush_translatepress_mutation_warm_hints()
    {
        if (empty($this->translatepress_mutation_warm_urls)) {
            return;
        }

        $urls = array_values($this->translatepress_mutation_warm_urls);
        $this->translatepress_mutation_warm_urls = array();
        $plan = $this->build_affected_url_plan($urls);
        $this->queue_affected_url_plan_rebuild($plan, 'translatepress-translation');
    }

    /**
     * Handle one shared TranslatePress dictionary mutation.
     *
     * @param mixed  $update_strings Changed strings keyed by language.
     * @param string $reason         Cache-correctness reason.
     * @return void
     */
    private function handle_translatepress_dictionary_mutation($update_strings, $reason)
    {
        if (!$this->is_translatepress_mutation_runtime()) {
            return;
        }

        $languages = $this->get_translatepress_mutation_languages($update_strings);
        if (empty($languages)) {
            return;
        }

        if (!$this->invalidate_translatepress_rendered_content($reason)) {
            return;
        }

        $this->collect_translatepress_mutation_warm_hints($languages);
    }

    /**
     * Confirm that exactly TranslatePress owns the current multilingual runtime.
     *
     * @return bool
     */
    private function is_translatepress_mutation_runtime()
    {
        return function_exists('ultracache_multilingual_get_provider')
            && 'translatepress' === ultracache_multilingual_get_provider();
    }

    /**
     * Return changed, active provider-native language codes.
     *
     * @param mixed $update_strings TranslatePress save payload.
     * @return array<int,string>
     */
    private function get_translatepress_mutation_languages($update_strings)
    {
        if (!is_array($update_strings) || empty($update_strings)) {
            return array();
        }

        $active = function_exists('ultracache_multilingual_get_active_language_codes')
            ? ultracache_multilingual_get_active_language_codes()
            : array();
        $active_lookup = array_fill_keys($active, true);
        $languages = array();

        foreach ($update_strings as $language_code => $updates) {
            if (!is_array($updates) || empty($updates)) {
                continue;
            }

            $language_code = $this->normalize_translatepress_mutation_language($language_code);
            if ('' === $language_code || !isset($active_lookup[$language_code])) {
                continue;
            }

            $languages[$language_code] = $language_code;
        }

        return array_values($languages);
    }

    /**
     * Normalize a language code through the active provider and require it to be published.
     *
     * @param mixed $language_code Raw language code.
     * @return string
     */
    private function normalize_translatepress_mutation_language($language_code)
    {
        $language_code = function_exists('ultracache_multilingual_normalize_language_code')
            ? ultracache_multilingual_normalize_language_code($language_code)
            : '';
        if ('' === $language_code) {
            return '';
        }

        $active = function_exists('ultracache_multilingual_get_active_language_codes')
            ? ultracache_multilingual_get_active_language_codes()
            : array();

        return in_array($language_code, $active, true) ? $language_code : '';
    }

    /**
     * Confirm a slug action represents an actual mutation payload.
     *
     * @param mixed $slug Changed slug payload.
     * @param mixed $arg3 Current update payload or legacy language code.
     * @param mixed $arg4 Legacy update payload.
     * @return bool
     */
    private function translatepress_slug_payload_has_change($slug, $arg3, $arg4)
    {
        if (is_array($slug) && !empty($slug)) {
            return true;
        }
        if (is_object($slug)) {
            return true;
        }

        $updates = is_array($arg4) ? $arg4 : (is_array($arg3) ? $arg3 : array());
        return !empty($updates);
    }

    /**
     * Purge rendered HTML and enabled native server HTML caches once per request.
     *
     * This deliberately does not call purge_all(): TranslatePress translation
     * dictionaries do not require Object Cache, JS-analysis, or generated asset
     * namespaces to be flushed. It also deliberately avoids the generic
     * ultracache_after_purge_all action so a full-site warm is not started.
     *
     * @param string $reason Cache-correctness reason.
     * @return bool
     */
    private function invalidate_translatepress_rendered_content($reason)
    {
        if ($this->translatepress_rendered_mutation_purged) {
            return true;
        }

        if (!$this->purge_html_cache_for_delivery_change()) {
            return false;
        }

        $this->translatepress_rendered_mutation_purged = true;
        $payload = array(
            'scope'  => 'all',
            'reason' => sanitize_key((string) $reason),
            'source' => 'translatepress',
        );

        if (class_exists('Ultra_Cache_WP') && is_callable(array('Ultra_Cache_WP', 'instance'))) {
            $wp = Ultra_Cache_WP::instance();
            if (is_object($wp) && method_exists($wp, 'handle_varnish_after_purge_all')) {
                $wp->handle_varnish_after_purge_all($payload);
            }
            if (is_object($wp) && method_exists($wp, 'handle_litespeed_after_purge_all')) {
                $wp->handle_litespeed_after_purge_all($payload);
            }
        }

        return true;
    }

    /**
     * Collect provider-resolved warm hints for one editor/public source URL.
     *
     * @param array<int,string> $language_codes Changed provider-native languages.
     * @param string            $explicit_url   Optional proven public URL.
     * @return void
     */
    private function collect_translatepress_mutation_warm_hints(array $language_codes, $explicit_url = '')
    {
        $source_url = trim((string) $explicit_url);
        if ('' === $source_url) {
            $source_url = $this->get_translatepress_editor_public_url();
        }
        if ('' === $source_url) {
            return;
        }

        $eligible_languages = function_exists('ultracache_multilingual_get_warm_languages')
            ? ultracache_multilingual_get_warm_languages('affected_save')
            : $language_codes;

        foreach ($language_codes as $language_code) {
            $language_code = $this->normalize_translatepress_mutation_language($language_code);
            if ('' === $language_code || !in_array($language_code, $eligible_languages, true)) {
                continue;
            }

            $target = function_exists('ultracache_multilingual_translate_url')
                ? ultracache_multilingual_translate_url($source_url, $language_code)
                : $source_url;
            $target = is_string($target) ? trim($target) : '';
            if ('' === $target) {
                continue;
            }
            if (function_exists('ultracache_is_strict_frontend_loopback_url')
                && !ultracache_is_strict_frontend_loopback_url($target)
            ) {
                continue;
            }

            $this->translatepress_mutation_warm_urls[$target] = $target;
        }
    }

    /**
     * Return the visual editor's current public page URL as a warm hint.
     *
     * @return string
     */
    private function get_translatepress_editor_public_url()
    {
        if (!isset($_POST['url']) || !is_scalar($_POST['url'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- observer only; TRP verifies its own save nonce before firing the action.
            return '';
        }

        $url = esc_url_raw(wp_unslash($_POST['url']), array('http', 'https')); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $url = trim((string) $url);
        if ('' === $url) {
            return '';
        }

        $fragment_pos = strpos($url, '#');
        if (false !== $fragment_pos) {
            $url = substr($url, 0, $fragment_pos);
        }
        $url = remove_query_arg(
            array(
                'trp-edit-translation',
                'trp-view-as',
                'trp-view-as-nonce',
            ),
            $url
        );

        if (function_exists('ultracache_is_strict_frontend_loopback_url')
            && !ultracache_is_strict_frontend_loopback_url($url)
        ) {
            return '';
        }

        return $url;
    }
}
