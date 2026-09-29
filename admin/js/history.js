/* AI Assets Scanner — Scan History page client-side.
 * Handles Export to ZIP (redirect-download) and Delete all history (AJAX).
 */
(function ($) {
    $(function () {
        var $export = $('#cu-history-export');
        var $delete = $('#cu-history-delete');

        $export.on('click', function (e) {
            e.preventDefault();
            var url = drspeedAiasHistory.ajaxUrl
                + '?action=drspeed_aias_export_history'
                + '&nonce=' + encodeURIComponent(drspeedAiasHistory.nonce);
            $export.prop('disabled', true);
            window.location.href = url;
            setTimeout(function () { $export.prop('disabled', false); }, 2000);
        });

        $delete.on('click', function (e) {
            e.preventDefault();
            if (!window.confirm(drspeedAiasHistory.deleteWarning)) {
                return;
            }
            $delete.prop('disabled', true);
            $.post(drspeedAiasHistory.ajaxUrl, {
                action: 'drspeed_aias_delete_history',
                nonce:  drspeedAiasHistory.nonce
            }).done(function () {
                window.location.reload();
            }).fail(function (xhr) {
                var msg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message)
                    || 'Failed to delete history.';
                window.alert(msg);
                $delete.prop('disabled', false);
            });
        });
    });
})(jQuery);
