(function ($) {
    'use strict';

    var pendingKey = 'ultracachePendingVariationSubmit';

    function hasCompleteAttributeSelection($form) {
        var hasAttributes = false;
        var complete = true;

        $form.find('select[name^="attribute_"]').each(function () {
            hasAttributes = true;
            if ('' === String($(this).val() || '')) {
                complete = false;
                return false;
            }
        });

        return hasAttributes && complete;
    }

    function variationId($form) {
        return parseInt($form.find('input.variation_id').first().val(), 10) || 0;
    }

    function setPendingState($form, pending) {
        var $button = $form.find('.single_add_to_cart_button').first();
        if (pending) {
            $form.data(pendingKey, true);
            $button.prop('disabled', true).attr('aria-busy', 'true');
            return;
        }

        $form.removeData(pendingKey);
        $button.prop('disabled', false).removeAttr('aria-busy');
    }

    $(document).on('submit.ultracacheVariationGuard', 'form.variations_form', function (event) {
        var $form = $(this);

        if (variationId($form) > 0 || !hasCompleteAttributeSelection($form)) {
            return;
        }

        event.preventDefault();
        event.stopImmediatePropagation();
        setPendingState($form, true);
    });

    $(document).on('found_variation.ultracacheVariationGuard', 'form.variations_form', function () {
        var $form = $(this);
        if (!$form.data(pendingKey) || variationId($form) <= 0) {
            return;
        }

        var form = $form.get(0);
        var button = $form.find('.single_add_to_cart_button').get(0);
        setPendingState($form, false);

        if (form && 'function' === typeof form.requestSubmit) {
            form.requestSubmit(button || undefined);
            return;
        }

        $form.trigger('submit');
    });

    $(document).on('reset_data.ultracacheVariationGuard hide_variation.ultracacheVariationGuard', 'form.variations_form', function () {
        if ($(this).data(pendingKey)) {
            setPendingState($(this), false);
        }
    });
}(jQuery));
