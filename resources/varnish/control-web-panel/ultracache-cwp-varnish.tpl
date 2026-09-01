# UltraCache Control Web Panel (CWP) per-domain Varnish template.
# Stack: Nginx -> Varnish -> Apache/PHP.
#
# Install this file directly as the domain vhost template; no post-download
# edits or additional per-template control layer are required. UltraCache may use:
#   - local-direct exact PURGE through this vhost, or
#   - the existing Varnish admin connection for exact, batch, HTML, and host BAN.
#
# WooCommerce shared-parent reuse requires two independent UltraCache signals:
#   1. Request opt-in cookie: ultracache_esi_optin=1
#   2. Response approval: X-UltraCache-ESI-Shared-Parent: 1
#
# Without both signals, cart/session requests remain PASS. Public/private ESI,
# canonical AVIF/WebP/original HTML variants, object metadata for admin BAN,
# origin-controlled grace, independent keep, and cache observability are included in this one per-domain file.

# Exact PURGE is intentionally local-direct only. Public requests arriving
# through the front proxy carry the real visitor address and are rejected.
acl %backend_domain%_purge_acl {
    "127.0.0.1";
    "::1";
}

backend %backend_domain%  {
    .host = "%proxy_ip%";
    .port = "%proxy_port%";
}

sub vcl_recv {
    if (regsub(regsub(req.http.host, ":[0-9]+$", ""), "[.]$", "") == "%domain%") {
        set req.backend_hint = %backend_domain% ;

        # Preserve the original client IP supplied by the front proxy.
        if (req.restarts == 0) {
            if (req.http.X-Real-IP) {
                set req.http.X-Forwarded-For = req.http.X-Real-IP;
            } else if (!req.http.X-Forwarded-For) {
                set req.http.X-Forwarded-For = client.ip;
            }
        }

        # Standalone exact-object invalidation. This does not depend on
        # UltraCache and purges the requested cache key together with its Vary
        # variants. Site-wide BAN and soft purge remain separate capabilities.
        if (req.method == "PURGE") {
            if (client.ip !~ %backend_domain%_purge_acl) {
                return (synth(403, "PURGE source denied"));
            }

            # A public request proxied by Nginx still has a loopback client.ip.
            # Require the forwarded original client to be loopback as well.
            if (
                req.http.X-Real-IP &&
                req.http.X-Real-IP !~ "^(127[.]0[.]0[.]1|::1)$"
            ) {
                return (synth(403, "PURGE proxy source denied"));
            }

            if (
                req.http.X-Forwarded-For &&
                req.http.X-Forwarded-For !~ "^(127[.]0[.]0[.]1|::1)$"
            ) {
                return (synth(403, "PURGE forwarded source denied"));
            }

            return (purge);
        }

        # Private/session ESI transport.
        if (req.esi_level == 0) {
            # Drop spoofed internal ESI transport headers from clients.
            unset req.http.X-ESI-Private-Request;
            unset req.http.X-ESI-Request-Level;
            unset req.http.X-ESI-Original-Cookie;
            unset req.http.X-UltraCache-ESI-Candidate;
            unset req.http.X-UltraCache-ESI-Cookie-Check;
            unset req.http.X-UltraCache-ESI-Opt-In;
            unset req.http.X-UltraCache-ESI-Shared-Parent;

            # Capture the request-side UltraCache opt-in before removing its
            # marker cookie from the request forwarded to the origin.
            if (req.http.Cookie ~ "(?i)(^|;[ ]*)ultracache_esi_optin=1(?:;|$)") {
                set req.http.X-UltraCache-ESI-Opt-In = "1";
            }

            # Build the private-fragment carrier from allowlisted cookies only.
            # Never copy the complete browser Cookie header into an ESI subrequest.
            if (req.http.Cookie ~ "(?i)(^|;[ ]*)esi_session=") {
                set req.http.X-ESI-Original-Cookie = regsub(
                    req.http.Cookie,
                    "(?i)^.*?(?:^|;[ ]*)(esi_session=[^;]*).*$",
                    "\1"
                );
            }

            if (req.http.Cookie ~ "(?i)(^|;[ ]*)woocommerce_items_in_cart=") {
                if (req.http.X-ESI-Original-Cookie) {
                    set req.http.X-ESI-Original-Cookie = req.http.X-ESI-Original-Cookie + "; " + regsub(
                        req.http.Cookie,
                        "(?i)^.*?(?:^|;[ ]*)(woocommerce_items_in_cart=[^;]*).*$",
                        "\1"
                    );
                } else {
                    set req.http.X-ESI-Original-Cookie = regsub(
                        req.http.Cookie,
                        "(?i)^.*?(?:^|;[ ]*)(woocommerce_items_in_cart=[^;]*).*$",
                        "\1"
                    );
                }
            }

            if (req.http.Cookie ~ "(?i)(^|;[ ]*)woocommerce_cart_hash=") {
                if (req.http.X-ESI-Original-Cookie) {
                    set req.http.X-ESI-Original-Cookie = req.http.X-ESI-Original-Cookie + "; " + regsub(
                        req.http.Cookie,
                        "(?i)^.*?(?:^|;[ ]*)(woocommerce_cart_hash=[^;]*).*$",
                        "\1"
                    );
                } else {
                    set req.http.X-ESI-Original-Cookie = regsub(
                        req.http.Cookie,
                        "(?i)^.*?(?:^|;[ ]*)(woocommerce_cart_hash=[^;]*).*$",
                        "\1"
                    );
                }
            }

            if (req.http.Cookie ~ "(?i)(^|;[ ]*)wp_woocommerce_session_[^=; ]+=") {
                if (req.http.X-ESI-Original-Cookie) {
                    set req.http.X-ESI-Original-Cookie = req.http.X-ESI-Original-Cookie + "; " + regsub(
                        req.http.Cookie,
                        "(?i)^.*?(?:^|;[ ]*)((?:wp_woocommerce_session_[^=; ]+)=[^;]*).*$",
                        "\1"
                    );
                } else {
                    set req.http.X-ESI-Original-Cookie = regsub(
                        req.http.Cookie,
                        "(?i)^.*?(?:^|;[ ]*)((?:wp_woocommerce_session_[^=; ]+)=[^;]*).*$",
                        "\1"
                    );
                }
            }

            # Remove UltraCache-only marker cookies from the origin request.
            if (req.http.Cookie ~ "(?i)(^|;[ ]*)(esi_session|ultracache_esi_optin)=") {
                set req.http.Cookie = regsuball(
                    req.http.Cookie,
                    "(?i)(^|;[ ]*)(esi_session|ultracache_esi_optin)=[^;]*",
                    ""
                );
                set req.http.Cookie = regsuball(req.http.Cookie, "^[; ]+|[; ]+$", "");
                set req.http.Cookie = regsuball(req.http.Cookie, ";[ ]*;", ";");

                if (req.http.Cookie == "") {
                    unset req.http.Cookie;
                }
            }
        } else {
            unset req.http.X-ESI-Private-Request;
            unset req.http.X-ESI-Request-Level;
            unset req.http.X-UltraCache-ESI-Candidate;
            unset req.http.X-UltraCache-ESI-Cookie-Check;
            unset req.http.X-UltraCache-ESI-Opt-In;
            unset req.http.X-UltraCache-ESI-Shared-Parent;

            if (
                req.url ~ "(?i)([?&])esi_scope=private(?:&|$)" &&
                req.url ~ "(?i)([?&])(ultracache_esi|ultracache_esi_probe_private_fragment)="
            ) {
                set req.http.X-ESI-Private-Request = "1";
                set req.http.X-ESI-Request-Level = "1";
                set req.http.X-Cache-Mode = "PASS";

                if (req_top.http.X-ESI-Original-Cookie) {
                    set req.http.Cookie = req_top.http.X-ESI-Original-Cookie;
                } else {
                    unset req.http.Cookie;
                }

                return (pass);
            }

            # Public ESI fragments never inherit visitor cookies.
            unset req.http.Cookie;
        }

        # Never cache authenticated, upgraded or non-read-only requests.
        if (req.http.Authorization) {
            set req.http.X-Cache-Mode = "PASS";
            return (pass);
        }

        if (req.http.Upgrade) {
            set req.http.X-Cache-Mode = "PASS";
            return (pass);
        }

        if (req.method != "GET" && req.method != "HEAD") {
            set req.http.X-Cache-Mode = "PASS";
            return (pass);
        }

        # Certificate validation requests must reach the origin.
        if (
            req.url ~ "^/\.well-known/acme-challenge/" ||
            req.url ~ "^/\.well-known/pki-validation/"
        ) {
            set req.http.X-Cache-Mode = "PASS";
            return (pass);
        }

        # Legacy AJAX requests.
        if (req.http.X-Requested-With ~ "(?i)^XMLHttpRequest$") {
            set req.http.X-Cache-Mode = "PASS";
            return (pass);
        }

        # WordPress administration, authentication, cron, comments and APIs.
        if (
            req.url ~ "(?i)^/wp-admin(?:/|\?|$)" ||
            req.url ~ "(?i)^/wp-login\.php(?:\?|$)" ||
            req.url ~ "(?i)^/wp-cron\.php(?:\?|$)" ||
            req.url ~ "(?i)^/wp-comments-post\.php(?:\?|$)" ||
            req.url ~ "(?i)^/xmlrpc\.php(?:\?|$)" ||
            req.url ~ "(?i)^/wp-json(?:/|\?|$)"
        ) {
            set req.http.X-Cache-Mode = "PASS";
            return (pass);
        }

        # WordPress previews, editors, REST requests and search pages.
        if (
            req.url ~ "(?i)(\?|&)(preview|preview_id|preview_nonce|customize_changeset_uuid|elementor-preview|et_fb|vc_editable|rest_route|s|nocache)="
        ) {
            set req.http.X-Cache-Mode = "PASS";
            return (pass);
        }

        # WooCommerce customer-specific actions are request-visible and can be
        # passed here without assuming site-specific Cart/Checkout/Account paths.
        # Dynamic WooCommerce page URLs are resolved by UltraCache itself and
        # protected through the origin's standard private/no-store cache policy.
        if (req.url ~ "(?i)(\?|&)(add-to-cart|wc-ajax|wc-api)=") {
            set req.http.X-Cache-Mode = "PASS";
            return (pass);
        }

        # Affiliate/referral requests may set visitor-specific cookies.
        if (req.url ~ "(?i)(\?|&)ref=") {
            set req.http.X-Cache-Mode = "PASS";
            return (pass);
        }

        # Remove common marketing parameters without changing application parameters.
        if (
            req.url ~ "(?i)(\?|&)(utm_[a-z0-9_]+|gclid|dclid|fbclid|msclkid|gbraid|wbraid|igshid)="
        ) {
            set req.url = regsuball(
                req.url,
                "(?i)([?&])(utm_[a-z0-9_]+|gclid|dclid|fbclid|msclkid|gbraid|wbraid|igshid)=[^&]*",
                "\1"
            );
            set req.url = regsuball(req.url, "\?&", "?");
            set req.url = regsuball(req.url, "&&+", "&");
            set req.url = regsuball(req.url, "[?&]+$", "");
        }

        # Varnish ESI works with identity/gzip origin responses.
        if (req.http.Accept-Encoding) {
            if (
                req.url ~ "(?i)\.(avif|gif|gz|ico|jpeg|jpg|mp3|mp4|ogg|ogv|pdf|png|svg|webm|webp|woff|woff2|zip)(\?.*)?$"
            ) {
                unset req.http.Accept-Encoding;
            } else if (req.http.Accept-Encoding ~ "(?i)gzip") {
                set req.http.Accept-Encoding = "gzip";
            } else {
                unset req.http.Accept-Encoding;
            }
        }

        # Static assets are shared and do not need browser cookies.
        # HTML/HTM are intentionally excluded because they may be dynamic rewrites.
        if (
            req.url ~ "(?i)\.(avif|bmp|css|eot|gif|ico|jpeg|jpg|js|map|mp3|mp4|ogg|ogv|pdf|png|svg|ttf|webm|webp|woff|woff2|zip)(\?.*)?$"
        ) {
            unset req.http.Cookie;
            return (hash);
        }

        # Remove only cookies known not to personalize rendered HTML.
        if (req.http.Cookie) {
            set req.http.Cookie = regsuball(
                req.http.Cookie,
                "(?i)(^|;[ ]*)(__utm[a-z0-9_]*|_ga(_[a-z0-9]+)?|_gid|_gat(_[a-z0-9]+)?|_fbp|has_js)=[^;]*",
                ""
            );
            set req.http.Cookie = regsuball(
                req.http.Cookie,
                "(?i)(^|;[ ]*)(wordpress_test_cookie|wp-settings-[0-9]+|wp-settings-time-[0-9]+)=[^;]*",
                ""
            );
            set req.http.Cookie = regsuball(req.http.Cookie, "^[; ]+|[; ]+$", "");
            set req.http.Cookie = regsuball(req.http.Cookie, ";[ ]*;", ";");

            if (req.http.Cookie == "") {
                unset req.http.Cookie;
            } else if (
                req.http.Cookie ~ "(?i)(^|;[ ]*)(woocommerce_items_in_cart|woocommerce_cart_hash|wp_woocommerce_session_[^=; ]+)="
            ) {
                # WooCommerce requests enter the shared-parent handshake only
                # when UltraCache has explicitly opted in this browser request.
                # Without the marker, preserve normal WooCommerce PASS behavior.
                if (req.http.X-UltraCache-ESI-Opt-In != "1") {
                    set req.http.X-Cache-Mode = "PASS";
                    return (pass);
                }

                set req.http.X-UltraCache-ESI-Cookie-Check = req.http.Cookie;
                set req.http.X-UltraCache-ESI-Cookie-Check = regsuball(
                    req.http.X-UltraCache-ESI-Cookie-Check,
                    "(?i)(^|;[ ]*)(woocommerce_items_in_cart|woocommerce_cart_hash|wp_woocommerce_session_[^=; ]+)=[^;]*",
                    ""
                );
                set req.http.X-UltraCache-ESI-Cookie-Check = regsuball(
                    req.http.X-UltraCache-ESI-Cookie-Check,
                    "^[; ]+|[; ]+$",
                    ""
                );
                set req.http.X-UltraCache-ESI-Cookie-Check = regsuball(
                    req.http.X-UltraCache-ESI-Cookie-Check,
                    ";[ ]*;",
                    ";"
                );

                if (req.http.X-UltraCache-ESI-Cookie-Check == "") {
                    set req.http.X-UltraCache-ESI-Candidate = "1";
                    unset req.http.X-UltraCache-ESI-Cookie-Check;
                } else {
                    # Another cookie may represent login, language, currency,
                    # membership or plugin-specific state. Preserve normal PASS.
                    unset req.http.X-UltraCache-ESI-Cookie-Check;
                    set req.http.X-Cache-Mode = "PASS";
                    return (pass);
                }
            } else {
                # Any other remaining cookie may personalize rendered HTML.
                set req.http.X-Cache-Mode = "PASS";
                return (pass);
            }
        }

        # Canonicalize browser Accept values into the three HTML representation
        # buckets used by modern WordPress image negotiation. Varnish compares
        # Vary request headers exactly, so raw browser Accept strings would create
        # many equivalent objects. A media type explicitly disabled with q=0 is
        # never selected.
        if (
            req.http.Accept &&
            req.http.Accept ~ "(?i)(^|,)[ ]*image/avif(?=[ ]*(?:;|,|$))(?![^,]*;[ ]*q[ ]*=[ ]*0(?:[.]0*)?(?:[ ;,]|$))"
        ) {
            set req.http.Accept = "text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8";
        } else if (
            req.http.Accept &&
            req.http.Accept ~ "(?i)(^|,)[ ]*image/webp(?=[ ]*(?:;|,|$))(?![^,]*;[ ]*q[ ]*=[ ]*0(?:[.]0*)?(?:[ ;,]|$))"
        ) {
            set req.http.Accept = "text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8";
        } else {
            set req.http.Accept = "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8";
        }

        return (hash);
    }
}

sub vcl_hit {
    if (regsub(regsub(req.http.host, ":[0-9]+$", ""), "[.]$", "") == "%domain%") {
        # WooCommerce ESI candidates may reuse only objects explicitly approved
        # by UltraCache. Anonymous/non-approved objects are bypassed so a normal
        # WooCommerce site can never receive an empty shared cart parent.
        if (
            req.http.X-UltraCache-ESI-Candidate == "1" &&
            obj.http.X-UltraCache-ESI-Shared-Parent != "1"
        ) {
            set req.http.X-Cache-Mode = "PASS";
            return (pass);
        }
    }
}

sub vcl_backend_fetch {
    if (regsub(regsub(bereq.http.host, ":[0-9]+$", ""), "[.]$", "") == "%domain%") {
        # Advertise generic ESI capability to any compatible application.
        set bereq.http.Surrogate-Capability = "varnish=ESI/1.0";

        # Never expose internal scratch/cookie-carrier headers to the origin.
        # X-UltraCache-ESI-Candidate intentionally remains: the plugin uses it
        # to decide whether it can safely approve a shared ESI parent response.
        unset bereq.http.X-ESI-Original-Cookie;
        unset bereq.http.X-UltraCache-ESI-Cookie-Check;
        unset bereq.http.X-UltraCache-ESI-Opt-In;
        unset bereq.http.X-UltraCache-ESI-Shared-Parent;
    }
}

sub vcl_backend_response {
    if (regsub(regsub(bereq.http.host, ":[0-9]+$", ""), "[.]$", "") == "%domain%") {
        # Private ESI fragments must execute for every delivery and must never be stored.
        if (bereq.http.X-ESI-Private-Request == "1") {
            set beresp.ttl = 0s;
            set beresp.uncacheable = true;
            set beresp.http.Cache-Control = "private, no-store";
            set beresp.http.Surrogate-Control = "no-store";
            return (deliver);
        }

        # WooCommerce ESI handshake.
        #
        # The request is allowed to hash while its real WooCommerce cookies are
        # still sent to the origin. Varnish stores/reuses a shared parent only
        # when UltraCache explicitly confirms that the parent is non-personalized.
        if (bereq.http.X-UltraCache-ESI-Candidate == "1") {
            if (
                beresp.http.X-UltraCache-ESI-Shared-Parent == "1" &&
                beresp.status == 200 &&
                beresp.http.Content-Type ~ "(?i)^text/html" &&
                beresp.http.Surrogate-Control ~ "(?i)ESI/1[.]0"
            ) {
                # Approval is valid only when the response itself has no normal
                # private/no-store signal or Set-Cookie header.
                if (
                    beresp.http.Set-Cookie ||
                    beresp.http.Cache-Control ~ "(?i)(private|no-cache|no-store)" ||
                    beresp.http.Pragma ~ "(?i)no-cache" ||
                    beresp.http.Vary == "*"
                ) {
                    unset beresp.http.X-UltraCache-ESI-Shared-Parent;
                    set beresp.ttl = 0s;
                    set beresp.uncacheable = true;
                    return (deliver);
                }

                set beresp.do_esi = true;
                unset beresp.http.Surrogate-Control;
            } else {
                # No approval: preserve correct WooCommerce behavior. If an older
                # UltraCache version emitted ESI markup, compose it for this one
                # delivery but never store/reuse the parent response.
                if (
                    beresp.status == 200 &&
                    beresp.http.Content-Type ~ "(?i)^text/html" &&
                    beresp.http.Surrogate-Control ~ "(?i)ESI/1[.]0"
                ) {
                    set beresp.do_esi = true;
                    unset beresp.http.Surrogate-Control;
                }

                unset beresp.http.X-UltraCache-ESI-Shared-Parent;
                set beresp.ttl = 0s;
                set beresp.uncacheable = true;
                return (deliver);
            }
        }

        # Never store authentication, rate-limit or backend-error responses.
        if (
            beresp.status == 401 ||
            beresp.status == 403 ||
            beresp.status == 429 ||
            beresp.status >= 500
        ) {
            set beresp.ttl = 0s;
            set beresp.uncacheable = true;
            return (deliver);
        }

        # Respect the origin application's cache policy.
        if (
            beresp.http.Set-Cookie ||
            beresp.http.Cache-Control ~ "(?i)(private|no-cache|no-store)" ||
            beresp.http.Pragma ~ "(?i)no-cache" ||
            beresp.http.Vary == "*"
        ) {
            set beresp.ttl = 0s;
            set beresp.uncacheable = true;
            return (deliver);
        }

        # Enable ESI only when the origin explicitly requests it.
        if (
            beresp.status == 200 &&
            beresp.http.Content-Type ~ "(?i)^text/html" &&
            beresp.http.Surrogate-Control ~ "(?i)ESI/1[.]0"
        ) {
            set beresp.do_esi = true;
            unset beresp.http.Surrogate-Control;
        }

        # Store object-side metadata for efficient ban-lurker processing. These
        # headers are removed in vcl_deliver and are never exposed to clients.
        set beresp.http.X-Cache-Object-Host = "%domain%";
        set beresp.http.X-Cache-Object-URL = bereq.url;

        # Varnish derives grace from the origin Cache-Control
        # stale-while-revalidate value emitted by UltraCache Automation. Keep
        # remains a smaller, independent conditional-revalidation window.
        set beresp.keep = 5m;

        return (deliver);
    }
}

sub vcl_deliver {
    if (regsub(regsub(req.http.host, ":[0-9]+$", ""), "[.]$", "") == "%domain%") {
        if (req.http.X-Cache-Mode == "PASS") {
            set resp.http.X-Cache = "PASS";
        } else if (obj.hits > 0) {
            set resp.http.X-Cache = "HIT";
            set resp.http.X-Cache-Hits = obj.hits;
        } else {
            set resp.http.X-Cache = "MISS";
        }

        # Internal handshake headers are stored for VCL decisions only.
        unset resp.http.X-UltraCache-ESI-Shared-Parent;
        unset resp.http.X-UltraCache-ESI-Candidate;
        unset resp.http.X-UltraCache-ESI-Opt-In;
        unset resp.http.X-Cache-Object-Host;
        unset resp.http.X-Cache-Object-URL;
    }
}
