<?php 
/* -----------------------------------------------------------------------------------------
   VamShop - http://vamshop.com
   -----------------------------------------------------------------------------------------
   Copyright (c) 2014 VamSoft Ltd.
   License - http://vamshop.com/license.html
   ---------------------------------------------------------------------------------------*/
App::uses('PaymentAppController', 'Payment.Controller');

class AssetPaymentsController extends PaymentAppController {
	public $uses = array('PaymentMethod', 'Order');
	public $module_name = 'AssetPayments';
	public $icon = 'asset.png';

	public function settings ()
	{
		$this->set('data', $this->PaymentMethod->findByAlias($this->module_name));
	}

	public function install()
	{
		$new_module = array();
		$new_module['PaymentMethod']['active'] = '1';
		$new_module['PaymentMethod']['default'] = '0';
		$new_module['PaymentMethod']['name'] = Inflector::humanize($this->module_name);
		$new_module['PaymentMethod']['icon'] = $this->icon;
		$new_module['PaymentMethod']['alias'] = $this->module_name;

		$new_module['PaymentMethodValue'][0]['payment_method_id'] = $this->PaymentMethod->id;
		$new_module['PaymentMethodValue'][0]['key'] = 'ap_merchant_id';
		$new_module['PaymentMethodValue'][0]['value'] = '';

		$new_module['PaymentMethodValue'][1]['payment_method_id'] = $this->PaymentMethod->id;
		$new_module['PaymentMethodValue'][1]['key'] = 'ap_secret_key';
		$new_module['PaymentMethodValue'][1]['value'] = '';

		$this->PaymentMethod->saveAll($new_module);

		$this->Session->setFlash(__('Module Installed'));
		$this->redirect('/payment_methods/admin/');
	}

	public function uninstall()
	{

		$module_id = $this->PaymentMethod->findByAlias($this->module_name);

		$this->PaymentMethod->delete($module_id['PaymentMethod']['id'], true);
			
		$this->Session->setFlash(__('Module Uninstalled'));
		$this->redirect('/payment_methods/admin/');
	}

	public function before_process () 
	{
			
		$order = $this->Order->read(null,$_SESSION['Customer']['order_id']);
		
		$payment_method = $this->PaymentMethod->find('first', array('conditions' => array('alias' => $this->module_name)));

		$assetpayments_settings = $this->PaymentMethod->PaymentMethodValue->find('first', array('conditions' => array('key' => 'ap_merchant_id')));
		$ap_merchant_id = $assetpayments_settings['PaymentMethodValue']['value'];

		$assetpayments_data = $this->PaymentMethod->PaymentMethodValue->find('first', array('conditions' => array('key' => 'ap_secret_key')));
		$ap_secret_key = $assetpayments_data['PaymentMethodValue']['value'];

		$server_url = FULL_BASE_URL . BASE . '/payment/AssetPayments/result/';
		$result_url = FULL_BASE_URL . BASE . '/orders/place_order/';
      
		$ip = getenv('HTTP_CLIENT_IP')?:
			  getenv('HTTP_X_FORWARDED_FOR')?:
			  getenv('HTTP_X_FORWARDED')?:
			  getenv('HTTP_FORWARDED_FOR')?:
			  getenv('HTTP_FORWARDED')?:
			  getenv('REMOTE_ADDR');
			  
		$version_url = "http://" . $_SERVER['SERVER_NAME'] . "/version.txt";
		$version = file_get_contents($version_url);
      
		//****Required variables****//	
		$option['TemplateId'] = '19';
		$option['CustomMerchantInfo'] = 'VamShop: ' .$version;
		$option['MerchantInternalOrderId'] = $_SESSION['Customer']['order_id'];
		$option['StatusURL'] = $server_url;	
		$option['ReturnURL'] = $result_url;
		$option['AssetPaymentsKey'] = $ap_merchant_id;
		$option['Amount'] = $order['Order']['total'];	
		$option['Currency'] = $_SESSION['Customer']['currency_code'];
		$option['CountryISO'] = $order['BillCountry']['iso_code_3'];
		$option['IpAddress'] = $ip;
		
		//****Customer data and address****//
		$option['FirstName'] = $order['Order']['bill_name'];
        $option['Email'] = $order['Order']['email'];
        $option['Phone'] = $order['Order']['phone'];
        $option['Address'] = $order['Order']['bill_line_1'] . ', ' . $order['Order']['bill_city']. ', ' . $order['Order']['bill_zip']. ', ' . $order['BillState']['code']. ', ' . $order['BillCountry']['name'];
        $option['City'] = $order['Order']['bill_city'];
        $option['ZIP'] = $order['Order']['bill_zip'];
        $option['Region'] = $order['BillState']['code'];
        $option['Country'] = $order['BillCountry']['name'];
		
		//****Adding cart details****//
		for ($i=0, $n=sizeof($order['OrderProduct']); $i<$n; $i++) {
        $option['Products'][] = array(
            'ProductId' => $order['OrderProduct'][$i]['id'],
            'ProductName' => $order['OrderProduct'][$i]['name'],
            'ProductPrice' => $order['OrderProduct'][$i]['price'],
            'ProductItemsNum' => $order['OrderProduct'][$i]['quantity'],
			'ImageUrl' => 'https://assetpayments.com/dist/css/images/product.png',
			);
		}
		
		//****Adding shipping method****//
		$option['Products'][] = array(
				'ProductId' => '1',
				'ImageUrl' => 'https://assetpayments.com/dist/css/images/delivery.png',
				'ProductItemsNum' => 1,
				'ProductName' => $order['ShippingMethod']['name'],						
				'ProductPrice' => $order['Order']['shipping'], 					
			);
		
		//var_dump($option);
		
		$data = base64_encode( json_encode($option) ); 
		
		$content = '<form action="https://assetpayments.us/checkout/pay" method="post">
			<input type="hidden" name="data" value="' . $data . '">';
								
						
		$content .= '
			<button class="btn btn-default" type="submit" value="{lang}Process to Payment{/lang}"><i class="fa fa-check"></i> {lang}Process to Payment{/lang}</button>
			</form>';
	
	// Save the order
	
		foreach($_POST AS $key => $value)
			$order['Order'][$key] = $value;
		
		// Get the default order status
		$default_status = $this->Order->OrderStatus->find('first', array('conditions' => array('default' => '1')));
		$order['Order']['order_status_id'] = $default_status['OrderStatus']['id'];

		// Save the order
		$this->Order->save($order);

		return $content;
	}

	public function payment_after($order_id = 0)
	{

		if(empty($order_id))
		return;
		
		$order = $this->Order->read(null,$order_id);

		$payment_method = $this->PaymentMethod->find('first', array('conditions' => array('alias' => $this->module_name)));

		$assetpayments_settings = $this->PaymentMethod->PaymentMethodValue->find('first', array('conditions' => array('key' => 'ap_merchant_id')));
		$ap_merchant_id = $assetpayments_settings['PaymentMethodValue']['value'];

		$assetpayments_data = $this->PaymentMethod->PaymentMethodValue->find('first', array('conditions' => array('key' => 'ap_secret_key')));
		$ap_secret_key = $assetpayments_data['PaymentMethodValue']['value'];

		$server_url = FULL_BASE_URL . BASE . '/payment/AssetPayments/result/';
		$result_url = FULL_BASE_URL . BASE . '/orders/place_order/';
      
		$$ip = getenv('HTTP_CLIENT_IP')?:
			  getenv('HTTP_X_FORWARDED_FOR')?:
			  getenv('HTTP_X_FORWARDED')?:
			  getenv('HTTP_FORWARDED_FOR')?:
			  getenv('HTTP_FORWARDED')?:
			  getenv('REMOTE_ADDR');
		
		$version_url = "http://" . $_SERVER['SERVER_NAME'] . "/version.txt";
		$version = file_get_contents($version_url);
      
		//****Required variables****//	
		$option['TemplateId'] = '19';
		$option['CustomMerchantInfo'] = 'VamShop: ' .$version;
		$option['MerchantInternalOrderId'] = $_SESSION['Customer']['order_id'];
		$option['StatusURL'] = $server_url;	
		$option['ReturnURL'] = $result_url;
		$option['AssetPaymentsKey'] = $ap_merchant_id;
		$option['Amount'] = $order['Order']['total'];	
		$option['Currency'] = $_SESSION['Customer']['currency_code'];
		$option['CountryISO'] = $order['BillCountry']['iso_code_3'];
		$option['IpAddress'] = $ip;
		
		//****Customer data and address****//
		$option['FirstName'] = $order['Order']['bill_name'];
        $option['Email'] = $order['Order']['email'];
        $option['Phone'] = $order['Order']['phone'];
        $option['Address'] = $order['Order']['bill_line_1'] . ', ' . $order['Order']['bill_city']. ', ' . $order['Order']['bill_zip']. ', ' . $order['BillState']['code']. ', ' . $order['BillCountry']['name'];
        $option['City'] = $order['Order']['bill_city'];
        $option['ZIP'] = $order['Order']['bill_zip'];
        $option['Region'] = $order['BillState']['code'];
        $option['Country'] = $order['BillCountry']['name'];
		
		//****Adding cart details****//
		for ($i=0, $n=sizeof($order['OrderProduct']); $i<$n; $i++) {
        $option['Products'][] = array(
            'ProductId' => $order['OrderProduct'][$i]['id'],
            'ProductName' => $order['OrderProduct'][$i]['name'],
            'ProductPrice' => $order['OrderProduct'][$i]['price'],
            'ProductItemsNum' => $order['OrderProduct'][$i]['quantity'],
			'ImageUrl' => 'https://assetpayments.com/dist/css/images/product.png',
			);
		}
		
		//****Adding shipping method****//
		$option['Products'][] = array(
				'ProductId' => '1',
				'ImageUrl' => 'https://assetpayments.com/dist/css/images/delivery.png',
				'ProductItemsNum' => 1,
				'ProductName' => $order['ShippingMethod']['name'],						
				'ProductPrice' => $order['Order']['shipping'], 					
			);
		
		$data = base64_encode( json_encode($option) ); 
		
		$content = '<form action="https://assetpayments.us/checkout/pay" method="post">
			<input type="hidden" name="data" value="' . $data . '">';
						
		$content .= '
			<button class="btn btn-default" type="submit" value="{lang}Pay Now{/lang}"><i class="fa fa-dollar"></i> {lang}Pay Now{/lang}</button>
			</form>';

		return $content;

	}

	public function after_process()
	{
	}
	
	
	public function result()
	{
		
		$json = json_decode(file_get_contents('php://input'), true);
		
		$payment_method = $this->PaymentMethod->find('first', array('conditions' => array('alias' => $this->module_name)));

		$assetpayments_settings = $this->PaymentMethod->PaymentMethodValue->find('first', array('conditions' => array('key' => 'ap_merchant_id')));
		$ap_merchant_id = $assetpayments_settings['PaymentMethodValue']['value'];

		$assetpayments_data = $this->PaymentMethod->PaymentMethodValue->find('first', array('conditions' => array('key' => 'ap_secret_key')));
		$ap_secret_key = $assetpayments_data['PaymentMethodValue']['value'];
		
		$key = $ap_merchant_id;
		$secret = $ap_secret_key;
		$transactionId = $json['Payment']['TransactionId'];
		$signature = $json['Payment']['Signature'];
		$order_id = $json['Order']['OrderId'];
		$status = $json['Payment']['StatusCode'];
		
		$requestSign =$key.':'.$transactionId.':'.strtoupper($secret);
		$sign = hash_hmac('md5',$requestSign,$secret);
		
		$this->layout = false;
		$order = $this->Order->read(null,$order_id);

		
		if ($status == 1 && $sign == $signature) {
		
			$payment_method = $this->PaymentMethod->find('first', array('conditions' => array('alias' => $this->module_name)));
			$order_data = $this->Order->find('first', array('conditions' => array('Order.id' => $order_id)));
			$order_data['Order']['order_status_id'] = $payment_method['PaymentMethod']['order_status_id'];
			
			$this->Order->save($order_data);
		
		}
	}
	
}

?>