<?php
/**
 * Strict viewport-based lazy loading for eligible third-party iframes.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Engine_Iframe_Lazy_Load_Trait
{
    /**
     * Rewrite eligible third-party iframes into inert placeholders.
     *
     * @param string $html Source HTML.
     * @return string
     */
    private function apply_lazy_load_third_party_iframes_to_html($html)
    {
        if (!is_string($html) || '' === $html || false === stripos($html, '<iframe')) {
            return $html;
        }

        if (!class_exists('WP_HTML_Tag_Processor') || $this->should_skip_lazy_third_party_iframes_for_request()) {
            return $html;
        }

        try {
            $processor = new WP_HTML_Tag_Processor($html);
            $changed = false;
            $processed = 0;

            while ($processor->next_tag('IFRAME')) {
                ++$processed;
                if ($processed > 120) {
                    break;
                }

                $source = $processor->get_attribute('src');
                $source = is_scalar($source) ? trim(html_entity_decode((string) $source, ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
                if ($this->should_skip_lazy_third_party_iframe($processor, $source)) {
                    continue;
                }

                $processor->set_attribute('src', 'about:blank');
                $processor->set_attribute('data-ultracache-iframe-src', $source);
                $processor->set_attribute('data-ultracache-lazy-iframe', '1');
                if (null === $processor->get_attribute('loading') || '' === trim((string) $processor->get_attribute('loading'))) {
                    $processor->set_attribute('loading', 'lazy');
                }
                $changed = true;
            }

            if (!$changed) {
                return $html;
            }

            $updated = $processor->get_updated_html();
            if (!is_string($updated) || '' === $updated) {
                return $html;
            }

            return $this->append_lazy_third_party_iframe_noscript_fallbacks($updated);
        } catch (\Throwable $e) {
            return $html;
        }
    }

    /**
     * Decide whether the current request must keep every iframe eager.
     *
     * @return bool
     */
    private function should_skip_lazy_third_party_iframes_for_request()
    {
        $skip = false;

        foreach (array('is_cart', 'is_checkout', 'is_account_page') as $conditional) {
            if (function_exists($conditional) && call_user_func($conditional)) {
                $skip = true;
                break;
            }
        }

        if (!$skip) {
            $request_uri = sanitize_text_field(ultracache_server_value('REQUEST_URI'));
            $path = strtolower((string) ultracache_safe_wp_parse_url($request_uri, PHP_URL_PATH, 'iframe_lazy_load_request_path'));
            if ('' !== $path && preg_match('~/(?:wp-login\.php|wp-admin(?:/|$)|checkout(?:/|$)|cart(?:/|$)|my-account(?:/|$)|order-pay(?:/|$)|order-received(?:/|$)|lost-password(?:/|$)|resetpass(?:/|$)|register(?:/|$))~i', $path)) {
                $skip = true;
            }
        }

        return (bool) apply_filters('ultracache_lazy_third_party_iframes_skip_request', $skip);
    }

    /**
     * Decide whether one iframe is eligible for strict lazy loading.
     *
     * @param WP_HTML_Tag_Processor $processor Current tag processor.
     * @param string                $source    Decoded source URL.
     * @return bool
     */
    private function should_skip_lazy_third_party_iframe($processor, $source)
    {
        if ('' === $source || !$this->is_third_party_http_iframe_url($source)) {
            return true;
        }

        if (null !== $processor->get_attribute('srcdoc')) {
            return true;
        }

        foreach (array('data-ultracache-lazy-iframe', 'data-ultracache-iframe-fallback', 'data-ultracache-no-lazy-iframe', 'data-no-lazy', 'data-skip-lazy') as $attribute) {
            if (null !== $processor->get_attribute($attribute)) {
                return true;
            }
        }

        if ('eager' === strtolower(trim((string) $processor->get_attribute('loading')))) {
            return true;
        }

        if (null !== $processor->get_attribute('hidden') || 'true' === strtolower(trim((string) $processor->get_attribute('aria-hidden')))) {
            return true;
        }

        foreach (array('width', 'height') as $dimension) {
            $value = $processor->get_attribute($dimension);
            if (null !== $value && preg_match('/^(?:0|1)(?:\.0+)?(?:px)?$/i', trim((string) $value))) {
                return true;
            }
        }

        $style = strtolower(trim((string) $processor->get_attribute('style')));
        if ('' !== $style && preg_match('/(?:^|;)\s*(?:display\s*:\s*none|visibility\s*:\s*hidden|opacity\s*:\s*0|(?:width|height)\s*:\s*(?:0|1)(?:\.0+)?px|(?:left|top)\s*:\s*-\d{3,}px)(?:\s*!important)?\s*(?:;|$)/i', $style)) {
            return true;
        }

        if ($this->is_critical_third_party_iframe_url($source)) {
            return true;
        }

        return (bool) apply_filters('ultracache_lazy_third_party_iframe_should_skip', false, $source);
    }

    /**
     * Check whether an iframe URL is an absolute HTTP(S) third-party URL.
     *
     * @param string $source Source URL.
     * @return bool
     */
    private function is_third_party_http_iframe_url($source)
    {
        $source = trim((string) $source);
        if (0 === strpos($source, '//')) {
            $source = (is_ssl() ? 'https:' : 'http:') . $source;
        }

        $scheme = strtolower((string) wp_parse_url($source, PHP_URL_SCHEME));
        $host = strtolower(rtrim((string) wp_parse_url($source, PHP_URL_HOST), '.'));
        if (!in_array($scheme, array('http', 'https'), true) || '' === $host) {
            return false;
        }

        $home = home_url('/');
        $home_scheme = strtolower((string) wp_parse_url($home, PHP_URL_SCHEME));
        $home_host = strtolower(rtrim((string) wp_parse_url($home, PHP_URL_HOST), '.'));
        $home_port = (int) wp_parse_url($home, PHP_URL_PORT);
        $source_port = (int) wp_parse_url($source, PHP_URL_PORT);
        if ($home_port < 1) {
            $home_port = 'https' === $home_scheme ? 443 : 80;
        }
        if ($source_port < 1) {
            $source_port = 'https' === $scheme ? 443 : 80;
        }
        if ('' !== $home_host && $home_scheme === $scheme && $home_host === $host && $home_port === $source_port) {
            return false;
        }

        return true;
    }

    /**
     * Identify payment, authentication, CAPTCHA, and challenge iframes.
     *
     * @param string $source Source URL.
     * @return bool
     */
    private function is_critical_third_party_iframe_url($source)
    {
        $source = strtolower(html_entity_decode((string) $source, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $host = strtolower((string) wp_parse_url($source, PHP_URL_HOST));
        $path = strtolower((string) wp_parse_url($source, PHP_URL_PATH));
        $query = strtolower((string) wp_parse_url($source, PHP_URL_QUERY));
        $haystack = $host . ' ' . $path . ' ' . $query;

        $critical_host_fragments = array(
            'js.stripe.com',
            'checkout.stripe.com',
            'paypal.com',
            'paypalobjects.com',
            'klarna.com',
            'vivawallet.com',
            'revolut.com',
            'adyen.com',
            'worldpay.com',
            '3dsecure',
            'accounts.google.com',
            'appleid.apple.com',
            'login.microsoftonline.com',
            'auth0.com',
            'okta.com',
            'hcaptcha.com',
            'recaptcha.net',
            'challenges.cloudflare.com',
        );
        foreach ($critical_host_fragments as $fragment) {
            if (false !== strpos($host, $fragment)) {
                return true;
            }
        }

        if (false !== strpos($host, 'google.com') && false !== strpos($path, '/recaptcha/')) {
            return true;
        }

        if (preg_match('~(?:^|[/?&=_-])(?:captcha|turnstile|challenge|checkout|payment|pay-for-order|3ds|3d-secure|authenticate|authentication|oauth|sso|token-refresh|login)(?:$|[/?&=_-])~i', $haystack)) {
            return true;
        }

        return (bool) apply_filters('ultracache_lazy_third_party_iframe_is_critical', false, $source);
    }

    /**
     * Append a no-JavaScript copy of each rewritten iframe after its closing tag.
     *
     * @param string $html Rewritten HTML.
     * @return string
     */
    private function append_lazy_third_party_iframe_noscript_fallbacks($html)
    {
        if (!function_exists('ultracache_scan_raw_html_tags')) {
            return $html;
        }

        $tags = ultracache_scan_raw_html_tags($html, array('iframe'), 0, null, 240);
        if (empty($tags)) {
            return $html;
        }

        $stack = array();
        $insertions = array();
        foreach ($tags as $tag) {
            if (empty($tag['closing'])) {
                $stack[] = $tag;
                continue;
            }

            if (empty($stack)) {
                continue;
            }

            $opening = array_pop($stack);
            $opening_raw = isset($opening['raw']) ? (string) $opening['raw'] : '';
            if ('' === $opening_raw || false === stripos($opening_raw, 'data-ultracache-lazy-iframe')) {
                continue;
            }

            $source = $this->extract_lazy_iframe_attribute_from_raw_tag($opening_raw, 'data-ultracache-iframe-src');
            if ('' === $source) {
                continue;
            }

            $fallback_opening = $this->build_lazy_iframe_fallback_opening_tag($opening_raw, $source);
            if ('' === $fallback_opening) {
                continue;
            }

            $opening_end = isset($opening['end']) ? (int) $opening['end'] : 0;
            $closing_offset = isset($tag['offset']) ? (int) $tag['offset'] : 0;
            $closing_end = isset($tag['end']) ? (int) $tag['end'] : 0;
            if ($opening_end < 1 || $closing_offset < $opening_end || $closing_end <= $closing_offset) {
                continue;
            }

            $inner = substr($html, $opening_end, $closing_offset - $opening_end);
            $closing_raw = isset($tag['raw']) ? (string) $tag['raw'] : '</iframe>';
            $fallback = '<noscript data-ultracache-iframe-fallback="1">' . $fallback_opening . $inner . $closing_raw . '</noscript>';
            $insertions[] = array(
                'offset' => $closing_end,
                'html' => $fallback,
            );
        }

        if (empty($insertions)) {
            return $html;
        }

        usort($insertions, static function ($left, $right) {
            return (int) $right['offset'] <=> (int) $left['offset'];
        });
        foreach ($insertions as $insertion) {
            $html = substr_replace($html, (string) $insertion['html'], (int) $insertion['offset'], 0);
        }

        return $html;
    }

    /**
     * Build the original-source opening tag used inside noscript.
     *
     * @param string $opening_tag Rewritten opening tag.
     * @param string $source      Original source URL.
     * @return string
     */
    private function build_lazy_iframe_fallback_opening_tag($opening_tag, $source)
    {
        try {
            $processor = new WP_HTML_Tag_Processor($opening_tag);
            if (!$processor->next_tag('IFRAME')) {
                return '';
            }

            $processor->set_attribute('src', $source);
            $processor->set_attribute('data-ultracache-iframe-fallback', '1');
            foreach (array('data-ultracache-lazy-iframe', 'data-ultracache-iframe-src', 'data-ultracache-iframe-activated') as $attribute) {
                $processor->remove_attribute($attribute);
            }

            $updated = $processor->get_updated_html();
            return is_string($updated) ? $updated : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Extract one attribute from a raw opening tag.
     *
     * @param string $tag       Opening tag.
     * @param string $attribute Attribute name.
     * @return string
     */
    private function extract_lazy_iframe_attribute_from_raw_tag($tag, $attribute)
    {
        $attribute = preg_quote((string) $attribute, '/');
        if (preg_match('/\s' . $attribute . '\s*=\s*(["\x27])(.*?)\1/is', (string) $tag, $matches)) {
            return html_entity_decode((string) ($matches[2] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if (preg_match('/\s' . $attribute . '\s*=\s*([^\s>]+)/i', (string) $tag, $matches)) {
            return html_entity_decode((string) ($matches[1] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return '';
    }
}
