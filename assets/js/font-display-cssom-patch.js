(function () {
    if (window.__ultracacheFontDisplayCssomPatch) {
        return;
    }
    window.__ultracacheFontDisplayCssomPatch = 1;

    var RX = /@font-face\s*\{[^}]*\}/gi;
    var MAX_SHEETS = 48;
    var MAX_RULES = 2500;
    var patchedRules = 0;
    var scheduled = false;

    function root() {
        return document.documentElement || document.body || document.head;
    }

    function mark(key, value) {
        try {
            var r = root();
            if (r) {
                r.setAttribute('data-ultracache-font-display-' + key, String(value));
            }
        } catch (e) {}
    }

    function patchBlock(block) {
        if (!block || String(block).toLowerCase().indexOf('@font-face') === -1) {
            return block;
        }
        if (/font-display\s*:/i.test(block)) {
            return block.replace(/font-display\s*:\s*[^;}]+;?/i, 'font-display: swap;');
        }
        return block.replace(/}\s*$/, ';font-display: swap;}').replace(/\{\s*;/, '{');
    }

    function patchText(css) {
        css = String(css || '');
        if (css.toLowerCase().indexOf('@font-face') === -1) {
            return css;
        }
        return css.replace(RX, function (block) {
            return patchBlock(block);
        });
    }

    function patchStyleNode(node) {
        if (!node || node.nodeType !== 1 || String(node.tagName || '').toLowerCase() !== 'style') {
            return;
        }
        var type = String(node.getAttribute('type') || '').toLowerCase();
        if (type && type !== 'text/css') {
            return;
        }
        var css = node.textContent || '';
        if (css.toLowerCase().indexOf('@font-face') === -1) {
            return;
        }
        var patched = patchText(css);
        if (patched !== css) {
            node.textContent = patched;
            node.setAttribute('data-ultracache-font-display-patched', '1');
        }
    }

    function patchRule(sheet, rule, index) {
        try {
            if (!rule || patchedRules >= MAX_RULES) {
                return;
            }
            var text = rule.cssText || '';
            if (String(text).toLowerCase().indexOf('@font-face') === -1) {
                return;
            }
            patchedRules++;
            if (rule.style && rule.style.setProperty) {
                try {
                    rule.style.setProperty('font-display', 'swap');
                    return;
                } catch (e) {}
            }
            var patched = patchText(text);
            if (patched !== text && sheet && sheet.deleteRule && sheet.insertRule) {
                sheet.deleteRule(index);
                sheet.insertRule(patched, index);
            }
        } catch (e) {}
    }

    function patchSheets() {
        var sheets = document.styleSheets || [];
        var sheetCount = 0;
        patchedRules = 0;
        for (var i = 0; i < sheets.length && sheetCount < MAX_SHEETS && patchedRules < MAX_RULES; i++) {
            var rules;
            try {
                rules = sheets[i].cssRules || sheets[i].rules;
            } catch (e) {
                continue;
            }
            if (!rules) {
                continue;
            }
            sheetCount++;
            for (var j = 0; j < rules.length && patchedRules < MAX_RULES; j++) {
                patchRule(sheets[i], rules[j], j);
            }
        }
        mark('cssom-sheets', sheetCount);
        mark('cssom-rules', patchedRules);
    }

    function patchStyleNodes(rootNode) {
        try {
            var base = rootNode && rootNode.querySelectorAll ? rootNode : document;
            var styles = base.querySelectorAll ? base.querySelectorAll('style') : [];
            for (var i = 0; i < styles.length; i++) {
                patchStyleNode(styles[i]);
            }
            if (rootNode && rootNode.nodeType === 1 && String(rootNode.tagName || '').toLowerCase() === 'style') {
                patchStyleNode(rootNode);
            }
        } catch (e) {}
    }

    function idle(cb) {
        if ('requestIdleCallback' in window) {
            window.requestIdleCallback(cb, { timeout: 1200 });
            return;
        }
        setTimeout(cb, 80);
    }

    function scheduleSheets() {
        if (scheduled) {
            return;
        }
        scheduled = true;
        idle(function () {
            scheduled = false;
            patchSheets();
        });
    }

    try {
        var proto = window.CSSStyleSheet && window.CSSStyleSheet.prototype;
        if (proto && proto.insertRule && !proto.__ultracacheFontDisplayPatched) {
            var insertRule = proto.insertRule;
            proto.insertRule = function (rule, index) {
                return insertRule.call(this, patchText(rule), index);
            };
            if (proto.addRule) {
                var addRule = proto.addRule;
                proto.addRule = function (selector, style, index) {
                    if (String(selector || '').toLowerCase() === '@font-face') {
                        style = patchText('@font-face{' + String(style || '') + '}').replace(/^@font-face\s*\{|}\s*$/gi, '');
                    }
                    return addRule.call(this, selector, style, index);
                };
            }
            proto.__ultracacheFontDisplayPatched = 1;
        }
    } catch (e) {}

    patchStyleNodes(document);
    scheduleSheets();

    try {
        var mo = new MutationObserver(function (list) {
            for (var i = 0; i < list.length; i++) {
                var added = list[i] && list[i].addedNodes ? list[i].addedNodes : [];
                for (var j = 0; j < added.length; j++) {
                    patchStyleNodes(added[j]);
                }
            }
        });
        mo.observe(document.documentElement || document.head || document.body, { childList: true, subtree: true });
        setTimeout(function () {
            try {
                mo.disconnect();
                mark('cssom-observer', 'disconnected');
            } catch (e) {}
        }, 10000);
    } catch (e) {}

    if (document.addEventListener) {
        document.addEventListener('DOMContentLoaded', scheduleSheets, { once: true });
        window.addEventListener('load', function () {
            scheduleSheets();
            setTimeout(scheduleSheets, 1200);
        }, { once: true });
    } else if (window.attachEvent) {
        window.attachEvent('onload', scheduleSheets);
    }
}());
