<?php
/*
Plugin Name: Stripe Payment Gateway Integration
Description: A custom plugin to integrate Stripe payment gateway with WordPress and allow payment through a shortcode.
Version: 2.1
Author: Yasin
*/

// Enqueue necessary scripts for Stripe
function stripe_payment_enqueue_scripts() {
    wp_enqueue_script('stripe-js', 'https://js.stripe.com/v3/');
    wp_enqueue_script('custom-stripe-js', plugin_dir_url(__FILE__) . 'custom-stripe.js', ['stripe-js'], null, true);
    wp_localize_script('custom-stripe-js', 'stripePayment', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'publishable_key' => get_option('stripe_publishable_key')
    ]);
}
add_action('wp_enqueue_scripts', 'stripe_payment_enqueue_scripts');

// Add admin menu for settings
function stripe_payment_admin_menu() {
    add_menu_page('Stripe Settings', 'Stripe Settings', 'manage_options', 'stripe-settings', 'stripe_payment_settings_page', 'dashicons-admin-generic');
}
add_action('admin_menu', 'stripe_payment_admin_menu');

// Display settings page
function stripe_payment_settings_page() {
    $connection_status = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        update_option('stripe_publishable_key', sanitize_text_field($_POST['stripe_publishable_key']));
        update_option('stripe_secret_key', sanitize_text_field($_POST['stripe_secret_key']));
        update_option('stripe_webhook_secret', sanitize_text_field($_POST['stripe_webhook_secret']));
        update_option('stripe_payment_amounts', array_map('sanitize_text_field', explode(',', $_POST['stripe_payment_amounts'])));
        update_option('stripe_currency', sanitize_text_field($_POST['stripe_currency']));

        // Test API connection
        require_once __DIR__ . '/vendor/autoload.php';
        \Stripe\Stripe::setApiKey(get_option('stripe_secret_key'));

        try {
            \Stripe\Balance::retrieve();
            $connection_status = '<div class="updated"><p>API Connection Successful!</p></div>';
        } catch (Exception $e) {
            $connection_status = '<div class="error"><p>API Connection Failed: ' . esc_html($e->getMessage()) . '</p></div>';
        }
    }

    $saved_amounts = implode(',', get_option('stripe_payment_amounts', ['10', '20', '50']));
    $currency = get_option('stripe_currency', 'USD');
    $webhook_url = home_url('/wp-json/stripe/v1/webhook');
    $shortcode = '[stripe_payment_form]';

    echo $connection_status;
    ?>
    <div class="wrap">
        <h1>Stripe Payment Settings</h1>
        <form method="POST">
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="stripe_publishable_key">Publishable Key</label>
                    </th>
                    <td>
                        <input type="text" name="stripe_publishable_key" id="stripe_publishable_key" value="<?php echo esc_attr(get_option('stripe_publishable_key')); ?>" class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="stripe_secret_key">Secret Key</label>
                    </th>
                    <td>
                        <input type="text" name="stripe_secret_key" id="stripe_secret_key" value="<?php echo esc_attr(get_option('stripe_secret_key')); ?>" class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="stripe_webhook_secret">Webhook Secret</label>
                    </th>
                    <td>
                        <input type="text" name="stripe_webhook_secret" id="stripe_webhook_secret" value="<?php echo esc_attr(get_option('stripe_webhook_secret')); ?>" class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="stripe_payment_amounts">Payment Amounts (Comma-separated)</label>
                    </th>
                    <td>
                        <input type="text" name="stripe_payment_amounts" id="stripe_payment_amounts" value="<?php echo esc_attr($saved_amounts); ?>" class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="stripe_currency">Currency</label>
                    </th>
                    <td>
                        <select name="stripe_currency" id="stripe_currency">
                            <?php
                            $currencies = ['USD', 'EUR', 'GBP', 'AUD', 'CAD'];
                            foreach ($currencies as $curr) {
                                $selected = ($currency === $curr) ? 'selected' : '';
                                echo "<option value='$curr' $selected>$curr</option>";
                            }
                            ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="stripe_webhook_url">Webhook URL</label>
                    </th>
                    <td>
                        <input type="text" id="stripe_webhook_url" value="<?php echo esc_url($webhook_url); ?>" class="regular-text" readonly>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="stripe_shortcode">Shortcode</label>
                    </th>
                    <td>
                        <input type="text" id="stripe_shortcode" value="<?php echo esc_html($shortcode); ?>" class="regular-text" readonly>
                        <p class="description">Use this shortcode to display the payment form on any page or post.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button('Save Settings'); ?>
        </form>
    </div>
    <?php
}


// Handle Stripe Payment via AJAX
function handle_stripe_payment() {
    require_once __DIR__ . '/vendor/autoload.php';

    \Stripe\Stripe::setApiKey(get_option('stripe_secret_key'));

    try {
        $amount = sanitize_text_field($_POST['amount']) * sanitize_text_field($_POST['quantity']) * 100; // Convert to cents
        $currency = get_option('stripe_currency', 'USD');

        $intent = \Stripe\PaymentIntent::create([
            'amount' => $amount,
            'currency' => $currency,
            'payment_method_types' => ['card'], // Ensure 'card' is listed
            'metadata' => [
                'customer_name' => sanitize_text_field($_POST['name']),
                'customer_email' => sanitize_email($_POST['email']),
            ],
        ]);

        wp_send_json_success(['client_secret' => $intent->client_secret]);
    } catch (Exception $e) {
        wp_send_json_error(['message' => $e->getMessage()]);
    }
}
add_action('wp_ajax_handle_stripe_payment', 'handle_stripe_payment');
add_action('wp_ajax_nopriv_handle_stripe_payment', 'handle_stripe_payment');


// Webhook Listener for Stripe
function handle_stripe_webhook() {
    require_once __DIR__ . '/vendor/autoload.php';

    $payload = @file_get_contents('php://input');
    $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'];
    $webhook_secret = get_option('stripe_webhook_secret');

    try {
        $event = \Stripe\Webhook::constructEvent(
            $payload,
            $sig_header,
            $webhook_secret
        );

        switch ($event->type) {
            case 'payment_intent.succeeded':
                $paymentIntent = $event->data->object;
                $customerEmail = $paymentIntent->metadata->customer_email;
                $customerName = $paymentIntent->metadata->customer_name;

                // Email content
                $subject = 'Payment Confirmation';
                $message = "Dear $customerName,\n\nThank you for your payment.\n\nPayment Details:\nAmount: " . ($paymentIntent->amount / 100) . " " . strtoupper($paymentIntent->currency) . "\nPayment ID: " . $paymentIntent->id . "\n\nRegards,\nYour Company Name";
                $headers = ['Content-Type: text/plain; charset=UTF-8'];

                // Send email
                if ($customerEmail) {
                    wp_mail($customerEmail, $subject, $message, $headers);
                }

                error_log('Payment succeeded for: ' . $paymentIntent->id);
                break;
            case 'payment_intent.payment_failed':
                $paymentIntent = $event->data->object;
                error_log('Payment failed for: ' . $paymentIntent->id);
                break;
            default:
                error_log('Received unknown event type ' . $event->type);
        }

        http_response_code(200);
    } catch (\Stripe\Exception\SignatureVerificationException $e) {
        error_log('Webhook signature verification failed: ' . $e->getMessage());
        http_response_code(400);
    }
}
add_action('rest_api_init', function () {
    register_rest_route('stripe/v1', '/webhook', [
        'methods' => 'POST',
        'callback' => 'handle_stripe_webhook',
    ]);
});

// Shortcode for payment form
function stripe_payment_form_shortcode() {
    $amounts = get_option('stripe_payment_amounts', ['10', '20', '50']);
    $currency = strtoupper(get_option('stripe_currency', 'USD'));
    $currency_symbols = [
        'USD' => '$',
        'EUR' => '€',
        'GBP' => '£',
        'AUD' => 'A$',
        'CAD' => 'C$'
    ];
    $currency_symbol = $currency_symbols[$currency] ?? '$';

    ob_start();
    ?>
    <div id="stripe-payment-form">
        <form id="payment-form">
            <label for="name">Name:</label><br>
            <input type="text" id="name" name="name" required><br><br>

            <label for="email">Email:</label><br>
            <input type="email" id="email" name="email" required><br><br>

            <label for="amount">Select Amount (<?php echo esc_html($currency_symbol); ?>):</label><br>
            <select id="amount" name="amount">
                <?php foreach ($amounts as $amount): ?>
                    <option value="<?php echo esc_attr($amount); ?>"><?php echo esc_html($currency_symbol . $amount); ?></option>
                <?php endforeach; ?>
            </select><br><br>

            <label for="quantity">Quantity:</label><br>
            <input type="number" id="quantity" name="quantity" value="1" min="1" required><br><br>

            <p>Total: <span id="total"><?php echo esc_html($currency_symbol); ?>0</span></p>

            <div id="card-element">
                <!-- Stripe Elements will be inserted here. -->
            </div><br>

            <button type="button" id="submit-payment">Pay Now</button>
        </form>
        <div id="payment-result"></div>
    </div>
    <?php
    return ob_get_clean();
}

add_shortcode('stripe_payment_form', 'stripe_payment_form_shortcode');



wp_localize_script('custom-stripe-js', 'stripePayment', [
    'ajax_url' => admin_url('admin-ajax.php'),
    'publishable_key' => get_option('stripe_publishable_key'),
    'currency_symbol' => $currency_symbols[get_option('stripe_currency', 'USD')] ?? '$'
]);


?>
