$(function () {
    var $table = $('#trip-split');
    var $body = $table.find('tbody');
    var $message = $('#split-message');

    $.getJSON('api/trip_split.php', { trip_id: $table.data('trip-id') })
        .done(function (data) {
            var totalCents = 0;
            data.clients.forEach(function (client) {
                var $row = $('<tr>').append($('<td>').text(client.name));
                [
                    client.cargo_count,
                    num(client.weight_kg),
                    client.volume_m3 === null ? '—' : client.volume_m3.toLocaleString('ru-RU', { maximumFractionDigits: 3 }),
                    num(client.share * 100),
                    money(client.amount)
                ].forEach(function (value) {
                    $row.append($('<td class="r">').text(value));
                });
                $body.append($row);
                totalCents += Number(client.amount.replace('.', ''));
            });
            if (data.clients.length === 0) {
                $body.append($('<tr>').append($('<td colspan="6" class="empty">').text('Грузов нет')));
            }
            $('#split-total').text(money(totalCents / 100));
            $('#split-cost').text(money(data.cost));
            $('#split-basis').text(data.basis === 'weight'
                ? 'По весу: у части грузов нет объёма.'
                : 'По объёму.').prop('hidden', data.clients.length === 0);
            $message.prop('hidden', true);
            $table.prop('hidden', false);
        })
        .fail(function (xhr) {
            $message.text(xhr.responseJSON?.error || 'Не удалось загрузить распределение стоимости.');
        });
});
