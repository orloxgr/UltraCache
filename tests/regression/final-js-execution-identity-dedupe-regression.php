<?php
/** UltraCache 3.12.26 final JavaScript execution-identity dedupe regression. */
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
if (!function_exists('home_url')) {
    function home_url($path = '/') { return 'https://example.com' . ('/' === $path ? '/' : $path); }
}

$root = dirname(__DIR__, 2);
require_once $root . '/includes/engine/js/class-js-html-rewrite-trait.php';

final class UltraCacheFinalJsDedupeHarness
{
    use Ultra_Cache_Engine_JS_HTML_Rewrite_Trait;

    private function html_tag_processor_available() { return false; }
    private function extract_attribute_from_html_tag($tag, $name)
    {
        $name = preg_quote((string) $name, '/');
        if (preg_match('/\\s' . $name . '\\s*=\\s*(?:"([^"]*)"|\'([^\']*)\'|([^\\s>]+))/i', (string) $tag, $m)) {
            return html_entity_decode((string) ($m[1] ?? $m[2] ?? $m[3] ?? ''), ENT_QUOTES, 'UTF-8');
        }
        return '';
    }
    private function absolutize_public_resource_url($url, $base = '')
    {
        $url = trim((string) $url);
        if (preg_match('#^https?://#i', $url)) { return $url; }
        return rtrim((string) $base, '/') . '/' . ltrim($url, '/');
    }
    private function normalize_public_resource_url($url) { return trim(html_entity_decode((string) $url, ENT_QUOTES, 'UTF-8')); }

    public function duplicates(array $records): array
    {
        return $this->ultracache_select_final_duplicate_script_indexes($records);
    }
}

$passes = 0;
$failures = array();
$expect = static function ($condition, $label) use (&$passes, &$failures) {
    if ($condition) { $passes++; echo '[PASS] ' . $label . PHP_EOL; return; }
    $failures[] = $label; echo '[FAIL] ' . $label . PHP_EOL;
};

$h = new UltraCacheFinalJsDedupeHarness();
$external = static function ($src, $lane, $offset, $id = '', $type = '') {
    $attrs = ($id ? ' id="' . $id . '"' : '') . ($type ? ' type="' . $type . '"' : '') . ' src="' . $src . '"';
    if ('defer' === $lane) { $attrs .= ' defer'; }
    return array(
        'tag' => '<script' . $attrs . '></script>',
        'open' => '<script' . $attrs . '>',
        'offset' => $offset,
        'src' => $src,
        'id' => $id,
        'handle' => $id,
        'has_src' => true,
        'delayed' => ('delay' === $lane),
        'code' => '',
    );
};
$inline = static function ($handle, $code, $lane, $offset, $id = '') {
    $attrs = ($id ? ' id="' . $id . '"' : '') . ('defer' === $lane ? ' defer' : '');
    return array(
        'tag' => '<script' . $attrs . '>' . $code . '</script>',
        'open' => '<script' . $attrs . '>',
        'offset' => $offset,
        'src' => '',
        'id' => $id,
        'handle' => $handle,
        'has_src' => false,
        'delayed' => ('delay' === $lane),
        'code' => $code,
    );
};

$r = $h->duplicates(array(
    0 => $external('/theme/elementor-frontend.js?ver=1.0.15', 'delay', 10),
    1 => $external('/theme/elementor-frontend.js?ver=1.0.15', 'defer', 20),
));
$expect(isset($r[0]) && !isset($r[1]), 'DEFER survivor removes duplicate DELAY external payload');

$r = $h->duplicates(array(
    0 => $external('/theme/init.js', 'defer', 10),
    1 => $external('/theme/init.js', 'native', 30),
));
$expect(isset($r[0]) && !isset($r[1]), 'NATIVE survivor wins even when it appears later in DOM');

$r = $h->duplicates(array(
    0 => $external('/theme/app.js', 'native', 10, '', 'module'),
    1 => $external('/theme/app.js', 'native', 20),
));
$expect(empty($r), 'module and classic execution modes remain distinct');

$woof = 'const woof_front_nonce = "abc";';
$r = $h->duplicates(array(
    0 => $inline('woof_front-js-before', $woof, 'delay', 10, 'woof_front-js-before'),
    1 => $inline('woof_front-js-before', $woof, 'defer', 20, 'woof_front-js-before'),
));
$expect(isset($r[0]) && !isset($r[1]), 'handled inline duplicate is executed once after DELAY to DEFER promotion');

$bokifa = 'const bokifaSwiperBase = class {};';
$r = $h->duplicates(array(
    0 => $inline('bokifa-inline', $bokifa, 'native', 10),
    1 => $inline('bokifa-inline', $bokifa, 'native', 20),
));
$expect(!isset($r[0]) && isset($r[1]), 'identical handled inline payload keeps first same-lane instance');

$r = $h->duplicates(array(
    0 => $inline('', 'window.counter=(window.counter||0)+1;', 'native', 10),
    1 => $inline('', 'window.counter=(window.counter||0)+1;', 'native', 20),
));
$expect(empty($r), 'anonymous inline scripts are not deduped without handle/id authority');

$r = $h->duplicates(array(
    0 => array('tag'=>'<script id="state" type="application/ld+json">{}</script>','open'=>'<script id="state" type="application/ld+json">','offset'=>10,'src'=>'','id'=>'state','handle'=>'state','has_src'=>false,'delayed'=>false,'code'=>'{}'),
    1 => array('tag'=>'<script id="state" type="application/ld+json">{}</script>','open'=>'<script id="state" type="application/ld+json">','offset'=>20,'src'=>'','id'=>'state','handle'=>'state','has_src'=>false,'delayed'=>false,'code'=>'{}'),
));
$expect(empty($r), 'non-executable JSON script blocks are outside execution dedupe');

$htmlSource = (string) file_get_contents($root . '/includes/engine/js/class-js-html-rewrite-trait.php');
$outputSource = (string) file_get_contents($root . '/includes/engine/class-engine-html-output-trait.php');
$loaderSource = (string) file_get_contents($root . '/assets/js/delayed-js-loader.js');
$normalizePos = strpos($outputSource, "'normalize-js-dependency-lanes'");
$dedupePos = strpos($outputSource, "'dedupe-final-js-execution-identities'");
$expect(false !== $normalizePos && false !== $dedupePos && $normalizePos < $dedupePos, 'final execution dedupe runs after lane normalization');
$externalIdentitySnippet = <<<'SNIPPET'
$keys[] = 'src:' . $normalized . '|mode:' . $mode;
SNIPPET;
$inlineIdentitySnippet = <<<'SNIPPET'
$keys[] = 'inline:' . $handle . ':' . hash('sha256', $code) . '|mode:' . $mode;
SNIPPET;
$expect(false !== strpos($htmlSource, $externalIdentitySnippet), 'external identity includes normalized src and execution mode');
$expect(false !== strpos($htmlSource, $inlineIdentitySnippet), 'inline identity includes handle and exact content fingerprint');
$expect(false !== strpos($loaderSource, "keys.push('src:' + src + '|mode:' + mode)"), 'server final dedupe remains aligned with delayed-loader external identity contract');

$total = $passes + count($failures);
echo PHP_EOL . 'Result: ' . $passes . '/' . $total . ' PASS' . PHP_EOL;
if ($failures) { exit(1); }
