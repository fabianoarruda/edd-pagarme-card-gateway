<?php
/*
Plugin Name: Easy Digital Downloads - Pagar.me Gateway (Credit Card)
Plugin URL: http://easydigitaldownloads.com/extension/pagarme-gateway
Description: Pagar.me gateway for Easy Digital Downloads (Credit Card)
Version: 1.0
Author: Fabiano Arruda
Author URI: http://twitter.com/fabianoarruda
Contributors:
*/

// Don't forget to load the text domain here. Sample text domain is pw_edd

require("pagarme-php/Pagarme.php");

// registers the gateway
function pw_edd_register_gateway( $gateways ) {
	$gateways['pagarme_card_gateway'] = array( 'admin_label' => 'Pagar.me Gateway (Cartão)', 'checkout_label' => __( 'Cartão de Crédito', 'pw_edd' ) );
	return $gateways;
}
add_filter( 'edd_payment_gateways', 'pw_edd_register_gateway' );


// Remove this if you want a credit card form
// add_action( 'edd_sample_gateway_cc_form', '__return_false' );


// processes the payment
function pw_edd_process_payment( $purchase_data ) {

	global $edd_options;

	/**********************************
	* set transaction mode
	**********************************/

	if ( edd_is_test_mode() ) {
		// set test credentials here
		Pagarme::setApiKey($edd_options["test_api_key"]);
	} else {
		// set live credentials here
		Pagarme::setApiKey($edd_options["live_api_key"]);
	}

	/**********************************
	* check for errors here
	**********************************/


	// errors can be set like this
	if( ! isset($_POST['card_number'] ) ) {
		// error code followed by error message
		edd_set_error('empty_card', __('You must enter a card number', 'edd'));
	}



	/**********************************
	* Purchase data comes in like this:

    $purchase_data = array(
        'downloads'     => array of download IDs,
        'tax' 			=> taxed amount on shopping cart
        'fees' 			=> array of arbitrary cart fees
        'discount' 		=> discounted amount, if any
        'subtotal'		=> total price before tax
        'price'         => total price of cart contents after taxes,
        'purchase_key'  =>  // Random key
        'user_email'    => $user_email,
        'date'          => date( 'Y-m-d H:i:s' ),
        'user_id'       => $user_id,
        'post_data'     => $_POST,
        'user_info'     => array of user's information and used discount code
        'cart_details'  => array of cart details,
     );
    */

	// check for any stored errors
	$errors = edd_get_errors();
	if ( ! $errors ) {

		$purchase_summary = edd_get_purchase_summary( $purchase_data );

		/****************************************
		* setup the payment details to be stored
		****************************************/

		$payment = array(
			'price'        => $purchase_data['price'],
			'date'         => $purchase_data['date'],
			'user_email'   => $purchase_data['user_email'],
			'purchase_key' => $purchase_data['purchase_key'],
			'currency'     => $edd_options['currency'],
			'downloads'    => $purchase_data['downloads'],
			'cart_details' => $purchase_data['cart_details'],
			'user_info'    => $purchase_data['user_info'],
			'status'       => 'pending'
		);

		// record the pending payment
		$payment = edd_insert_payment( $payment );

		$merchant_payment_confirmed = false;

		/**********************************
		* Process the credit card here.
		* If not using a credit card
		* then redirect to merchant
		* and verify payment with an IPN
		**********************************/

		//die(print_r($purchase_data['cart_details'], true ));

		$customer = array(
			"email" => $purchase_data['user_info']['email'],
			"name" => $purchase_data['user_info']['first_name'] . " " . $purchase_data['user_info']['last_name']
		);

		$metadata = array(
			"campain_id" => $purchase_data['cart_details'][0]['id'],
			"campain_name" => $purchase_data['cart_details'][0]['name']

		);

		$transaction = new PagarMe_Transaction(array(
		    "amount" => number_format( $purchase_data['price'], 2, '', '' ), // Valor em centavos - 1000 = R$ 10,00
		    "payment_method" => "credit_card", // Meio de pagamento
		    "card_number" => $_POST[card_number], // Número do cartão
		    "card_holder_name" => $_POST[card_name], // Nome do proprietário do cartão
		    "card_expiration_month" => $_POST[card_exp_month], // Mês de expiração do cartão
		    "card_expiration_year" => $_POST[card_exp_year], // Ano de expiração do cartão
		    "card_cvv" => $_POST[card_cvc], // Código de segurança
				"customer" => $customer,
				"metadata" => $metadata
		));

		$transaction->charge();

		if($transaction->getStatus() == 'paid') {
			//Transação foi aprovada

			// if the merchant payment is complete, set a flag
			$merchant_payment_confirmed = true;

		} else if($transaction->getStatus() == 'refused') {
			//Transação foi recusada
			// $transaction->getRefuseReason() - mostra por que a transação foi recusada
		}






		if ( $merchant_payment_confirmed ) { // this is used when processing credit cards on site

			// once a transaction is successful, set the purchase to complete
			edd_update_payment_status( $payment, 'complete' );

			// record transaction ID, or any other notes you need
			edd_insert_payment_note( $payment, ' ID Transação Pagar.me: ' . $transaction[id] );

			// go to the success page
			edd_send_to_success_page();

		} else {
			$fail = true; // payment wasn't recorded
		}

	} else {
		$fail = true; // errors were detected
	}

	if ( $fail !== false ) {
		// if errors are present, send the user back to the purchase page so they can be corrected
		edd_send_back_to_checkout( '?payment-mode=' . $purchase_data['post_data']['edd-gateway'] );
	}
}
add_action( 'edd_gateway_pagarme_gateway', 'pw_edd_process_payment' );


// adds the settings to the Payment Gateways section
function pw_edd_add_settings( $settings ) {

	$sample_gateway_settings = array(
		array(
			'id' => 'pagarme_gateway_settings',
			'name' => '<strong>' . __( 'Configurções Pagar.me (Cartão)', 'pw_edd' ) . '</strong>',
			'desc' => __( 'Configurações do Gateway do Pagar.me', 'pw_edd' ),
			'type' => 'header'
		),
		array(
			'id' => 'live_api_key',
			'name' => __( 'Live API Key', 'pw_edd' ),
			'desc' => __( 'Adicione a live API key, que pode ser encontrada nas configurações do seu dashboard Pagar.me', 'pw_edd' ),
			'type' => 'text',
			'size' => 'regular'
		),
		array(
			'id' => 'test_api_key',
			'name' => __( 'Test API Key', 'pw_edd' ),
			'desc' => __( 'Adicione a test API key, que pode ser encontrada nas configurações do seu dashboard Pagar.me', 'pw_edd' ),
			'type' => 'text',
			'size' => 'regular'
		)
	);

	return array_merge( $settings, $sample_gateway_settings );
}
add_filter( 'edd_settings_gateways', 'pw_edd_add_settings' );
