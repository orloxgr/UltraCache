<?php
/**
 * UltraCache 3.13.10 consent-family coherence regression.
 *
 * Consent managers may replace the live DOM id of a WordPress inline
 * before/data/after/translations companion while preserving its original id as
 * provenance. UltraCache must still recognize the WordPress family so it does
 * not independently own an external consumer while the CMP owns its companion.
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
$root = dirname(__DIR__, 2);

if (!function_exists('sanitize_key')) {
    function sanitize_key($value) { return strtolower((string) preg_replace('/[^a-z0-9_\-]/', '', (string) $value)); }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($value) { return trim((string) $value); }
}
if (!function_exists('ultracache_query_value')) {
    function ultracache_query_value($key, $default = null) { return $default; }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
}
if (!function_exists('wp_get_script_tag')) {
    function wp_get_script_tag($attributes) {
        $parts = array();
        foreach ((array) $attributes as $name => $value) {
            if (true === $value) {
                $parts[] = htmlspecialchars((string) $name, ENT_QUOTES) . '="' . htmlspecialchars((string) $name, ENT_QUOTES) . '"';
            } else {
                $parts[] = htmlspecialchars((string) $name, ENT_QUOTES) . '="' . htmlspecialchars((string) $value, ENT_QUOTES) . '"';
            }
        }
        return '<script ' . implode(' ', $parts) . '></script>\n';
    }
}
if (!function_exists('wp_get_inline_script_tag')) {
    function wp_get_inline_script_tag($code, $attributes = array()) {
        $parts = array();
        foreach ((array) $attributes as $name => $value) {
            $parts[] = htmlspecialchars((string) $name, ENT_QUOTES) . '="' . htmlspecialchars((string) $value, ENT_QUOTES) . '"';
        }
        return '<script' . (empty($parts) ? '' : ' ' . implode(' ', $parts)) . '>' . (string) $code . '</script>\n';
    }
}

require_once $root . '/includes/engine/js/class-js-html-rewrite-trait.php';

final class UltraCacheConsentFamilyHarness
{
    use Ultra_Cache_Engine_JS_HTML_Rewrite_Trait;

    public function records(string $html): array
    {
        return $this->collect_script_dependency_records_from_html($html);
    }

    public function normalize(string $html): string
    {
        return $this->ultracache_normalize_inline_companion_group_lanes_in_html($html, array());
    }

    public function phase(array $record): string
    {
        return $this->ultracache_script_family_record_phase($record);
    }

    public function __call($name, $arguments)
    {
        if ('extract_attribute_from_html_tag' === $name) {
            $tag = (string) ($arguments[0] ?? '');
            $attribute = preg_quote((string) ($arguments[1] ?? ''), '/');
            if (preg_match('/\\b' . $attribute . '\\s*=\\s*(?:"([^"]*)"|\'([^\']*)\'|([^\\s>]+))/i', $tag, $m)) {
                return html_entity_decode((string) ($m[1] !== '' ? $m[1] : ($m[2] !== '' ? $m[2] : ($m[3] ?? ''))), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            return '';
        }
        if ('set_or_add_html_tag_attribute' === $name) {
            $tag = (string) ($arguments[0] ?? '');
            $attribute = (string) ($arguments[1] ?? '');
            $value = (string) ($arguments[2] ?? '');
            $quoted = preg_quote($attribute, '/');
            if (preg_match('/\\s' . $quoted . '\\s*=\\s*(?:"[^"]*"|\'[^\']*\'|[^\\s>]+)/i', $tag)) {
                return (string) preg_replace('/(\\s' . $quoted . '\\s*=\\s*)(?:"[^"]*"|\'[^\']*\'|[^\\s>]+)/i', '$1"' . htmlspecialchars($value, ENT_QUOTES) . '"', $tag, 1);
            }
            return (string) preg_replace('/<script\\b/i', '<script ' . $attribute . '="' . htmlspecialchars($value, ENT_QUOTES) . '"', $tag, 1);
        }
        if ('html_tag_processor_available' === $name) {
            return false;
        }
        if ('is_javascript_mime_type' === $name) {
            $type = strtolower(trim((string) ($arguments[0] ?? '')));
            return '' === $type || in_array($type, array('text/javascript', 'application/javascript', 'module'), true);
        }
        return false;
    }
}

$passes = 0;
$failures = array();
$expect = static function (bool $condition, string $label) use (&$passes, &$failures): void {
    if ($condition) { $passes++; echo '[PASS] ' . $label . PHP_EOL; return; }
    $failures[] = $label; echo '[FAIL] ' . $label . PHP_EOL;
};

$h = new UltraCacheConsentFamilyHarness();

$preserved = base64_encode(json_encode(array('id' => 'mailchimp-woocommerce-js')));
$rcb = '<script id="10680" consent-required="10679" consent-by="services" consent-id="10680" consent-original-type-_="application/javascript" type="application/consent" consent-inline="var mailchimp_public_data = {};" consent-original-id-_="mailchimp-woocommerce-js-extra"></script>'
    . '<script type="text/ultracache-delayed-js" data-ultracache-handle="mailchimp-woocommerce" data-ultracache-src="https://site.example/mailchimp.js" data-ultracache-original-src="https://site.example/mailchimp.js" data-ultracache-attrs="' . $preserved . '"></script>';

$records = $h->records($rcb);
$expect(2 === count($records), 'A1: RCB fixture yields one consent companion and one external consumer');
$expect('mailchimp-woocommerce' === ($records[0]['group'] ?? ''), 'A2: consent-original-id provenance restores the WordPress family despite replaced DOM id');
$expect('mailchimp-woocommerce-js-extra' === ($records[0]['consent_original_id'] ?? ''), 'A3: original consent-controlled companion id is retained as provenance');
$expect('data' === $h->phase($records[0]), 'A4: original -js-extra identity preserves the data phase');
$expect('mailchimp-woocommerce' === ($records[1]['group'] ?? ''), 'A5: delayed external consumer resolves to the same generic family');

$normalized = $h->normalize($rcb);
$expect(false === strpos($normalized, 'text/ultracache-delayed-js'), 'B1: UltraCache releases ownership of the consumer when its companion is CMP-controlled');
$expect(false === strpos($normalized, 'src="https://site.example/mailchimp.js"'), 'B2: external consumer no longer has a live src before consent');
$expect(false !== strpos($normalized, 'consent-original-src-_="https://site.example/mailchimp.js"'), 'B3: external consumer is handed back through the CMP original-src contract');
$expect(substr_count($normalized, 'type="application/consent"') >= 2, 'B4: both provider and consumer remain application/consent-owned');
$expect(false !== strpos($normalized, 'consent-required="10679"') && false !== strpos($normalized, 'consent-by="services"') && false !== strpos($normalized, 'consent-id="10680"'), 'B5: consumer inherits the exact consent service contract');
$expect(false !== strpos($normalized, 'data-ultracache-family="mailchimp-woocommerce"'), 'B6: family topology remains explicit without a vendor exclusion');

$complianz = '<script type="text/plain" id="example-service-js-extra" data-category="marketing">var exampleConfig = {};</script>'
    . '<script type="text/ultracache-delayed-js" data-ultracache-handle="example-service" data-ultracache-src="https://site.example/example.js" data-ultracache-original-src="https://site.example/example.js"></script>';
$complianzRecords = $h->records($complianz);
$expect('example-service' === ($complianzRecords[0]['group'] ?? '') && 'example-service' === ($complianzRecords[1]['group'] ?? ''), 'C1: retained-id text/plain consent placeholders already use the same generic family path');
$complianzNormalized = $h->normalize($complianz);
$expect(false === strpos($complianzNormalized, 'text/ultracache-delayed-js'), 'C2: generic non-executable consent companion prevents independent UltraCache consumer release');

$unrelated = '<script type="application/consent" id="999" consent-original-id-_="not-a-wordpress-family">x</script>';
$unrelatedRecords = $h->records($unrelated);
$expect('999' === ($unrelatedRecords[0]['group'] ?? ''), 'D1: arbitrary consent metadata cannot invent a WordPress family relationship');

$source = (string) file_get_contents($root . '/includes/engine/js/class-js-html-rewrite-trait.php');
$expect(false === stripos($source, 'mailchimp_public_data'), 'D2: production family coherence contains no Mailchimp global hardcode');
$expect(false === stripos($source, 'mailchimp-woocommerce'), 'D3: production family coherence contains no Mailchimp handle hardcode');

$total = $passes + count($failures);
echo PHP_EOL . 'Result: ' . $passes . '/' . $total . ' PASS' . PHP_EOL;
if (!empty($failures)) exit(1);
