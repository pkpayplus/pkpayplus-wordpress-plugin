(function ($) {
    'use strict';

    var settings = window.pkplus_classic_settings || {};
    var frame = null;
    var session = null;
    var paymentComplete = false;
    var submitting = false;
    var creating = false;

    // The 3DS popup returns to the classic checkout URL. Relay the provider
    // result back to the iframe that opened this popup, then close this tab.
    var returnParams = new URLSearchParams(window.location.search || '');
    if (returnParams.get('pkplus_3ds_return') === '1' && window.opener && !window.opener.closed) {
        window.opener.postMessage({
            event: 'pkplus_3ds_returned',
            session_id: returnParams.get('session_id') || null,
            sdk_on_validate_jwt: returnParams.get('sdk_on_validate_jwt') || returnParams.get('JWT') || '',
            authorization: returnParams.get('authorization') || '',
            request_id: returnParams.get('request_id') || '',
            tracker: returnParams.get('tracker') || '',
            status: returnParams.get('status') || ''
        }, '*');
        window.setTimeout(function () { window.close(); }, 300);
        return;
    }

    function status(message, error) {
        var node = $('#pkplus-classic-checkout-status');
        node.text(message || '').toggleClass('pkplus-error', !!error);
    }

    function loadFrame(data) {
        session = data;
        var src = (data.iframe_url || settings.iframe_url) + '#session_id=' + encodeURIComponent(data.session_id)
            + '&publishable_key=' + encodeURIComponent(data.publishable_key || settings.publishable_key || '')
            + '&three_ds_return_url=' + encodeURIComponent(data.three_ds_return_url || settings.three_ds_return_url || '')
            + '&classic=1';
        frame = $('<iframe>', {
            id: 'pkplus-classic-checkout-frame',
            src: src,
            title: 'PKPay+ secure checkout',
            style: 'width:100%;height:310px;min-height:310px;border:0;display:block;'
        });
        $('#pkplus-classic-checkout-frame-wrap').empty().append(frame);
        frame.on('load', function () {
            sendCustomerData();
        });
    }

    function fieldValue(name) {
        return String($('[name="' + name + '"]').first().val() || '');
    }

    function sendCustomerData() {
        if (!frame || !frame[0] || !frame[0].contentWindow) return;
        var firstName = fieldValue('billing_first_name');
        var lastName = fieldValue('billing_last_name');
        frame[0].contentWindow.postMessage({
            type: 'pkplus_customer_update',
            data: {
                name: (firstName + ' ' + lastName).trim(),
                email: fieldValue('billing_email'),
                phone: fieldValue('billing_phone'),
                country: fieldValue('billing_country'),
                city: fieldValue('billing_city'),
                state: fieldValue('billing_state'),
                address: fieldValue('billing_address_1'),
                postal_code: fieldValue('billing_postcode')
            }
        }, '*');
    }

    function createSession() {
        if (creating) return;
        creating = true;
        status('Loading secure checkout…');
        $.ajax({
            url: settings.ajax_url,
            method: 'POST',
            data: { _wpnonce: settings.nonce, checkout_flow: 'iframe' },
            dataType: 'json'
        }).done(function (response) {
            if (!response.success || !response.data || !response.data.session_id) {
                status((response.data && response.data.message) || 'Unable to start secure checkout.', true);
                return;
            }
            loadFrame(response.data);
            status('');
        }).fail(function () {
            status('Unable to start secure checkout. Please try again.', true);
        }).always(function () {
            creating = false;
        });
    }

    function ensureFrame() {
        if (!$('#pkplus-classic-checkout-container').length) return;
        if (session && session.session_id) {
            loadFrame(session);
        } else {
            createSession();
        }
    }

    function submitWooCommerceForm() {
        if (submitting) return;
        submitting = true;
        $('#pkplus_classic_session_id').val(session && session.session_id ? session.session_id : '');
        $('form.checkout').trigger('submit');
    }

    $(function () {
        if (!$('#pkplus-classic-checkout-container').length) return;
        createSession();
        $(document.body).on('input change', 'form.checkout input, form.checkout select, form.checkout textarea', sendCustomerData);
        $(document.body).on('updated_checkout', function () {
            // WooCommerce replaces the payment-method markup after country,
            // state, shipping, or tax changes. Reattach the iframe to the new
            // container instead of losing the checkout UI.
            window.setTimeout(ensureFrame, 0);
        });

        window.addEventListener('message', function (event) {
            var data = event.data || {};
            if (data.event === 'paymentSuccess') {
                paymentComplete = true;
                session = session || {};
                session.session_id = data.data && data.data.session_id || session.session_id;
                $('#pkplus_classic_session_id').val(session.session_id || '');
                status('Payment verified. Submitting order…');
                submitWooCommerceForm();
            } else if (data.event === 'paymentError') {
                paymentComplete = false;
                submitting = false;
                status(data.message || 'Payment failed. Please try again.', true);
            }
        });

        $('form.checkout').on('click', '#place_order', function (event) {
            if (paymentComplete || submitting) return;
            if (!frame || !frame[0] || !frame[0].contentWindow) {
                event.preventDefault();
                status('Secure checkout is still loading. Please wait.', true);
                return;
            }
            event.preventDefault();
            frame[0].contentWindow.postMessage({ event: 'pkplus_place_order' }, '*');
            status('Processing payment…');
        });
    });
}(jQuery));
