document.addEventListener('DOMContentLoaded', function () {
    const stripe = Stripe(stripePayment.publishable_key);
    const elements = stripe.elements();
    const cardElement = elements.create('card');
    cardElement.mount('#card-element');

    const form = document.getElementById('payment-form');
    const resultDiv = document.getElementById('payment-result');
    const submitButton = document.getElementById('submit-payment');
    const amountSelect = document.getElementById('amount');
    const quantityInput = document.getElementById('quantity');
    const totalDisplay = document.getElementById('total');
    const currencySymbol = stripePayment.currency_symbol;

    function updateTotal() {
        const amount = parseFloat(amountSelect.value || 0);
        const quantity = parseInt(quantityInput.value || 1);
        totalDisplay.textContent = `${currencySymbol}${(amount * quantity).toFixed(2)}`;
    }
    updateTotal();

    amountSelect.addEventListener('change', updateTotal);
    quantityInput.addEventListener('input', updateTotal);

    submitButton.addEventListener('click', async function () {
        resultDiv.textContent = 'Processing payment...';

        const name = document.getElementById('name').value;
        const email = document.getElementById('email').value;
        const amount = amountSelect.value;
        const quantity = quantityInput.value;

        try {
            const response = await fetch(stripePayment.ajax_url + '?action=handle_stripe_payment', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    name,
                    email,
                    amount,
                    quantity,
                }),
            });

            const result = await response.json();

            if (result.success) {
                const { client_secret } = result.data;

                // Confirm the payment with the card element
                const { error } = await stripe.confirmCardPayment(client_secret, {
                    payment_method: {
                        card: cardElement,
                        billing_details: {
                            name,
                            email,
                        },
                    },
                });

                if (error) {
                    resultDiv.textContent = `Payment failed: ${error.message}`;
                } else {
                    resultDiv.textContent = 'Payment successful!';
                    form.reset();
                    cardElement.clear();
                    updateTotal();
                }
            } else {
                resultDiv.textContent = `Error: ${result.data.message}`;
            }
        } catch (error) {
            resultDiv.textContent = `Error: ${error.message}`;
        }
    });
});



document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('payment-form');
    const resultDiv = document.getElementById('payment-result');
    const submitButton = document.getElementById('submit-payment');
    const amountSelect = document.getElementById('amount');
    const quantityInput = document.getElementById('quantity');
    const totalDisplay = document.getElementById('total');

    const stripe = Stripe(stripePayment.ajax_url);
    const elements = stripe.elements();
    const cardElement = elements.create('card');
    cardElement.mount('#card-element');

    function updateTotal() {
        const amount = parseFloat(amountSelect.value || 0);
        const quantity = parseInt(quantityInput.value || 1);
        totalDisplay.textContent = `$${(amount * quantity).toFixed(2)}`;
    }

    amountSelect.addEventListener('change', updateTotal);
    quantityInput.addEventListener('input', updateTotal);

    updateTotal(); // Initialize total on page load

    submitButton.addEventListener('click', async function(event) {
        resultDiv.innerHTML = "Processing payment...";

        const formData = new FormData(form);
        const response = await fetch(stripePayment.ajax_url + '?action=handle_stripe_payment', {
            method: 'POST',
            body: formData
        });

        const result = await response.json();

        if (result.success) {
            resultDiv.innerHTML = "Payment successful! A confirmation email has been sent.";
            form.reset(); // Reset the form fields
            cardElement.clear(); // Clear the card details
            updateTotal(); // Reset the total
        } else {
            resultDiv.innerHTML = `Error: ${result.data.message}`;
        }
    });
});
