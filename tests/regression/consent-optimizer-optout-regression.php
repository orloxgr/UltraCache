<?php
/**
 * UltraCache 3.11.18 CMP metadata / optimizer opt-out regression contracts.
 *
 * Run:
 *   wp eval-file tests/regression/consent-optimizer-optout-regression.php
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$root = dirname(__DIR__, 2);
require_once $root . '/includes/engine/class-engine-js-optimization-trait.php';

final class UltraCache_Consent_Optimizer_Optout_Regression_Harness
{
    use Ultra_Cache_Engine_JS_Optimization_Trait;

    public function optedOut(string $tag): bool
    {
        return $this->is_script_tag_optimizer_opted_out($tag);
    }

    public function delayableExternal(string $tag): bool
    {
        return $this->is_delayable_external_script_tag($tag);
    }

    /**
     * Deterministic attribute parser for this isolated contract harness.
     * Production continues to use WP_HTML_Tag_Processor.
     *
     * @param string $tag Script tag.
     * @return array<string,string>
     */
    private function extract_html_tag_attributes($tag)
    {
        $attributes = array();
        if (preg_match_all('/\s+([a-zA-Z_:][-a-zA-Z0-9_:.]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?/i', (string) $tag, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                if (empty($match[1])) {
                    continue;
                }
                $name = strtolower((string) $match[1]);
                $value = '';
                if (isset($match[2]) && '' !== $match[2]) {
                    $value = $match[2];
                } elseif (isset($match[3]) && '' !== $match[3]) {
                    $value = $match[3];
                } elseif (isset($match[4]) && '' !== $match[4]) {
                    $value = $match[4];
                }
                $attributes[$name] = $value;
            }
        }
        return $attributes;
    }

    /**
     * Deterministic single-attribute lookup for this isolated contract harness.
     *
     * @param string $tag Script tag.
     * @param string $attribute Attribute name.
     * @return string
     */
    private function extract_attribute_from_html_tag($tag, $attribute)
    {
        $attributes = $this->extract_html_tag_attributes((string) $tag);
        $attribute = strtolower((string) $attribute);
        return array_key_exists($attribute, $attributes) ? (string) $attributes[$attribute] : '';
    }
}

$failures = array();
$passes = 0;
$harness = new UltraCache_Consent_Optimizer_Optout_Regression_Harness();

function ultracache_consent_optout_expect(bool $condition, string $label): void
{
    global $failures, $passes;

    if ($condition) {
        $passes++;
        echo esc_html('[PASS] ' . $label) . PHP_EOL;
        return;
    }

    $failures[] = $label;
    echo esc_html('[FAIL] ' . $label) . PHP_EOL;
}

ultracache_consent_optout_expect(
    false === $harness->optedOut(
        '<script data-consent-category="statistics" data-consent-purpose="analytics" src="https://www.googletagmanager.com/gtag/js?id=G-TEST"></script>'
    ),
    'A: generic data-consent metadata does not opt executable gtag out'
);

ultracache_consent_optout_expect(
    true === $harness->delayableExternal(
        '<script data-consent-category="statistics" data-consent-purpose="analytics" src="https://www.googletagmanager.com/gtag/js?id=G-TEST"></script>'
    ),
    'B: executable gtag with generic CMP metadata remains delayable'
);

ultracache_consent_optout_expect(
    false === $harness->delayableExternal(
        '<script type="text/plain" class="cmplz-script" data-consent-category="statistics" src="https://www.googletagmanager.com/gtm.js?id=GTM-TEST"></script>'
    ),
    'C: CMP-disarmed text/plain GTM placeholder remains non-delayable'
);

ultracache_consent_optout_expect(
    true === $harness->optedOut(
        '<script data-no-defer src="https://example.com/app.js"></script>'
    ),
    'D: data-no-defer remains an explicit optimizer opt-out'
);

ultracache_consent_optout_expect(
    true === $harness->optedOut(
        '<script data-consent-skip-blocker src="https://example.com/app.js"></script>'
    ),
    'E: data-consent-skip-blocker remains an explicit CMP interoperability opt-out'
);

ultracache_consent_optout_expect(
    true === $harness->optedOut(
        '<script data-cfasync="false" src="https://example.com/app.js"></script>'
    ),
    'F: Cloudflare data-cfasync=false remains an explicit optimizer opt-out'
);

ultracache_consent_optout_expect(
    false === $harness->optedOut(
        '<script class="cmplz-script" data-consent-category="marketing" src="https://connect.facebook.net/en_US/fbevents.js"></script>'
    ),
    'G: executable Meta payload with generic CMP metadata is not opted out'
);

echo esc_html('Consent optimizer opt-out regression: ' . $passes . ' passed, ' . count($failures) . ' failed.') . PHP_EOL;

if (!empty($failures)) {
    throw new RuntimeException('UltraCache consent optimizer opt-out regression failed.');
}
