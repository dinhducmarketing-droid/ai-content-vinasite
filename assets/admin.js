/* AI Content Vinasite — admin */
jQuery(function ($) {

    // ---- Meta box trên trang sửa ----
    var $box = $('#acv-metabox');
    if ($box.length) {
        var postId = $box.data('post');
        function busy(b) { $box.find('button').prop('disabled', b); }
        function status(t) { $('#acv-status').html(t); }

        $('#acv-generate').on('click', function () {
            busy(true); status('Đang tạo nội dung… (vài giây)');
            $('#acv-preview').hide(); $('#acv-meta').empty(); $('#acv-apply').hide();
            $.post(ajaxurl, {
                action: 'acv_preview', post: postId, _n: ACV.nonce, model: $('#acv-model').val()
            }).done(function (r) {
                busy(false);
                if (!r.success) { status('<span class="acv-warn">Lỗi: ' + r.data + '</span>'); return; }
                $('#acv-meta').html(r.data.meta);
                $('#acv-preview').html(r.data.preview).show();
                $('#acv-apply').show();
                status('Đã tạo. Xem trước rồi bấm <strong>Áp dụng</strong>.');
            }).fail(function () { busy(false); status('<span class="acv-warn">Lỗi kết nối.</span>'); });
        });

        $('#acv-apply').on('click', function () {
            busy(true); status('Đang áp dụng…');
            var fields = $('input.acv-field:checked').map(function () { return this.value; }).get();
            $.post(ajaxurl, {
                action: 'acv_apply', post: postId, _n: ACV.nonce, fields: fields
            }).done(function (r) {
                busy(false);
                status(r.success ? '<span class="acv-ok">' + r.data + '</span>' : '<span class="acv-warn">Lỗi: ' + r.data + '</span>');
                if (r.success) $('#acv-revert').show();
            }).fail(function () { busy(false); status('<span class="acv-warn">Lỗi kết nối.</span>'); });
        });

        $('#acv-revert').on('click', function () {
            if (!confirm('Hoàn tác về nội dung trước đó?')) return;
            busy(true); status('Đang hoàn tác…');
            $.post(ajaxurl, { action: 'acv_revert', post: postId, _n: ACV.nonce })
                .done(function (r) {
                    busy(false);
                    status(r.success ? '<span class="acv-ok">' + r.data + ' — tải lại trang.</span>' : '<span class="acv-warn">Lỗi: ' + r.data + '</span>');
                }).fail(function () { busy(false); status('<span class="acv-warn">Lỗi kết nối.</span>'); });
        });
    }

    // ---- Test key ở trang Settings ----
    $('#acv-test-key').on('click', function () {
        var $r = $('#acv-test-result').text('Đang kiểm tra…');
        var key = $('input[name="acv_settings[api_key]"]').val();
        $.post(ajaxurl, { action: 'acv_test_key', _n: ACV.nonce, key: key })
            .done(function (res) {
                $r.html(res.success ? '<span class="acv-ok">✓ ' + res.data + '</span>' : '<span class="acv-warn">✗ ' + res.data + '</span>');
            }).fail(function () { $r.html('<span class="acv-warn">✗ Lỗi kết nối.</span>'); });
    });
});
