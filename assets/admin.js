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
                if (r.data.image_prompt && !$('#acv-img-prompt').val()) {
                    $('#acv-img-prompt').val(r.data.image_prompt);
                }
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

        // ---- Tạo ảnh đại diện ----
        $('#acv-gen-image').on('click', function () {
            var prompt = $.trim($('#acv-img-prompt').val());
            if (!prompt) { $('#acv-img-status').html('<span class="acv-warn">Nhập prompt hoặc tạo nội dung trước.</span>'); return; }
            busy(true);
            $('#acv-img-status').html('Đang tạo ảnh… (10–30 giây)');
            $('#acv-img-result').empty();
            $.post(ajaxurl, {
                action: 'acv_gen_image', post: postId, _n: ACV.nonce, prompt: prompt
            }).done(function (r) {
                busy(false);
                if (!r.success) { $('#acv-img-status').html('<span class="acv-warn">Lỗi: ' + r.data + '</span>'); return; }
                $('#acv-img-status').html('<span class="acv-ok">✓ Đã đặt làm ảnh đại diện (~$' + r.data.cost + '). Lưu/tải lại bài để thấy.</span>');
                $('#acv-img-result').html('<img src="' + r.data.url + '?t=' + Date.now() + '" style="max-width:100%;height:auto;border:1px solid #dcdcde;margin-top:6px">');
            }).fail(function () { busy(false); $('#acv-img-status').html('<span class="acv-warn">Lỗi kết nối.</span>'); });
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

    // ---- Test kết nối nguồn tạo ảnh (fal.ai / OpenAI) ----
    $('#acv-test-image').on('click', function () {
        var $r = $('#acv-test-image-result').text('Đang kiểm tra…');
        var provider = $('select[name="acv_settings[image_provider]"]').val();
        var key = (provider === 'openai')
            ? $('input[name="acv_settings[openai_api_key]"]').val()
            : $('input[name="acv_settings[fal_api_key]"]').val();
        $.post(ajaxurl, {
            action: 'acv_test_image', _n: ACV.nonce,
            provider: provider, key: key,
            model: $('select[name="acv_settings[fal_model]"]').val(),
            endpoint: $('input[name="acv_settings[fal_endpoint]"]').val()
        }).done(function (res) {
            $r.html(res.success ? '<span class="acv-ok">✓ ' + res.data + '</span>' : '<span class="acv-warn">✗ ' + res.data + '</span>');
        }).fail(function () { $r.html('<span class="acv-warn">✗ Lỗi kết nối.</span>'); });
    });
});
