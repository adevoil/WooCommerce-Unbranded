<?php
/**
 * Gateway class
 */
class WC_Payment_Network extends WC_Payment_Gateway {

    const MMS_URL                  = 'https://example.domain.com';
    const DEFAULT_HOSTED_URL       = 'https://example.domain.com/hosted/';
    const DEFAULT_HOSTED_MODAL_URL = 'https://example.domain.com/hosted/modal/';
    const DEFAULT_DIRECT_URL       = 'https://example.domain.com/direct/';
    const DEFAULT_MERCHANT_ID      = '100001';
    const DEFAULT_SECRET           = 'Circle4Take40Idea';

    private $gateway     = 'PaymentNetwork';
    public  $gateway_url = '';

    public static $lang;

    public function __construct() {

        $id = str_replace(' ', '', strtolower($this->gateway));

        // Language translation module to use
        self::$lang = strtolower('woocommerce_' . $id);

        $this->has_fields          = false;
        $this->id                  = $id;
        $this->icon                = plugins_url('/', dirname(__FILE__)) . 'img/logo.png';
        $this->method_title        = __(ucwords($this->gateway), self::$lang);
        $this->method_description  = __(ucwords($this->gateway) . ' hosted works by sending the user to ' . ucwords($this->gateway) . ' to enter their payment infomation', self::$lang);
        $this->supports = array (
            'subscriptions',
            'products',
            'subscription_cancellation',
            'subscription_suspension',
            'subscription_reactivation',
            'subscription_amount_changes',
            'subscription_date_changes',
            'subscription_payment_method_change'
        );
        $this->init_form_fields();

        $this->init_settings();

        // Get setting values
        $this->enabled             = isset($this->settings['enabled']) ? $this->settings['enabled'] : 'no';
        $this->title               = isset($this->settings['title']) ? $this->settings['title'] : 'Credit Card via ' . strtoupper($this->gateway);
        $this->description         = isset($this->settings['description']) ? $this->settings['description'] : 'Pay via Credit / Debit Card with ' . strtoupper($this->gateway) . ' secure card processing.';
        $this->gateway             = isset($this->settings['gateway']) ? $this->settings['gateway'] : $this->gateway;

        // Custom forms
        $this->gateway_url = $this->settings['gatewayURL'];

        if (!empty($this->gateway_url)) {
            // Prevent insecure requests
            $this->gateway_url = str_ireplace('http://', 'https://', $this->gateway_url);

            // Always append end slash
            if (preg_match('/(\.php|\/)$/', $this->gateway_url) == false) {
                $this->gateway_url .= '/';
            }

            switch ($this->settings['type']) {
                case 'direct_v2':
                case 'direct':
                    $this->gateway_url .= 'direct/';
                    break;
                case 'hosted_v3':
                    $this->gateway_url .= 'hosted/modal/';
                    break;
                case 'hosted_v2':
                case 'hosted':
                default:
                    $this->gateway_url .= 'hosted/';
            }
        } else {
            switch ($this->settings['type']) {
                case 'direct_v2':
                case 'direct':
                    $this->gateway_url = self::DEFAULT_DIRECT_URL;
                    break;
                case 'hosted_v3':
                    $this->gateway_url = self::DEFAULT_HOSTED_MODAL_URL;
                    break;
                case 'hosted_v2':
                case 'hosted':
                default:
                    $this->gateway_url = self::DEFAULT_HOSTED_URL;
            }
        }

        // Hooks
        /* 1.6.6 */
        add_action('woocommerce_update_options_payment_gateways', array($this, 'process_admin_options'));

        /* 2.0.0 */
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

        add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));
        add_action('woocommerce_api_wc_' . $this->id, array($this, 'process_hosted_callback'));
        add_action('woocommerce_api_wc_' . $this->id . '_direct_callback', array($this, 'process_direct_callback'));

        /* 3.0.0 Subscriptions */
        add_action('woocommerce_scheduled_subscription_payment_'. $this->id, array( $this, 'process_scheduled_subscription_payment_callback'),10,3);
    }

    /**
     * Initialise Gateway Settings
     */
    function init_form_fields() {

        $this->form_fields = array(
            'enabled' => array(
                'title'       => __('Enable/Disable', self::$lang),
                'label'       => __('Enable ' . strtoupper($this->gateway), self::$lang),
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'no'
            ),
            'title' => array(
                'title'       => __('Title', self::$lang),
                'type'        => 'text',
                'description' => __('This controls the title which the user sees during checkout.', self::$lang),
                'default'     => __(strtoupper(ucwords($this->gateway)), self::$lang)
            ),
            'type' => array(
                'title'       => __('Type of integration', self::$lang),
                'type'        => 'select',
                'options' => array(
                    'hosted'     => 'Hosted',
                    'hosted_v2'  => 'Hosted (Embedded)',
                    'hosted_v3'  => 'Hosted (Modal)',
                    'direct'     => 'Direct',
                    'direct_v2'  => 'Direct 3-D Secure V2',
                ),
                'description' => __('This controls method of integration.', self::$lang),
                'default'     => 'hosted'
            ),
            'description' => array(
                'title'       => __('Description', self::$lang),
                'type'        => 'textarea',
                'description' => __('This controls the description which the user sees during checkout.', self::$lang),
                'default'     => 'Pay securely via Credit / Debit Card with ' . ucwords($this->gateway)
            ),
            'merchantID' => array(
                'title'       => __('Merchant ID', self::$lang),
                'type'        => 'text',
                'description' => __('Please enter your ' . ucwords($this->gateway) . ' merchant ID', self::$lang),
                'default'     => self::DEFAULT_MERCHANT_ID
            ),
            'signature' => array(
                'title'       => __('Signature Key', self::$lang),
                'type'        => 'text',
                'description' => __('Please enter the signature key for the merchant account. This can be changed in the <a href="' . self::MMS_URL . '" target="_blank">MMS</a>', self::$lang),
                'default'     => self::DEFAULT_SECRET
            ),
            'formResponsive' => array(
                'title'       => __('Responsive form', self::$lang),
                'type'        => 'select',
                'options' => array(
                    'Y'       => 'Yes',
                    'N'       => 'No'
                ),
                'description' => __('This controls whether the payment form is responsive.', self::$lang),
                'default'     => 'No'
            ),
            'gatewayURL' => array(
                'title'       => __('Gateway URL', self::$lang),
                'type'        => 'text',
                'description' => __('Allows the use of custom forms. Leave blank to use default', self::$lang)
            ),
            'countryCode' => array(
                'title'       => __('Country Code', self::$lang),
                'type'        => 'text',
                'description' => __('Please enter your 3 digit <a href="http://en.wikipedia.org/wiki/ISO_3166-1" target="_blank">ISO country code</a>', self::$lang),
                'default'     => '826'
            ),
            'customerWalletsEnabled' => array(
                'title'       => __('Customer wallets', self::$lang),
                'type'        => 'select',
                'options' => array(
                    'Y'       => 'Yes',
                    'N'       => 'No'
                ),
                'description' => __('This controls whether wallets is enabled for customers on the hosted form.', self::$lang),
                'default'     => 'No'
            ),
        );

    }

    /**
     * You will need it if you want your custom credit card form, Step 4 is about it
     */
    public function payment_fields() {

        // ok, let's display some description before the payment form
        if ( $this->description ) {
            // you can instructions for test mode, I mean test card numbers etc.
            echo wpautop( wp_kses_post( $this->description ) );
        }

        switch ($this->settings['type']) {
            case 'direct':
                echo $this->generate_direct_initial_request_form_v1();
                break;
            case 'direct_v2':
                echo $this->generate_direct_initial_request_form_v2();
                break;
            default;
        }
    }

    /**
     * @param $order_id
     * @return array
     */
    public function capture_order($order_id) {
        $order = new WC_Order($order_id);
	$amount = (int) (round($order->get_total(), 2) * 100);


        $billing_address  = $order->get_billing_address_1();
        $billing2 = $order->get_billing_address_2();

        if (!empty($billing2)) {
            $billing_address .= "\n" . $billing2;
        }
        $billing_address .= "\n" . $order->get_billing_city();
        $state = $order->get_billing_state();
        if (!empty($state)) {
            $billing_address .= "\n" . $state;
            unset($state);
        }
        $country = $order->get_billing_country();
        if (!empty($country)) {
            $billing_address .= "\n" . $country;
            unset($country);
        }

        // Fields for hash
        $req = array(
            'action'			  => 'SALE',
            'merchantID'          => $this->settings['merchantID'],
            'amount'              => $amount,
            'countryCode'         => $this->settings['countryCode'],
            'customerCountryCode' => $this->settings['countryCode'],
            'currencyCode'        => $order->get_currency(),
            'transactionUnique'   => uniqid($order->get_order_key() . "-"),
            'orderRef'            => $order_id,
            'customerName'        => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'customerAddress'     => $billing_address,
            'customerCounty'	  => $order->get_billing_state(),
            'customerTown'		  => $order->get_billing_city(),
            'customerPostCode'    => $order->get_billing_postcode(),
            'customerEmail'       => $order->get_billing_email(),
        );

        $phone = $order->get_billing_phone();
        if (!empty($phone)) {
            $req['customerPhone'] = $phone;
            unset($phone);
        }

        /**
         * Wallets
         */

        //If wallets is enabled in the plugin settings and their is a user logged in.
        if ($this->settings['customerWalletsEnabled'] === 'Y' && is_user_logged_in()) {
            //Try and find the users walletID in the wallets table.
            global $wpdb;
            $wallet_table_name = $wpdb->prefix . 'woocommerce_payment_network_wallets';

            //Query table. Select customer wallet where belongs to user id and current configured merchant.
            $customersWalletID = $wpdb->get_var(
                $wpdb->prepare( "SELECT wallets_id FROM {$wallet_table_name} WHERE users_id = %d AND merchants_id = %d LIMIT 1",
                    get_current_user_id(), $this->settings['merchantID']));

            //If the customer wallet record exists.
            if($customersWalletID > 0)
            {
                //Add walletID to request.
                $req['walletID'] = $customersWalletID;
                $req['walletEnabled'] = 'Y';
                $req['walletRequired'] = 'Y';

            } else {
                //Create a new wallet.
                $req['walletStore'] = 'Y';
                $req['customerAddressStore'] = 'Y';
                $req['deliveryAddressStore'] = 'Y';
                $req['walletEnabled'] = 'Y';
                $req['walletRequired'] = 'Y';
            }
        }

        return $req;
    }

    /**
     * Process the payment and return the result
     *
     * @param $order_id
     *
     * @return array
     */
    function process_payment($order_id) {
        $order = new WC_Order($order_id);

        if (in_array($this->settings['type'], ['hosted', 'hosted_v2', 'hosted_v3'], true)) {
            return array(
                'result'   => 'success',
                'redirect' => $order->get_checkout_payment_url(true)
            );
        }

        if ('direct_v2' === $this->settings['type']) {
            $args = array_merge(
                $this->capture_order($order_id),
                $_POST['browserInfo'],
                [
                    'type'                 => 1,
                    'cardNumber'           => $_POST['cardNumber'],
                    'cardExpiryMonth'      => $_POST['cardExpiryMonth'],
                    'cardExpiryYear'       => $_POST['cardExpiryYear'],
                    'cardCVV'              => $_POST['cardCVV'],
                    'threeDSVersion'       => 2,
                    'remoteAddress'        => $_SERVER['REMOTE_ADDR'],
                    'merchantCategoryCode' => 5411, // TODO: move to settings
                    'threeDSRedirectURL'   => add_query_arg(
                        [
                            'wc-api' => 'wc_'.$this->id.'_direct_callback',
                        ],
                        home_url('/')
                    ),
                ]
            );

            if (isset($this->settings['signature']) && !empty($this->settings['signature'])) {
                $args['signature'] = $this->create_signature($args, $this->settings['signature']);
            }

            $response = $this->post($args);

            setcookie('threeDSRef', $response['threeDSRef'], time()+315);

            try {
                if (!$response || !isset($response['responseCode'])) {
                    throw new RuntimeException('Invalid response from Payment Gateway');
                }

                if ($response['responseCode'] == 65802) {
                    return [
                        'result'   => 'success',
                        'redirect' => add_query_arg(
                            [
                                'ACSURL' => rawurlencode($response['threeDSURL']),
                                'threeDSRef' => rawurlencode($response['threeDSRef']),
                                'threeDSMethodData' => rawurlencode($response['threeDSRequest']['threeDSMethodData']),
                            ],
                            plugins_url('public/3d-secure-form-v2.php', dirname(__FILE__))
                        ),
                    ];
                } else {
                    return $this->process_response($response);
                }
            } catch (RuntimeException $exception) {
                wc_add_notice(  'Connection error.', 'error' );
                return [];
            }
        }

        if ('direct' === $this->settings['type']) {
            $args = array_merge($this->capture_order($order_id), [
                'type'              => 1,
                'cardNumber'        => $_POST['cardNumber'],
                'cardExpiryMonth'   => $_POST['cardExpiryMonth'],
                'cardExpiryYear'    => $_POST['cardExpiryYear'],
                'cardCVV'           => $_POST['cardCVV'],
                'threeDSOptions'    => $_POST['threeDSOptions'],
            ]);

            if (isset($this->settings['signature']) && !empty($this->settings['signature'])) {
                $args['signature'] = $this->create_signature($args, $this->settings['signature']);
            }

            $response = $this->post($args);

            try {
                if (!$response || !isset($response['responseCode'])) {
                    throw new RuntimeException('Invalid response from Payment Gateway');
                }

                if ($response['responseCode'] == 65802) {
                    $callback = add_query_arg(
                        [
                            'wc-api' => 'wc_' . $this->gateway . '_direct_callback',
                            'xref' => $response['xref'],
                        ],
                        home_url('/')
                    );

                    return [
                        'result'   => 'success',
                        'redirect' =>  add_query_arg(
                            [
                                'ACSURL' => rawurlencode($response['threeDSACSURL']),
                                'PaReq' => rawurlencode($response['threeDSPaReq']),
                                'MD' => rawurlencode($response['threeDSMD']),
                                'TermUrl' => rawurlencode($callback),
                            ],
                            plugins_url('public/3d-secure-form.php', dirname(__FILE__))
                        ),
                    ];
                } else {
                    return $this->process_response($response);
                }
            } catch (RuntimeException $exception) {
                wc_add_notice(  'Connection error.', 'error' );
                return [];
            }
        }
    }

    /**
     * receipt_page
     */
    function receipt_page($order) {
        switch ($this->settings['type']) {
            case 'hosted':
            case 'hosted_v3':
                echo $this->generate_hosted_form($order);
                break;
            case 'hosted_v2':
                echo $this->generate_embedded_form($order);
                break;
//            // we never should arrive here, because we have direct integration implemented via wordpress `payment_fields` functionality
            case 'direct':
            case 'direct_v2':
            default;
                return null;
        }
    }

    /**
     * Hosted form
     * @param $order_id
     * @return string
     */
    protected function generate_hosted_form($order_id) {
        $redirect  = add_query_arg('wc-api', 'wc_' . $this->id, home_url('/'));
        $callback  = add_query_arg('wc-api', 'wc_' . $this->id, home_url('/'));

        $req = array_merge($this->capture_order($order_id), array(
            'redirectURL'       => $redirect,
            'callbackURL'       => $callback,
            'formResponsive'    => $this->settings['formResponsive'],
            'threeDSVersion'    => 2,
        ));

        if (isset($this->settings['signature']) && !empty($this->settings['signature'])) {
            $req['signature'] = $this->create_signature($req, $this->settings['signature']);
        }

        $requestData = '';
        foreach ($req as $key => $value) {
            $requestData .= '<input type="hidden" name="' . $key . '" value="' . htmlentities($value) . '" />';
        }

        $gateway_url = $this->gateway_url;

        return <<<FORM
<form action="$gateway_url" method="post" id="payment_network_payment_form">
 <input type="submit" class="button alt" value="Pay securely via $this->gateway" />
 $requestData
</form>
<script type="text/javascript">
    window.onload = function () {
        document.getElementById('payment_network_payment_form').submit();
    };
</script>
FORM;

    }

    /**
     * Embedded form
     */
    protected function generate_embedded_form($order_id) {
        $redirect  = add_query_arg('wc-api', 'wc_' . $this->gateway, home_url('/'));
        $callback  = add_query_arg('wc-api', 'wc_' . $this->gateway, home_url('/'));

        $req = array_merge($this->capture_order($order_id), array(
            'redirectURL'       => $redirect,
            'callbackURL'       => $callback,
            'formResponsive'    => $this->settings['formResponsive'],
            'threeDSVersion'    => 2,
        ));

        if (isset($this->settings['signature']) && !empty($this->settings['signature'])) {
            $req['signature'] = $this->create_signature($req, $this->settings['signature']);
        }

        $requestData = '';
        foreach ($req as $key => $value) {
            $requestData .= '<input type="hidden" name="' . $key . '" value="' . htmlentities($value) . '" />';
        }

        return <<<FORM
<iframe id="paymentgatewayframe" name="paymentgatewayframe" frameBorder="0" seamless='seamless' style="width:699px; height:1100px;margin: 0 auto;display:block;"></iframe>
<form id="paymentgatewaymoduleform" action="$this->gateway_url" method="post" target="paymentgatewayframe">
 $requestData
</form>
<script>
	// detects if jquery is loaded and adjusts the form for mobile devices
	document.body.addEventListener('load', function() {
		if( /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent) ) {
			const frame = document.querySelector('#paymentgatewayframe');
			frame.style.height = '1280px';
			frame.style.width = '50%';
		}
	});
	document.getElementById('paymentgatewaymoduleform').submit();
</script>
FORM;
    }

    /**
     * Direct form step 1
     * @return string
     */
    protected function generate_direct_initial_request_form_v2() {
        $parameters = [
            'cardNumber'         => @$_POST['cardNumber'],
            'cardExpiryMonth'    => @$_POST['cardExpiryMonth'],
            'cardExpiryYear'     => @$_POST['cardExpiryYear'],
            'cardCVV'            => @$_POST['cardCVV'],
        ];

        $browserInfo = '';
        $scriptData = '';

        if (in_array($this->settings['type'], ['direct_v2'], true)) {
            $deviceData = [
                'deviceChannel'				=> 'browser',
                'deviceIdentity'			=> (isset($_SERVER['HTTP_USER_AGENT']) ? htmlentities($_SERVER['HTTP_USER_AGENT']) : null),
                'deviceTimeZone'			=> '0',
                'deviceCapabilities'		=> '',
                'deviceScreenResolution'	=> '1x1x1',
                'deviceAcceptContent'		=> (isset($_SERVER['HTTP_ACCEPT']) ? htmlentities($_SERVER['HTTP_ACCEPT']) : null),
                'deviceAcceptEncoding'		=> (isset($_SERVER['HTTP_ACCEPT_ENCODING']) ? htmlentities($_SERVER['HTTP_ACCEPT_ENCODING']) : null),
                'deviceAcceptLanguage'		=> (isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? htmlentities($_SERVER['HTTP_ACCEPT_LANGUAGE']) : null),
                'deviceAcceptCharset'		=> (isset($_SERVER['HTTP_ACCEPT_CHARSET']) ? htmlentities($_SERVER['HTTP_ACCEPT_CHARSET']) : null),
            ];

            foreach ($deviceData as $key => $value) {
                $browserInfo .= '<input type="hidden" id="'.$key.'" name="browserInfo[' . $key .']" value="' . htmlentities($value) . '" />';
            }

            $scriptData = <<<SCRIPT
<script>
    const screen_width = (window && window.screen ? window.screen.width : '0');
    const screen_height = (window && window.screen ? window.screen.height : '0');
    const screen_depth = (window && window.screen ? window.screen.colorDepth : '0');
    const identity = (window && window.navigator ? window.navigator.userAgent : '');
    const language = (window && window.navigator ? (window.navigator.language ? window.navigator.language : window.navigator.browserLanguage) : '');
    const timezone = (new Date()).getTimezoneOffset();
    const java = (window && window.navigator ? navigator.javaEnabled() : false);
    document.getElementById('deviceIdentity').value = identity;
    document.getElementById('deviceTimeZone').value = timezone;
    document.getElementById('deviceCapabilities').value = 'javascript' + (java ? ',java' : '');
    document.getElementById('deviceAcceptLanguage').value = language;
    document.getElementById('deviceScreenResolution').value = screen_width + 'x' + screen_height + 'x' + screen_depth;
</script>
SCRIPT;
        }

        return <<<FORM
    <label class="card-label label-cardNumber">Card Number</label>
    <input type='text' class='card-input field-cardNumber' name='cardNumber' value='{$parameters['cardNumber']}' required='required'/>

    <div style="display:flex; place-content: center space-between; align-items: center;">
        <div style="width: 35%">
            <label class="card-label label-cardExpiryMonth">Card Expiry Date</label>
            <div style="display: flex; place-content: center space-between;">
                <input type='text' style="width: 45%" class='card-input field-cardExpiryMonth' name='cardExpiryMonth' value='{$parameters['cardExpiryMonth']}' required='required' placeholder='MM' maxlength='2'/>
                <input type='text' style="width: 45%" class='card-input field-cardExpiryYear' name='cardExpiryYear' value='{$parameters['cardExpiryYear']}' required='required' placeholder='YY' maxlength='4'/>
            </div>
        </div>
        <div style="width: 40%">
    <label class="card-label label-cardCVV">CVV</label>
    <input type='text' class='card-input field-cardCVV' name='cardCVV' value='{$parameters['cardCVV']}' required='required'/>
</div>
    </div>
    <br/>
    {$browserInfo}
    {$scriptData}
FORM;
    }

    /**
     * Direct form step 1
     * @param $order_id
     * @param array $errors
     * @return string
     */
    protected function generate_direct_initial_request_form_v1() {
        $parameters = [
            'cardNumber'         => @$_POST['cardNumber'],
            'cardExpiryMonth'    => @$_POST['cardExpiryMonth'],
            'cardExpiryYear'     => @$_POST['cardExpiryYear'],
            'cardCVV'            => @$_POST['cardCVV'],
        ];

        $browserInfo = '';
        $scriptData = '';

        if (in_array($this->settings['type'], ['direct_v2'], true)) {
            $deviceData = [
                'browserAcceptHeader'      => (isset($_SERVER['HTTP_ACCEPT']) ? htmlentities($_SERVER['HTTP_ACCEPT']) : null),
                'browserIPAddress'         => (isset($_SERVER['REMOTE_ADDR']) ? htmlentities($_SERVER['REMOTE_ADDR']) : null),
                'browserJavaEnabledVal'    => '',
                'browserJavaScriptEnabled' => true,
                'browserLanguage'		   => (isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? htmlentities($_SERVER['HTTP_ACCEPT_LANGUAGE']) : null),
                'browserScreenColorDepth'  => '',
                'browserScreenHeight'      => '',
                'browserScreenWidth'       => '',
                'browserTimeZone'          => '0',
                'browserUserAgent'		   => (isset($_SERVER['HTTP_USER_AGENT']) ? htmlentities($_SERVER['HTTP_USER_AGENT']) : null),
            ];

            foreach ($deviceData as $key => $value) {
                $browserInfo .= '<input type="hidden" id="'.$key.'" name="threeDSOptions[' . $key .']" value="' . htmlentities($value) . '" />';
            }

            $scriptData = <<<SCRIPT
<script>
    const screen_width = (window && window.screen ? window.screen.width : '0');
    const screen_height = (window && window.screen ? window.screen.height : '0');
    const screen_depth = (window && window.screen ? window.screen.colorDepth : '0');
    const identity = (window && window.navigator ? window.navigator.userAgent : '');
    const language = (window && window.navigator ? (window.navigator.language ? window.navigator.language : window.navigator.browserLanguage) : '');
    const timezone = (new Date()).getTimezoneOffset();
    const java = (window && window.navigator ? navigator.javaEnabled() : false);
    document.getElementById('browserUserAgent').value = identity;
    document.getElementById('browserTimeZone').value = timezone;
    document.getElementById('browserJavaEnabledVal').value = java;
    document.getElementById('browserLanguage').value = language;
    document.getElementById('browserScreenColorDepth').value = screen_depth;
    document.getElementById('browserScreenWidth').value = screen_width;
    document.getElementById('browserScreenHeight').value = screen_height;
</script>
SCRIPT;
        }

        return <<<FORM
    <label class="card-label label-cardNumber">Card Number</label>
    <input type='text' class='card-input field-cardNumber' name='cardNumber' value='{$parameters['cardNumber']}' required='required'/>

    <div style="display:flex; place-content: center space-between; align-items: center;">
        <div style="width: 35%">
            <label class="card-label label-cardExpiryMonth">Card Expiry Date</label>
            <div style="display: flex; place-content: center space-between;">
                <input type='text' style="width: 45%" class='card-input field-cardExpiryMonth' name='cardExpiryMonth' value='{$parameters['cardExpiryMonth']}' required='required' placeholder='MM' maxlength='2'/>
                <input type='text' style="width: 45%" class='card-input field-cardExpiryYear' name='cardExpiryYear' value='{$parameters['cardExpiryYear']}' required='required' placeholder='YY' maxlength='4'/>
            </div>
        </div>
        <div style="width: 40%">
    <label class="card-label label-cardCVV">CVV</label>
    <input type='text' class='card-input field-cardCVV' name='cardCVV' value='{$parameters['cardCVV']}' required='required'/>
</div>
    </div>
    <br/>
    {$browserInfo}
    {$scriptData}
FORM;
    }

    /**
     * Check for response from payment gateway
     */
    function process_response($data = null) {
        global $woocommerce;

        $_POST = array_map('stripslashes_deep', $_POST);

        $response = $data ?: $_POST;

        if (empty($response) || !isset($response['orderRef'])) {
            return $this->throw_empty_response();
        }

        $order = new WC_Order((int)$response['orderRef']);
        if (!$this->check_signature($response, $this->settings['signature'])) {
            return $this->throw_signature_error($order);
        }

        /**
         * Wallet creation.
         *
         * A wallet will always be created if a walletID is returned. Even if payment fails.
         */
        //If the wallets is enabled, the user is logged in and their is a wallet ID in the response.
        if ($this->settings['customerWalletsEnabled'] === 'Y' && isset($response['walletID']) && $order->get_user_id() > 0) {
            global $wpdb;
            $wallet_table_name = $wpdb->prefix . 'woocommerce_' . 'payment_network_' . 'wallets';

            $customersWalletID = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT wallets_id FROM {$wallet_table_name} WHERE users_id = %d AND merchants_id = %d AND wallets_id = %d LIMIT 1",
                    $order->get_user_id(),
                    $this->settings['merchantID'],
                    $response['walletID']
                )
            );

            //If the customer wallet record does not exists.
            if ($customersWalletID === null) {
                //Add walletID to request.
                $wpdb->insert($wallet_table_name, [
                    'users_id' => $order->get_user_id(),
                    'merchants_id' => $this->settings['merchantID'],
                    'wallets_id' => $response['walletID']
                ]);
            }
        }

        if (isset($response['responseCode'])) {
            if ($order->get_status == 'completed') {
                return [
                    'result' => 'error',
                    'redirect' => $this->get_return_url($order),
                ];
            } else {

                $orderNotes  = "\r\nResponse Code : {$response['responseCode']}\r\n";
                $orderNotes .= "Message : {$response['responseMessage']}\r\n";
                $orderNotes .= "Amount Received : " . number_format($response['amount'] / 100, 2, '.', ',') . "\r\n";
                $orderNotes .= "Unique Transaction Code : {$response['transactionUnique']}";

                if ($response['responseCode'] === '0') {
                    $order->set_transaction_id($response['xref']);
                    $order->add_order_note(__(ucwords($this->gateway) . ' payment completed.' . $orderNotes, self::$lang));
                    $order->payment_complete();

                    return [
                        'result' => 'success',
                        'redirect' => $this->get_return_url($order),
                    ];
                } else {
                    $message = __('Payment error: ', 'woothemes') . $response['responseMessage'];

                    if (method_exists($woocommerce, 'add_error')) {
                        $woocommerce->add_error($message);
                    } else {
                        wc_add_notice($message, $notice_type = 'error');
                    }
                    $order->update_status('failed');
                    $order->add_order_note(__(ucwords($this->gateway) . ' payment failed.' . $orderNotes, self::$lang));

                    return [
                        'result' => 'error',
                        'redirect' => $order->get_cancel_order_url($order),
                    ];
                }
            }
        } else {
            exit;
        }
    }

    ##########################
    ## Callbacks
    ##########################

    /**
     * Hook to process a subscriptions payment
     *
     * @param $amount_to_charge
     * @param $renewal_order
     */
    public function process_scheduled_subscription_payment_callback($amount_to_charge, $renewal_order) {
        // Gets all subscriptions (hopefully just one) linked to this order
        $subs = wcs_get_subscriptions_for_renewal_order($renewal_order);

        // Get all orders on this subscription and remove any that haven't been paid
        $orders = array_filter(current($subs)->get_related_orders('all'),function($ord){return $ord->is_paid();});

        // Replace every order with orderId=>xref kvps
        $xrefs = array_map(function($ord){return $ord->get_transaction_id();},$orders);

        // Return the xref corresponding to the most recent order (assuming order number increase with time)
        $xref = $xrefs[max(array_keys($xrefs))];

        $req = array(
            'merchantID' => $this->settings['merchantID'],
            'xref' => $xref,
            'amount' => (int) round($amount_to_charge, 2) * 100,
            'action' => "SALE",
            'type' => 9,
        );

        // Sign and send request
        $req['signature'] = $this->create_signature($req,$this->settings['signature']);
        $ch = curl_init($this->gateway_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($req));
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        parse_str(curl_exec($ch), $res);
        $info = curl_getinfo($ch);

        curl_close($ch);

        // handle response
        if (!empty($res)) {
            $orderNotes  = "\r\nResponse Code : {$res['responseCode']}\r\n";
            $orderNotes .= "Message : {$res['responseMessage']}\r\n";
            $orderNotes .= "Amount Received : " . number_format($res['amount'] / 100, 2, '.', ',') . "\r\n";
            $orderNotes .= "Unique Transaction Code : {$res['transactionUnique']}";

            if($this->check_signature($res,$this->settings['signature'])){
                if(isset($res['responseCode']) && $res['responseCode']==0) {
                    $renewal_order->set_transaction_id($res['xref']);
                    $renewal_order->add_order_note(__(ucwords($this->gateway) . ' payment completed.' . $orderNotes, self::$lang));
                    $renewal_order->payment_complete();
                    $renewal_order->save();
                    $result = true;
                } else {
                    $renewal_order->add_order_note(__(ucwords($this->gateway) . ' payment failed with signature error.' . $orderNotes, self::$lang));
                    $renewal_order->save();
                    $result = new WP_Error('payment_failed_error','recurring payment failed due to gateway decline');
                }
            } else {
                $renewal_order->add_order_note(__(ucwords($this->gateway).' payment failed' . $orderNotes, self::$lang));
                $renewal_order->save();
                $result = new WP_Error('signature_error','recurring payment failed due to signature error');
            }
        } else {
            $renewal_order->add_order_note(__(ucwords($this->gateway).' payment failed. Could not communicate with direct API. Curl data: ' . json_encode($info), self::$lang));
            $renewal_order->save();
            $result = new WP_Error('payment_failed_error','recurring payment failed due to communication error');
        }

        if (is_wp_error( $result )) {
            WC_Subscriptions_Manager::process_subscription_payment_failure_on_order( $renewal_order );
        } else {
            WC_Subscriptions_Manager::process_subscription_payments_on_order( $renewal_order );
        }
    }

    /**
     * Callback for Direct Response Initial Request
     */
    public function process_direct_callback() {
        // v1
        if (isset($_REQUEST['MD'], $_REQUEST['PaRes'], $_REQUEST['xref'])) {
            $req = array(
                'action'	   => 'SALE',
                'merchantID'   => $this->settings['merchantID'],
                'xref'         => $_REQUEST['xref'],
                'threeDSMD'    => $_REQUEST['MD'],
                'threeDSPaRes' => $_REQUEST['PaRes'],
                'threeDSPaReq' => (isset($_REQUEST['PaReq']) ? $_REQUEST['PaReq'] : null),
            );

            $req['signature'] = $this->create_signature($req, $this->settings['signature']);

            $response = $this->post($req);

            $data = $this->process_response($response);

            $this->redirect($data['redirect']);
        }

        // v2
        if (isset($_POST['threeDSMethodData']) || isset($_POST['cres'])) {
            $req = array(
                'merchantID' => $this->settings['merchantID'],
                'action' => 'SALE',
                // The following field must be passed to continue the 3DS request
                'threeDSRef' => $_COOKIE['threeDSRef'],
                'threeDSResponse' => $_POST,
            );

            $req['signature'] = $this->create_signature($req, $this->settings['signature']);

            $response = $this->post($req);

            if ($response['responseCode'] == 65802) {

                setcookie('threeDSRef', $response['threeDSRef'], time()+315);

                // Render an IFRAME to show the ACS challenge (hidden for fingerprint method)
                $style = (isset($response['threeDSRequest']['threeDSMethodData']) ? 'display: none;' : '');
                echo "<iframe name=\"threeds_acs\" style=\"height:420px; width:420px; {$style}\"></iframe>\n";

                // Silently POST the 3DS request to the ACS in the IFRAME
                echo $this->silentPost($response['threeDSURL'], $response['threeDSRequest'], 'threeds_acs');

                die();
            }

            $data = $this->process_response($response);

            echo <<<HTML
Processing secure form, please wait ...
<script>window.top.location.href = "{$data['redirect']}";</script>
HTML;
            die;
        }
    }

    /**
     * Callback for processing response for all type of Hosted integrations
     */
    public function process_hosted_callback() {
        $data = $this->process_response();

        $this->redirect($data['redirect']);
    }

    ##########################
    ## Helper functions
    ##########################

    /**
     * Redirect to the checkout when the server response is not legitimate
     *
     * @param $order
     * @return array
     */
    protected function throw_signature_error($order) {
        global $woocommerce;

        $message = "\r\n" . __('Payment error: ', 'woothemes') . "Signature Check Failed";
        if (method_exists($woocommerce, 'add_error')) {
            $woocommerce->add_error($message);
        } else {
            wc_add_notice($message, $notice_type = 'error');
        }
        $order->add_order_note(__(ucwords($this->gateway).' payment failed' . $message, self::$lang));

        return [
            'result' => 'error',
            'redirect' => $order->get_checkout_payment_url(true),
        ];
    }

    protected function throw_empty_response() {
        global $woocommerce;

        $message = 'Payment unsuccessful - empty response (contact Administrator)';
        if (method_exists($woocommerce, 'add_error')) {
            $woocommerce->add_error($message);
        } else {
            wc_add_notice($message, $notice_type = 'error');
        }

        return [
            'result' => 'error',
            'redirect' => get_site_url()
        ];
    }

    /**
     * Check the signature received in a response
     *
     * @param array $data
     * @param $key
     * @return bool
     */
    protected function check_signature(array $data, $key) {
        $current_sig = $data['signature'];
        unset($data['signature']);
        $generated_sig = $this->create_signature($data, $key);
        return ($current_sig === $generated_sig);
    }

    /**
     * Redirect to the URL provided depending on integration type
     */
    protected function redirect($url) {
        if ($this->settings['type'] === 'hosted_v2') {
            echo <<<SCRIPT
<script>window.top.location.href = "$url";</script>;
SCRIPT;

        } else {
            wp_safe_redirect($url);
        }
        exit;
    }

    protected function post($parameters, $options = null) {
        $gatewayUrl = isset($options['gatewayURL']) && !empty($options['gatewayURL']) ? $options['gatewayURL'] : $this->gateway_url;

        $ch = curl_init($gatewayUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($parameters));
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        parse_str(curl_exec($ch), $response);
        curl_close($ch);

        return $response;
    }

    /**
     * Function to generate a signature
     */
    protected function create_signature(array $data, $key) {
        if (!$key || !is_string($key) || $key === '' || !$data || !is_array($data)) {
            return null;
        }

        ksort($data);

        // Create the URL encoded signature string
        $ret = http_build_query($data, '', '&');

        // Normalise all line endings (CRNL|NLCR|NL|CR) to just NL (%0A)
        $ret = preg_replace('/%0D%0A|%0A%0D|%0A|%0D/i', '%0A', $ret);

        // Hash the signature string and the key together
        return hash('SHA512', $ret . $key);

    }

    // Render HTML to silently POST data to URL in target brower window
    protected function silentPost($url = '?', array $post = null, $target = '_self') {

        $url = htmlentities($url);
        $target = htmlentities($target);
        $fields = '';

        if ($post) {
            foreach ($post as $name => $value) {
                $fields .= $this->fieldToHtml($name, $value);
            }
        }

        $ret = "
		<form id=\"silentPost\" action=\"{$url}\" method=\"post\" target=\"{$target}\">
			{$fields}
		</form>
		<script>
			window.setTimeout('document.forms.silentPost.submit()', 0);
		</script>
        <noscript>
			<input type=\"submit\" value=\"Continue\">
		<noscript>
	";

        return $ret;
    }

    protected function fieldToHtml($name, $value) {
        $ret = '';
        if (is_array($value)) {
            foreach ($value as $n => $v) {
                $ret .= $this->fieldToHtml($name . '[' . $n . ']', $v);
            }
        } else {
            // Convert all applicable characters or none printable characters to HTML entities
            $value = preg_replace_callback('/[\x00-\x1f]/', function($matches) { return '&#' . ord($matches[0]) . ';'; }, htmlentities($value, ENT_COMPAT, 'UTF-8', true));
            $ret = "<input type=\"hidden\" name=\"{$name}\" value=\"{$value}\" />\n";
        }

        return $ret;
    }
}
