jQuery(function ($) {
    'use strict';

    $('.wcdp-import-button').on('click', function () {
        var $button = $(this), $box = $button.closest('.wcdp-import');
        var $status = $box.find('.wcdp-import-status');
        var file = $box.find('input[type="file"]')[0].files[0];
        function error(message) { $status.addClass('is-error').text(message); }
        if (!file) { error('Choose a CSV file first.'); return; }
        if (!/\.csv$/i.test(file.name)) { error('Choose a .csv file using the sample format.'); return; }
        if (file.size > 5 * 1024 * 1024) { error('Choose a CSV file smaller than 5 MB.'); return; }
        $button.prop('disabled', true);
        $status.removeClass('is-error').text('Reading CSV…');
        var reader = new FileReader();
        reader.onload = function () {
            try {
                var imported = wcdpParseProductCSV(reader.result);
                var group = $box.data('group');
                var $ids = $('[name="' + group + '_product_ids"]');
                var $skus = $('[name="' + group + '_product_skus"]');
                var ids = new Set($ids.val().split(/[\s,]+/).filter(Boolean).map(function (id) { return id.replace(/^0+(?=\d)/, ''); }));
                var skus = new Set($skus.val().split(/\r\n|\r|\n/).map(function (sku) { return sku.trim(); }).filter(Boolean));
                var oldIds = ids.size, oldSkus = skus.size;
                imported.ids.forEach(function (id) { ids.add(id); });
                imported.skus.forEach(function (sku) { skus.add(sku); });
                $ids.val(Array.from(ids).join(', ')).trigger('change');
                $skus.val(Array.from(skus).join('\n')).trigger('change');
                $status.removeClass('is-error').text('Added ' + (ids.size - oldIds) + ' IDs and ' + (skus.size - oldSkus) + ' SKUs. Duplicates were skipped. Review the fields, then save or run.');
            } catch (e) { error(e.message + ' No values were imported.'); }
        };
        reader.onerror = function () { error('The CSV could not be read. No values were imported.'); };
        reader.onloadend = function () { $button.prop('disabled', false); };
        reader.readAsText(file);
    });

    var results = document.querySelector('.wcdp-results');
    if (results) {
        results.scrollIntoView({ block: 'start' });
    }

    if ($.fn.selectWoo) {
        $('.wcdp-term-select').each(function () {
            var $select = $(this);
            $select.selectWoo({
                width: '100%',
                placeholder: $select.data('placeholder'),
                closeOnSelect: false
            });

            // Give the enhanced control the same label and help as the native select.
            $select.next('.select2-container').find('.select2-selection, .select2-search__field')
                .attr('aria-label', $('label[for="' + this.id + '"]').text())
                .attr('aria-describedby', $select.attr('aria-describedby'));
        });
    }

    var $schedule = $('[name="schedule_enabled"]');
    function updateSchedule() {
        $('[name="schedule_start_date"], [name="schedule_end_date"]').prop('disabled', !$schedule.prop('checked'));
    }
    $schedule.on('change', updateSchedule);
    updateSchedule();

    var $all = $('[name="apply_to_all"]');
    function updateIncludeHelp() {
        $('#wcdp-include-help').text($all.prop('checked')
            ? 'These rules are ignored while Start from all products is checked.'
            : 'Choose at least one rule; otherwise no products are selected. IDs and SKUs form one combined list. Products must also match every filled taxonomy field, with any selected term matching within each field.');
    }
    $all.on('change', updateIncludeHelp);
    updateIncludeHelp();

    var $type = $('[name="discount_type"]');
    function updateDiscountHelp() {
        var fixed = $type.val() === 'fixed';
        $('#wcdp-discount-value-label').text(fixed ? 'Discount value (fixed amount)' : 'Discount value (%)');
        $('#wcdp-discount-value-help').text(fixed
            ? 'Amount off the regular price in store currency, e.g. 10 means 10 currency units off.'
            : 'Percentage off the regular price, e.g. 20 means 20% off.');
    }
    $type.on('change', updateDiscountHelp);
    updateDiscountHelp();
});
