/**
 * PKPay+ Secured Checkout – using Cart Store + deep debug
 */

const { createElement, useEffect, useRef, useState } = window.wp.element;
const { useSelect } = window.wp.data;
const { registerPaymentMethod } = window.wc.wcBlocksRegistry;

console.log('🟦 Pkplus Block: initializing checkout.js');

const pkplus_settings = window.wc.wcSettings.getSetting(
  'pkplus_checkout_client_data',
  {}
);
console.log('🟩 Pkplus settings from PHP:', pkplus_settings);

const label = pkplus_settings.title || 'PKPay+ Secured Checkout';
const checkoutFlow = pkplus_settings.checkout_flow || 'iframe';
const createSessionNonce = pkplus_settings.create_session_nonce || '';
const threeDsReturnUrl = pkplus_settings.three_ds_return_url || '';
const diagnosticUrlTemplate = pkplus_settings.diagnostic_url || '';
const diagnosticAjaxUrl = pkplus_settings.diagnostic_ajax_url || '';
const diagnosticNonce = pkplus_settings.create_session_nonce || '';
let pkplusLastSessionId = null;
let pkplusLastReportedWooError = '';

function pkplusSendDiagnostic(sessionId, event, data = {}, level = 'info', message = '') {
  if (!sessionId || (!diagnosticUrlTemplate && !diagnosticAjaxUrl)) return;
  const directUrl = diagnosticUrlTemplate.replace('{session_id}', encodeURIComponent(sessionId));
  const url = diagnosticAjaxUrl
    ? `${diagnosticAjaxUrl}&_wpnonce=${encodeURIComponent(diagnosticNonce)}`
    : directUrl;
  fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      event,
      session_id: sessionId,
      level,
      message,
      data,
      url: window.location.href,
    }),
    keepalive: true,
  }).catch(() => {});
}

function pkplusTextFromError(value) {
  if (typeof value === 'string') {
    const node = document.createElement('div');
    node.innerHTML = value;
    return (node.textContent || node.innerText || value).replace(/\s+/g, ' ').trim();
  }
  if (value && typeof value === 'object') {
    return value.message || value.error || value.code || JSON.stringify(value);
  }
  return String(value || '').trim();
}

function pkplusReportWooError(message, data = {}) {
  const text = pkplusTextFromError(message);
  const sessionId = pkplusLastSessionId || data.session_id || null;
  if (!sessionId || !text) return;

  const dedupeKey = `${sessionId}:${text}`;
  if (dedupeKey === pkplusLastReportedWooError) return;
  pkplusLastReportedWooError = dedupeKey;
  pkplusSendDiagnostic(sessionId, 'woocommerce.checkout_error', {
    message: text,
    ...data,
    checkout_url: window.location.href,
    user_agent: navigator.userAgent,
  }, 'error', text);
}

window.addEventListener('error', (event) => {
  pkplusReportWooError(event.message || 'Checkout browser error', {
    source: 'window.error',
    filename: event.filename || '',
    line: event.lineno || null,
    column: event.colno || null,
  });
});

window.addEventListener('unhandledrejection', (event) => {
  pkplusReportWooError(event.reason?.message || event.reason || 'Checkout promise rejected', {
    source: 'window.unhandledrejection',
  });
});

function pkplusWatchWooErrorNotices() {
  if (!document.body || !window.MutationObserver) return;
  const observer = new MutationObserver(() => {
    document.querySelectorAll(
      '.wc-block-components-notice-banner.is-error, .woocommerce-error, .woocommerce-NoticeGroup-checkout'
    ).forEach((notice) => {
      const text = pkplusTextFromError(notice.innerHTML);
      if (text) pkplusReportWooError(text, { source: 'visible_woocommerce_notice' });
    });
  });
  observer.observe(document.body, { childList: true, subtree: true });
}

if (document.body) {
  pkplusWatchWooErrorNotices();
} else {
  document.addEventListener('DOMContentLoaded', pkplusWatchWooErrorNotices, { once: true });
}

if ( new URLSearchParams( window.location.search ).get( 'pkplus_3ds_return' ) === '1' ) {
  const params = new URLSearchParams( window.location.search );
  const message = {
    event: 'pkplus_3ds_returned',
    session_id: params.get( 'session_id' ) || null,
    sdk_on_validate_jwt: params.get( 'sdk_on_validate_jwt' ) || params.get( 'JWT' ) || '',
    status: params.get( 'status' ) || '',
  };
  pkplusSendDiagnostic(message.session_id, '3ds.returned', {
    status: message.status,
    has_validation_jwt: Boolean(message.sdk_on_validate_jwt),
  });
  if ( window.opener && !window.opener.closed ) {
    window.opener.postMessage( message, '*' );
    window.setTimeout( () => window.close(), 300 );
  }
}

const PkplusPaymentContent = ({ eventRegistration, emitResponse }) => {
  const iframeRef = useRef(null);
  const [iframeLoaded, setIframeLoaded] = useState(false);
  const [iframeSrc, setIframeSrc] = useState(null);
  const [sessionId, setSessionId] = useState(null);
  const [isCreatingSession, setIsCreatingSession] = useState(false);

  // ✅ Use Cart Store → getCartData()
  const billing = useSelect((select) => {
    try {
      // Prefer the official descriptor if present
      let store;
      if (window.wc && window.wc.wcBlocksData && window.wc.wcBlocksData.cartStore) {
        store = select(window.wc.wcBlocksData.cartStore);
      } else {
        // Fallback to string key
        store = select('wc/store/cart');
      }

      if (!store || !store.getCartData) {
        console.warn('⚠️ Cart store or getCartData not available:', store);
        return {};
      }

      const cartData = store.getCartData();
      console.log('🛒 Cart data from store:', cartData);

      const billingAddress = cartData?.billingAddress || {};
      console.log('👤 Billing address from cartData:', billingAddress);

      return billingAddress;
    } catch (err) {
      console.error('❌ Failed to read cart billingAddress:', err);
      return {};
    }
  }, []);

  // ✅ Load iframe once with session + publishable key
  useEffect(() => {
    if (!iframeRef.current) return;
    if (iframeLoaded) return;
    if (!iframeSrc) return;

    iframeRef.current.src = iframeSrc;

    iframeRef.current.addEventListener("load", () => {
      console.log("✅ Iframe loaded");
      pkplusSendDiagnostic(sessionId, 'iframe.loaded', {
        iframe_url: iframeSrc,
        cross_origin: true,
      });
      setIframeLoaded(true);
    });
  }, [iframeRef.current, iframeLoaded, iframeSrc, sessionId]);

  const [isPkplusActive, setIsPkplusActive] = useState(false);
  const [isPkplusCardActive, setIsPkplusCardReady] = useState(false);

  // ✅ After iframe is loaded, send *only* customer info (no totals)
  const [isPkplusReady, setIsPkplusReady] = useState(false);
  useEffect(() => {
    const handlePkplusReadyMessage = (event) => {
      const { event: type, ready } = event.data || {};
      console.log('handleMessage', event);
      if (type === "pkplusPaymentIsReady") {

        setIsPkplusReady(true);
      }
    };

    window.addEventListener("message", handlePkplusReadyMessage);
    return () => window.removeEventListener("message", handlePkplusReadyMessage);
  }, []);

  useEffect(() => {
    if (!iframeLoaded || !iframeRef.current?.contentWindow) {
      console.log('⏳ Waiting for iframe to load before sending customer data...');
      return;
    }

    const data = {
      name: [billing?.first_name, billing?.last_name].filter(Boolean).join(' ') || '',
      email: billing?.email ?? '',
      phone: billing?.phone ?? '',
      country: billing?.country ?? '',
      city: billing?.city ?? '',
      state: billing?.state ?? '',
      address: billing?.address_1 ?? '',
      postal_code: billing?.postcode ?? '',
    };

    console.log('📤 Sending customer data to iframe:', data);
    iframeRef.current.contentWindow.postMessage(
      { type: 'pkplus_customer_update', data },
      '*'
    );
  }, [billing, iframeLoaded, iframeSrc, isPkplusReady]);

  const createPkplusSession = async (flow = checkoutFlow) => {
    if (isCreatingSession) return null;

    try {
      setIsCreatingSession(true);
      const sessionUrl = `/?wc-ajax=pkplusCreateSession&_wpnonce=${encodeURIComponent(createSessionNonce)}`;
      const response = await fetch(sessionUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          checkout_flow: flow,
        }),
      });

      const result = await response.json();
      if (!result?.success || !result?.data?.session_id) {
        console.error('❌ Failed to create Pkplus session', result);
        return null;
      }

      setSessionId(result.data.session_id);
      pkplusLastSessionId = result.data.session_id;
      pkplusSendDiagnostic(result.data.session_id, 'session.received', {
        checkout_flow: flow,
        has_iframe_url: Boolean(result.data.iframe_url),
      });
      return result.data;
    } catch (err) {
      console.error('⚠️ Pkplus session creation error:', err);
      return null;
    } finally {
      setIsCreatingSession(false);
    }
  };

  useEffect(() => {
    const radios = document.querySelectorAll(
      'input[name="radio-control-wc-payment-method-options"]'
    );

    const updateState = () => {
      const pkplusRadio = document.querySelector(
        '#radio-control-wc-payment-method-options-pkplus_checkout_client'
      );
      setIsPkplusActive(pkplusRadio?.checked || false);
    };

    radios.forEach((r) => r.addEventListener("change", updateState));
    updateState(); // run on mount

    return () => {
      radios.forEach((r) => r.removeEventListener("change", updateState));
    };
  }, []);

  // 🔹 2. Handle messages from iframe about card readiness
  useEffect(() => {
    const handleMessage = (event) => {
      const { event: type, ready } = event.data || {};
      console.log('handleMessage', event);
      if (type !== "pkplus_card_ready" || !isPkplusActive) {
        return
      }
      setIsPkplusCardReady(ready);

      const button = document.querySelector(
        ".wc-block-components-checkout-place-order-button"
      );
      if (!button) return;


      /*if (ready) {
        console.log("✅ Card ready → enabling button");
        button.disabled = false;
        button.classList.remove("pkplus-disabled");
        button.style.opacity = "1";
        button.style.pointerEvents = "auto";
        button.style.cursor = "";
      } else {
        console.log("⛔ Card NOT ready → disabling button");
        button.disabled = true;
        button.classList.add("pkplus-disabled");
        button.style.opacity = "0.5";
        button.style.pointerEvents = "none";
        button.style.cursor = "not-allowed";
      }*/
    };

    window.addEventListener("message", handleMessage);
    return () => window.removeEventListener("message", handleMessage);
  }, [isPkplusActive]);

  useEffect(() => {
    if (!eventRegistration || !emitResponse || !isPkplusActive) return;

    const { onPaymentSetup } = eventRegistration;

    const unsubscribe = onPaymentSetup(() => {

      // 🛑 1. VALIDATE WC CHECKOUT FIELDS
      const validationStore = wp.data.select("wc/store/validation");
      const hasErrors = validationStore?.hasValidationErrors();

      if (hasErrors) {
        console.log("❌ WC validation failed");
        return {
          type: emitResponse.responseTypes.ERROR,
          message: "Please fill all required checkout fields.",
        };
      }

      if (checkoutFlow === 'redirect') {
        return {
          type: emitResponse.responseTypes.SUCCESS,
          meta: {
            paymentMethodData: {
              pkplus_checkout_flow: 'redirect',
            },
          },
        };
      }

      // 🛑 2. VALIDATE PKPLUS CARD READINESS
      if (!isPkplusCardActive) {
        console.log("⛔ Pkplus card NOT ready → block payment");
        return {
          type: emitResponse.responseTypes.ERROR,
          message: "Your card information is incomplete.",
        };
      }

      // 🟢 3. TRIGGER PKPLUS IFRAME PAYMENT
      const iframe = document.getElementById('pkplus-secured-frame');
      if (iframe?.contentWindow) {
        iframe.contentWindow.postMessage({ event: 'pkplus_place_order' }, '*');
      }

      // 🟢 4. WAIT FOR RESULT FROM IFRAME
      return new Promise((resolve) => {
        const handle = (event) => {
          const { event: type, data, message } = event.data || {};
          if (!type) return;

          console.log(type, data);
          pkplusLastSessionId = sessionId || data?.session_id || pkplusLastSessionId;
          pkplusSendDiagnostic(pkplusLastSessionId, `iframe.${type}`, {
            data,
          }, type === 'paymentError' ? 'error' : 'info', message || '');

          if (type === 'paymentSuccess') {
            window.removeEventListener('message', handle);
            resolve({
              type: emitResponse.responseTypes.SUCCESS,
              meta: {
                paymentMethodData: {
                  pkplus_session_id: data.session_id || null,
                },
              },
            });
          }
          else if (type === 'paymentError') {
            console.error('❌ Pkplus iframe reports error', message);
            window.removeEventListener('message', handle);
            resolve({
              type: emitResponse.responseTypes.ERROR,
              message: message || 'Payment failed. Please try again.',
            });
          }
        };

        window.addEventListener('message', handle);
      });
    });

    return () => unsubscribe?.();

  }, [eventRegistration, emitResponse, isPkplusActive, isPkplusReady, isPkplusCardActive, checkoutFlow, sessionId]);

  // 🔹 3. If user switches away from Pkplus, always re-enable the button
  useEffect(() => {
    const button = document.querySelector(
      ".wc-block-components-checkout-place-order-button"
    );
    if (button && !isPkplusActive) {
      button.disabled = false;
      button.classList.remove("pkplus-disabled");
    }
  }, [isPkplusActive]);

  useEffect(() => {
    if (checkoutFlow !== 'iframe') return;
    if (!isPkplusActive) return;
    if (iframeSrc) return;

    async function createSessionForIframe() {
      const sessionData = await createPkplusSession('iframe');
      if (!sessionData) return;

      const returnUrl = sessionData.three_ds_return_url || threeDsReturnUrl;
      const url = `${sessionData.iframe_url}#session_id=${sessionData.session_id}&publishable_key=${sessionData.publishable_key}&three_ds_return_url=${encodeURIComponent(returnUrl)}`;
      setIframeSrc(url);
    }

    createSessionForIframe();
  }, [checkoutFlow, isPkplusActive, iframeSrc]);

  if (checkoutFlow === 'redirect') {
    return createElement(
      'div',
      { className: 'pkplus-secured-checkout-redirect-block' },
      'You will be redirected to PKPay+ to complete payment after placing your order.'
    );
  }

  if (!iframeSrc) {
    return createElement(
      'div',
      { className: 'pkplus-secured-checkout-waiting-block' },
      createElement(
        'div',
        {
          id: 'pkplus-secured-waiting',
          style: {
            width: '100%',
            height: '250px',
            border: '0',
            borderRadius: '8px',
            background: '#fff',
            marginTop: '10px',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            fontSize: '16px',
            color: '#555',
          },
          allow: 'payment',
        },
        'Loading Pkplus Checkout...'
      )
    );
  }

  return createElement(
    'div',
    { className: 'pkplus-secured-checkout-block' },
    createElement('iframe', {
      ref: iframeRef,
      id: 'pkplus-secured-frame',
      style: {
        width: '100%',
        height: '250px',
        border: '0',
        borderRadius: '8px',
        background: '#fff',
        marginTop: '10px',
      },
      allow: 'payment',
    })
  );
};

registerPaymentMethod({
  name: 'pkplus_checkout_client',
  label,
  content: createElement(PkplusPaymentContent, null),
  edit: createElement(PkplusPaymentContent, null),
  canMakePayment: () => true,
  ariaLabel: 'PKPay+ Secured Checkout',
  supports: { features: ['products'] },
});

console.log('✅ Pkplus Block registered successfully');
