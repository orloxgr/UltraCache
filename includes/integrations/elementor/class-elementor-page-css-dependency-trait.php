<?php
/**
 * Elementor per-page generated CSS dependency reconciliation.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Engine_Elementor_Page_CSS_Dependency_Trait
{
    /** @var string Unresolved Elementor CSS dependency from the latest finalizer pass. */
    private $elementor_page_css_dependency_error = '';

    /** @var array<string,mixed> Request-local Elementor CSS dependency diagnostics. */
    private $elementor_page_css_dependency_diagnostics = array();

    /**
     * Return the unresolved Elementor CSS dependency error from the latest pass.
     *
     * @return string
     */
    private function get_elementor_page_css_dependency_error()
    {
        return (string) $this->elementor_page_css_dependency_error;
    }

    /**
     * Return request-local Elementor CSS dependency diagnostics.
     *
     * @return array<string,mixed>
     */
    private function get_elementor_page_css_dependency_diagnostics()
    {
        return is_array($this->elementor_page_css_dependency_diagnostics)
            ? $this->elementor_page_css_dependency_diagnostics
            : array();
    }

    /**
     * Resolve the canonical Elementor generated-CSS URL and filesystem roots.
     *
     * Elementor Core\Files\Base publishes generated files below the active
     * WordPress uploads base in elementor/css/. Use wp_upload_dir() so custom
     * and multisite uploads layouts remain authoritative.
     *
     * @return array{url_path:string,dir:string}|array{}
     */
    private function get_elementor_page_css_dependency_roots()
    {
        $uploads = wp_upload_dir(null, false);
        if (!is_array($uploads) || !empty($uploads['error'])) {
            return array();
        }

        $base_url = isset($uploads['baseurl']) ? (string) $uploads['baseurl'] : '';
        $base_dir = isset($uploads['basedir']) ? (string) $uploads['basedir'] : '';
        if ('' === $base_url || '' === $base_dir) {
            return array();
        }

        $base_url_path = wp_parse_url($base_url, PHP_URL_PATH);
        if (false === $base_url_path) {
            return array();
        }
        $base_url_path = is_string($base_url_path) ? $base_url_path : '';

        return array(
            'url_path' => '/' . ltrim(rtrim($base_url_path, '/') . '/elementor/css/', '/'),
            'dir' => trailingslashit(wp_normalize_path($base_dir)) . 'elementor/css/',
        );
    }

    /**
     * Regenerate and verify Elementor post CSS files referenced by one rendered
     * page before UltraCache stores that page.
     *
     * The dependency IDs are discovered from the rendered page itself. For a
     * missing dependency, UltraCache follows Elementor's own per-document CSS
     * generation contract: resolve the frontend document, create Post_CSS for
     * that ID, call update(), then verify the generated file. Existing files
     * are not regenerated.
     *
     * @param string $html    Final frontend HTML being prepared for cache storage.
     * @param array  $context Finalizer context.
     * @return string
     */
    private function reconcile_elementor_page_css_dependencies($html, array $context = array())
    {
        $this->elementor_page_css_dependency_error = '';
        $this->elementor_page_css_dependency_diagnostics = array(
            'source' => sanitize_key((string) ($context['source'] ?? '')),
            'discovered' => 0,
            'generated' => 0,
            'verified' => 0,
            'rewritten' => 0,
            'empty' => 0,
            'failed' => 0,
            'postIds' => array(),
            'failures' => array(),
        );

        if (!is_string($html) || '' === $html || false === stripos($html, 'elementor/css/post-')) {
            return $html;
        }

        if (!class_exists('WP_HTML_Tag_Processor')) {
            $this->elementor_page_css_dependency_error = 'Elementor CSS dependency validation requires WP_HTML_Tag_Processor.';
            $this->elementor_page_css_dependency_diagnostics['failed'] = 1;
            $this->elementor_page_css_dependency_diagnostics['failures'][] = 'html-tag-processor-unavailable';
            return $html;
        }

        $post_css_class = 'Elementor\\Core\\Files\\CSS\\Post';
        if (!class_exists($post_css_class)
            || !method_exists($post_css_class, 'create')) {
            $this->elementor_page_css_dependency_error = 'Elementor post CSS generation API is unavailable.';
            $this->elementor_page_css_dependency_diagnostics['failed'] = 1;
            $this->elementor_page_css_dependency_diagnostics['failures'][] = 'post-css-api-unavailable';
            return $html;
        }

        if (!class_exists('Elementor\\Plugin') || !method_exists('Elementor\\Plugin', 'instance')) {
            $this->elementor_page_css_dependency_error = 'Elementor runtime is unavailable for CSS dependency generation.';
            $this->elementor_page_css_dependency_diagnostics['failed'] = 1;
            $this->elementor_page_css_dependency_diagnostics['failures'][] = 'elementor-runtime-unavailable';
            return $html;
        }

        $roots = $this->get_elementor_page_css_dependency_roots();
        if (empty($roots['url_path']) || empty($roots['dir'])) {
            $this->elementor_page_css_dependency_error = 'Elementor CSS dependency roots could not be resolved from WordPress uploads.';
            $this->elementor_page_css_dependency_diagnostics['failed'] = 1;
            $this->elementor_page_css_dependency_diagnostics['failures'][] = 'uploads-root-unavailable';
            return $html;
        }

        try {
            $elementor = \Elementor\Plugin::instance();
        } catch (Throwable $throwable) {
            $this->elementor_page_css_dependency_error = 'Elementor runtime could not be initialized for CSS dependency generation.';
            $this->elementor_page_css_dependency_diagnostics['failed'] = 1;
            $this->elementor_page_css_dependency_diagnostics['failures'][] = 'elementor-runtime-initialization-failed';
            return $html;
        }

        if (!is_object($elementor)
            || !isset($elementor->documents)
            || !is_object($elementor->documents)
            || !method_exists($elementor->documents, 'get_doc_for_frontend')) {
            $this->elementor_page_css_dependency_error = 'Elementor frontend document manager is unavailable for CSS dependency generation.';
            $this->elementor_page_css_dependency_diagnostics['failed'] = 1;
            $this->elementor_page_css_dependency_diagnostics['failures'][] = 'document-manager-unavailable';
            return $html;
        }

        $expected_url_dir = '/' . ltrim((string) $roots['url_path'], '/');
        $expected_fs_dir = trailingslashit(wp_normalize_path((string) $roots['dir']));
        $seen = array();
        $changed = false;

        try {
            $processor = new WP_HTML_Tag_Processor($html);

            while ($processor->next_tag('LINK')) {
                $href = $processor->get_attribute('href');
                if (!is_string($href) || '' === $href || false === stripos($href, 'post-')) {
                    continue;
                }

                $decoded_href = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $href_path = wp_parse_url($decoded_href, PHP_URL_PATH);
                if (!is_string($href_path) || '' === $href_path) {
                    continue;
                }

                $href_path = '/' . ltrim($href_path, '/');
                if (0 !== strpos($href_path, $expected_url_dir)) {
                    continue;
                }

                $file_name = substr($href_path, strlen($expected_url_dir));
                if (!is_string($file_name) || 1 !== preg_match('/^post-([1-9][0-9]*)\.css$/', $file_name, $matches)) {
                    continue;
                }

                $post_id = absint($matches[1] ?? 0);
                if ($post_id < 1) {
                    continue;
                }

                if (!isset($seen[$post_id])) {
                    $seen[$post_id] = array(
                        'success' => false,
                        'empty' => false,
                        'version' => 0,
                    );

                    $this->elementor_page_css_dependency_diagnostics['discovered']++;
                    $this->elementor_page_css_dependency_diagnostics['postIds'][] = $post_id;

                    try {
                        $document = $elementor->documents->get_doc_for_frontend($post_id);
                        if (!is_object($document)) {
                            throw new RuntimeException('Elementor frontend document could not be resolved.');
                        }

                        $css_file = $post_css_class::create($post_id);
                        if (!is_object($css_file)
                            || !method_exists($css_file, 'get_path')
                            || !method_exists($css_file, 'get_meta')
                            || !method_exists($css_file, 'update')) {
                            throw new RuntimeException('Elementor Post_CSS object does not expose the required generation contract.');
                        }

                        $css_path = wp_normalize_path((string) $css_file->get_path());
                        $expected_path = $expected_fs_dir . 'post-' . $post_id . '.css';
                        if ('' === $css_path || $css_path !== $expected_path) {
                            throw new RuntimeException('Elementor Post_CSS path does not match the rendered dependency path.');
                        }

                        $meta = $css_file->get_meta();
                        $meta = is_array($meta) ? $meta : array();
                        $needs_generation = !is_file($css_path) || max(0, (int) ($meta['time'] ?? 0)) < 1;

                        if ($needs_generation) {
                            $css_file->update();
                            $this->elementor_page_css_dependency_diagnostics['generated']++;
                            $meta = $css_file->get_meta();
                            $meta = is_array($meta) ? $meta : array();
                        }

                        $status = sanitize_key((string) ($meta['status'] ?? ''));
                        if ('empty' === $status) {
                            $seen[$post_id]['success'] = true;
                            $seen[$post_id]['empty'] = true;
                            $seen[$post_id]['version'] = max(0, (int) ($meta['time'] ?? 0));
                            $this->elementor_page_css_dependency_diagnostics['empty']++;
                        } elseif (!is_file($css_path) || !is_readable($css_path)) {
                            throw new RuntimeException('Elementor Post_CSS update completed without a readable generated CSS file.');
                        } else {
                            $seen[$post_id]['success'] = true;
                            $seen[$post_id]['version'] = max(0, (int) ($meta['time'] ?? 0));
                            $this->elementor_page_css_dependency_diagnostics['verified']++;
                        }
                    } catch (Throwable $throwable) {
                        $message = sanitize_text_field($throwable->getMessage());
                        $this->elementor_page_css_dependency_diagnostics['failed']++;
                        $this->elementor_page_css_dependency_diagnostics['failures'][] = array(
                            'postId' => $post_id,
                            'message' => $message,
                        );
                        if ('' === $this->elementor_page_css_dependency_error) {
                            $this->elementor_page_css_dependency_error = sprintf(
                                /* translators: 1: Elementor document ID, 2: dependency failure reason. */
                                __('Elementor CSS dependency post-%1$d.css is unresolved: %2$s', 'ultracache'),
                                $post_id,
                                $message
                            );
                        }
                    }
                }

                $result = $seen[$post_id];
                if (empty($result['success'])) {
                    continue;
                }

                if (!empty($result['empty'])) {
                    $processor->remove_attribute('href');
                    $processor->remove_attribute('rel');
                    $processor->set_attribute('data-ultracache-elementor-css-empty', (string) $post_id);
                    $changed = true;
                    continue;
                }

                $version = max(0, (int) ($result['version'] ?? 0));
                if ($version > 0) {
                    $updated_href = add_query_arg('ver', $version, $decoded_href);
                    if (is_string($updated_href) && '' !== $updated_href && $updated_href !== $decoded_href) {
                        $processor->set_attribute('href', $updated_href);
                        $this->elementor_page_css_dependency_diagnostics['rewritten']++;
                        $changed = true;
                    }
                }
            }

            if (!$changed) {
                return $html;
            }

            $updated_html = $processor->get_updated_html();
            return is_string($updated_html) && '' !== $updated_html ? $updated_html : $html;
        } catch (Throwable $throwable) {
            $message = sanitize_text_field($throwable->getMessage());
            if ('' === $this->elementor_page_css_dependency_error) {
                $this->elementor_page_css_dependency_error = sprintf(
                    /* translators: %s: HTML dependency reconciliation error. */
                    __('Elementor CSS dependency reconciliation failed: %s', 'ultracache'),
                    $message
                );
            }
            $this->elementor_page_css_dependency_diagnostics['failed']++;
            $this->elementor_page_css_dependency_diagnostics['failures'][] = array(
                'postId' => 0,
                'message' => $message,
            );
            return $html;
        }
    }
}
