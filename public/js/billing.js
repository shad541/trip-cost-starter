$(function () {
    var $period = $('#billing-period');
    var $preview = $('#billing-preview');
    var $apply = $('#billing-apply');
    var $table = $('#billing-results');
    var $body = $table.find('tbody');
    var $message = $('#billing-message');
    var actionLabels = { created: 'Создан', updated: 'Обновлён', unchanged: 'Без изменений' };
    var previewPeriod = null;

    function render(result) {
        var applied = result.created !== undefined;
        $('#billing-action-heading, #billing-action-total').prop('hidden', !applied);
        $body.empty();
        result.clients.forEach(function (client) {
            var $row = $('<tr>').append($('<td>').text(client.name));
            [client.before, client.after, client.diff].forEach(function (amount) {
                $row.append($('<td class="r">').text(money(amount)));
            });
            if (applied) {
                $row.append($('<td>').text(actionLabels[client.action]));
            }
            $body.append($row);
        });
        if (result.clients.length === 0) {
            $body.append($('<tr>').append($('<td class="empty">').attr('colspan', applied ? 5 : 4)
                .text('За этот месяц нет счетов и рейсов')));
        }
        $('#billing-total-before').text(money(result.total_before));
        $('#billing-total-after').text(money(result.total_after));
        $('#billing-total-diff').text(money(result.total_diff));
        $table.prop('hidden', false);
    }

    function busy(pending) {
        $period.prop('disabled', pending);
        $preview.prop('disabled', pending);
        $apply.prop('disabled', pending || previewPeriod !== $period.val());
    }

    $period.on('input change', function () {
        previewPeriod = null;
        $table.prop('hidden', true);
        $message.empty();
        $apply.prop('disabled', true);
    });

    $('#billing-form').on('submit', function (event) {
        event.preventDefault();
        var period = $period.val();
        previewPeriod = null;
        $table.prop('hidden', true);
        busy(true);
        $message.text('Загрузка предпросмотра…');

        $.getJSON('api/billing_preview.php', { period: period })
            .done(function (result) {
                render(result);
                previewPeriod = result.period;
                $message.empty();
            })
            .fail(function (xhr) {
                $message.text(xhr.responseJSON?.error || 'Не удалось загрузить предпросмотр.');
            })
            .always(function () { busy(false); });
    });

    $apply.on('click', function () {
        if (previewPeriod !== $period.val() || !window.confirm('Применить пересчёт счетов за ' + previewPeriod + '?')) {
            return;
        }
        busy(true);
        $message.text('Применение пересчёта…');

        $.post('api/billing_apply.php', { period: previewPeriod }, null, 'json')
            .done(function (result) {
                render(result);
                $message.text('Пересчёт за ' + result.period + ' применён. Создано: ' + result.created
                    + ', обновлено: ' + result.updated + ', без изменений: ' + result.unchanged + '.');
                previewPeriod = null;
            })
            .fail(function (xhr) {
                $message.text(xhr.responseJSON?.error || 'Не удалось применить пересчёт.');
            })
            .always(function () { busy(false); });
    });
});
