<?php
/**
 * @package     Joomla - > Site and Administrator payment info
 * @subpackage  com_virtuemart
 * @subpackage 	fanap_podpay
 * @copyright   fanap => https://fanap.ir
 * @copyright   Copyright (C) 2017 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */
defined ('_JEXEC') or die('Restricted access');

if (!class_exists ('vmPSPlugin')) {
	require(JPATH_VM_PLUGINS . '/vmpsplugin.php');
}

//if (!class_exists ('checkHack')) {
//	require_once( VMPATH_ROOT . '/plugins/vmpayment/podpay/helper/inputcheck.php');
//}

if (!class_exists( 'VirtueMartCart' ))
	require(JPATH_SITE.DIRECTORY_SEPARATOR .'components' .DIRECTORY_SEPARATOR.'com_virtuemart'.DIRECTORY_SEPARATOR.'helpers'.DIRECTORY_SEPARATOR.'cart.php');

class plgVmPaymentPodPay extends vmPSPlugin {
	private $config;
	function __construct (& $subject, $config) {
		parent::__construct ($subject, $config);
		$this->_loggable = TRUE;
		$this->tableFields = array_keys ($this->getTableSQLFields ());
		$this->_tablepkey = 'id';
		$this->_tableId = 'id';
		$varsToPush = array('merchant_id' => array('', 'varchar'),'zaringate' => array(0, 'int'));
		$this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
		$params = JComponentHelper::getParams('com_podsso');
		$this->config = [
			"service"       => $params->get('platform_address') . "/srv/basic-platform",
			"sso"           => $params->get('sso_address') . "/oauth2/",
			"client_id"     => $params->get('client_id'),
			"client_secret" => $params->get('client_secret'),
			"api_token"     => $params->get('api_token'),
			"guild"         => $params->get('guild_code'),
			"pod_invoice_url" => $params->get('private_call_address')."/v1/pbc/payinvoice",

		];
	}

	public function getVmPluginCreateTableSQL () {
		return $this->createTableSQL ('Payment podpay Table');
	}

	function getTableSQLFields () {

		$SQLfields = array(
			'id'                          => 'int(1) UNSIGNED NOT NULL AUTO_INCREMENT',
			'virtuemart_order_id'         => 'int(1) UNSIGNED',
			'order_number'                => 'char(64)',
			'order_pass'                  => 'varchar(50)',
			'virtuemart_paymentmethod_id' => 'mediumint(1) UNSIGNED',
			'crypt_virtuemart_pid' 	      => 'varchar(255)',
			'salt'                        => 'varchar(255)',
			'payment_name'                => 'varchar(5000)',
			'amount'                      => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\'',
			'payment_currency'            => 'char(3)',
			'email_currency'              => 'char(3)',
			'mobile'                      => 'varchar(12)',
			'tracking_code'               => 'varchar(50)'
		);

		return $SQLfields;
	}

	function getOtt($api_token){
		$config = $this->config;

		$url =$config['service']."/nzh/ott/";
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, [
			"_token_: {$api_token}",
			"_token_issuer_: 1"
		]);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		$response = curl_exec($ch);
		curl_close($ch);
		$resp = json_decode($response);
		return $ott = $resp->ott;
	}


	function plgVmConfirmedOrder ($cart, $order) {
		$config = $this->config;
		if (!$this->selectedThisByMethodId($cart->virtuemart_paymentmethod_id)) {
			return null;
		}

		if (!($method = $this->getVmPluginMethod ($order['details']['BT']->virtuemart_paymentmethod_id))) {
			return NULL;
		}
		$app	= JFactory::getApplication();
		$session = JFactory::getSession();

		$salt = JUserHelper::genRandomPassword(32);
		$crypt_virtuemartPID = JUserHelper::getCryptedPassword($order['details']['BT']->virtuemart_order_id, $salt);
		if ($session->isActive('uniq')) {
			$session->clear('uniq');
		}
		$session->set('uniq', $crypt_virtuemartPID);

		$payment_currency = $this->getPaymentCurrency($method,$order['details']['BT']->payment_currency_id);
		$totalInPaymentCurrency = vmPSPlugin::getAmountInCurrency($order['details']['BT']->order_total,$payment_currency);
		$currency_code_3 = shopFunctions::getCurrencyByID($payment_currency, 'currency_code_3');
		$email_currency = $this->getEmailCurrency($method);
		$dbValues['payment_name'] = $this->renderPluginName ($method) . '<br />';
		$dbValues['order_number'] = $order['details']['BT']->order_number;
		$dbValues['order_pass'] = $order['details']['BT']->order_pass;
		$dbValues['virtuemart_paymentmethod_id'] = $order['details']['BT']->virtuemart_paymentmethod_id;
		$dbValues['crypt_virtuemart_pid'] = $crypt_virtuemartPID;
		$dbValues['salt'] = $salt;
		$dbValues['payment_currency'] = $order['details']['BT']->order_currency;
		$dbValues['email_currency'] = $email_currency;
		$dbValues['amount'] = $totalInPaymentCurrency['value'];
		$dbValues['mobile'] = $order['details']['BT']->phone_2;
		$this->storePSPluginInternalData ($dbValues);
		$id = JUserHelper::getCryptedPassword($order['details']['BT']->virtuemart_order_id);
		$app	= JFactory::getApplication();
		$Amount = round($totalInPaymentCurrency['value']);
		$Description = 'خرید محصول از فروشگاه '. $cart->vendor->vendor_store_name;
		//Todo: send $Description to pod invoice
		$ott = $this->getOtt($session->get('pod_api_token'));
		$url = $config['service'];
		$fields = "/nzh/biz/issueInvoice?userId={$session->get('pod_user_id')}&pay=true&preferredTaxRate=0&guildCode={$session->get('pod_guild_code')}&verificationNeeded=true&productId[]=0&price[]={$Amount}&quantity[]=1&productDescription[]=desc";

			$ch = curl_init($url.$fields);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_HTTPHEADER, [
				"_token_: {$session->get('pod_api_token')}",
				"_ott_: {$ott}",
				"_token_issuer_: 1"
			]);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			$response = curl_exec($ch);
			$e = curl_error($ch);
			if($e){
				echo $e;
				exit();
			}
			curl_close($ch);
			$resp = json_decode($response);
			if($resp->hasError)
			{
				echo "error code:";
				echo $resp->errorCode;
				exit();
			}

			$session->set('pod_invoice_id',$resp->result->id);
			$CallbackURL = JURI::root().'pay-return';
			$url =  $config['pod_invoice_url']."/?invoiceId={$resp->result->id}&redirectUri={$CallbackURL}&callUri={$CallbackURL}";

			Header('Location: '.$url);
	}

	public function plgVmOnPaymentResponseReceived(&$html) {
		$config = $this->config;
		if (!class_exists('VirtueMartModelOrders')) {
			require(VMPATH_ADMIN . DS . 'models' . DS . 'orders.php');
		}

		$app	= JFactory::getApplication();
		$jinput = $app->input;
		$gateway = $jinput->get->get('gid', '3', 'INT');
		$billNumber = $jinput->get->get('paymentBillNumber') ?  $jinput->get->get('paymentBillNumber') : $jinput->get->get('billNumber');
		$invoice_id = $_GET['invoiceId'];
		if ($gateway == '3'){
			$session = JFactory::getSession();
			if ($session->isActive('uniq') && $session->get('uniq') != null) {
				$cryptID = $session->get('uniq');
			}
			else {
				$msg = $this->getGateMsg('notff');
				$link = JRoute::_(JUri::root().'index.php/component/virtuemart/cart',false);
				$app->redirect($link, '<h2>'.$msg.'</h2>', $msgType='Error');
			}

			$orderInfo = $this->getOrderInfo ($cryptID);
			if ($orderInfo != null){
				if (!($currentMethod = $this->getVmPluginMethod($orderInfo->virtuemart_paymentmethod_id))) {
					return NULL;
				}
			}
			else {
				return NULL;
			}


			$salt = $orderInfo->salt;
			$id = $orderInfo->virtuemart_order_id;
			$uId = $cryptID.':'.$salt;

			$order_id = $orderInfo->order_number;
			//$mobile = $orderInfo->mobile; 
			$payment_id = $orderInfo->virtuemart_paymentmethod_id;
			$pass_id = $orderInfo->order_pass;
			$price = round($orderInfo->amount);


			$method = $this->getVmPluginMethod ($payment_id);

			if (JUserHelper::verifyPassword($id , $uId)) {
					try {
						$fields = "/nzh/biz/getInvoiceList/?size=1&id={$invoice_id}&offset=0";
						$ch = curl_init($config['service'].$fields);
						curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
						curl_setopt($ch, CURLOPT_HTTPHEADER, [
							"_token_: {$config['api_token']}",
							"_token_issuer_: 1"
						]);
						curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
						$response = curl_exec($ch);
						$e = curl_error($ch);
						if($e){
							echo $e;
						}
						curl_close($ch);
						$resp = json_decode($response);
						if($resp->result[0]->waiting){
							$fields = "/nzh/biz/verifyInvoice/?id={$invoice_id}";
							$ch = curl_init($config['service'].$fields);
							curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
							curl_setopt($ch, CURLOPT_HTTPHEADER, [
								"_token_: {$config['api_token']}",
								"_token_issuer_: 1"
							]);
							curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
							$response = curl_exec($ch);
							$e = curl_error($ch);
							if($e){
								echo $e;
							}
							curl_close($ch);
							$respVerify = json_decode($response);
						}
						if(isset($respVerify) && $respVerify->result->payed){
							//فاکتور بسته می‌شود تا امکان درخواست تسویه حساب فعال شود
							// با توجه به سیاست کسب و کار خود برای کنسل کردن خرید این بخش را باید تغییر دهید.
							$fields = "/nzh/biz/closeInvoice/?id={$invoice_id}";
							$ch = curl_init($config['service'].$fields);
							curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
							curl_setopt($ch, CURLOPT_HTTPHEADER, [
								"_token_: {$config['api_token']}",
								"_token_issuer_: 1"
							]);
							curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
							$response = curl_exec($ch);
							$e = curl_error($ch);
							if($e){
								echo $e;
							}
							curl_close($ch);
							$respClose = json_decode($response);
							if($respClose->hasError){
								$msg = $this->getGateMsg('cls');
								$link = JRoute::_(JUri::root().'index.php/component/virtuemart/cart',false);
								$app->redirect($link, '<h2>'.$msg.'</h2>', $msgType='Error');
							}
							$msg= $this->getGateMsg(100);
							$html = $this->renderByLayout('podpay_payment', array(
								'order_number' =>$order_id,
								'order_pass' =>$pass_id,
								'status' => $msg
							));
							$this->updateOrderInfo ($id,1);
							vRequest::setVar ('html', $html);
							$cart = VirtueMartCart::getCart();
							$cart->emptyCart();
							$session->clear('uniq');
						}
						else {
							$msg= $this->getGateMsg(999);
							$link = JRoute::_(JUri::root().'index.php/component/virtuemart/cart',false);
							$app->redirect($link, '<h2>'.$msg.'</h2>', $msgType='Error');
						}
					}
					catch(\SoapFault $e) {
						$msg= $this->getGateMsg('error');
						$link = JRoute::_(JUri::root().'index.php/component/virtuemart/cart',false);
						$app->redirect($link, '<h2>'.$msg.'</h2>', $msgType='Error');
					}

			}
			else {
				$msg= $this->getGateMsg('notff');
				$link = JRoute::_(JUri::root().'index.php/component/virtuemart/cart',false);
				$app->redirect($link, '<h2>'.$msg.'</h2>', $msgType='Error');
			}
		}
		else {
			return NULL;
		}
	}


	protected function getOrderInfo ($id){
		$db = JFactory::getDbo();
		$query = $db->getQuery(true);
		$query->select('*')
			->from($db->qn('#__virtuemart_payment_plg_podpay'));
		$query->where($db->qn('crypt_virtuemart_pid') . ' = ' . $db->q($id));
		$db->setQuery((string)$query);
		$result = $db->loadObject();
		return $result;
	}

	protected function updateOrderInfo ($id,$trackingCode){
		$db = JFactory::getDbo();
		$query = $db->getQuery(true);
		$fields = array($db->qn('tracking_code') . ' = ' . $db->q($trackingCode));
		$conditions = array($db->qn('virtuemart_order_id') . ' = ' . $db->q($id));
		$query->update($db->qn('#__virtuemart_payment_plg_podpay'));
		$query->set($fields);
		$query->where($conditions);

		$db->setQuery($query);
		$result = $db->execute();
	}


	protected function checkConditions ($cart, $method, $cart_prices) {
		$amount = $this->getCartAmount($cart_prices);
		$address = (($cart->ST == 0) ? $cart->BT : $cart->ST);

		if($this->_toConvert){
			$this->convertToVendorCurrency($method);
		}

		$countries = array();
		if (!empty($method->countries)) {
			if (!is_array ($method->countries)) {
				$countries[0] = $method->countries;
			} else {
				$countries = $method->countries;
			}
		}

		if (!is_array ($address)) {
			$address = array();
			$address['virtuemart_country_id'] = 0;
		}

		if (!isset($address['virtuemart_country_id'])) {
			$address['virtuemart_country_id'] = 0;
		}
		if (count ($countries) == 0 || in_array ($address['virtuemart_country_id'], $countries) ) {
			return TRUE;
		}

		return FALSE;
	}

	public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected = 0, &$htmlIn) {

		if ($this->getPluginMethods($cart->vendorId) === 0) {
			if (empty($this->_name)) {
				$app = JFactory::getApplication();
				$app->enqueueMessage(vmText::_('COM_VIRTUEMART_CART_NO_' . strtoupper($this->_psType)));
				return false;
			} else {
				return false;
			}
		}
		$method_name = $this->_psType . '_name';

		$htmla = array();
		foreach ($this->methods as $this->_currentMethod) {
			if ($this->checkConditions($cart, $this->_currentMethod, $cart->cartPrices)) {

				$html = '';
				$cartPrices=$cart->cartPrices;
				if (isset($this->_currentMethod->cost_method)) {
					$cost_method=$this->_currentMethod->cost_method;
				} else {
					$cost_method=true;
				}
				$methodSalesPrice = $this->setCartPrices($cart, $cartPrices, $this->_currentMethod, $cost_method);

				$this->_currentMethod->payment_currency = $this->getPaymentCurrency($this->_currentMethod);
				$this->_currentMethod->$method_name = $this->renderPluginName($this->_currentMethod);
				$html .= $this->getPluginHtml($this->_currentMethod, $selected, $methodSalesPrice);
				$htmla[] = $html;
			}
		}
		$htmlIn[] = $htmla;
		return true;

	}

	public function plgVmOnSelectCheckPayment (VirtueMartCart $cart, &$msg) {
		if (!$this->selectedThisByMethodId($cart->virtuemart_paymentmethod_id)) {
			return null;
		}

		return $this->OnSelectCheck ($cart);
	}

	function plgVmOnCheckAutomaticSelectedPayment (VirtueMartCart $cart, array $cart_prices = array(), &$paymentCounter) {
		return $this->onCheckAutomaticSelected ($cart, $cart_prices, $paymentCounter);
	}

	public function plgVmonSelectedCalculatePricePayment (VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name) {
		return $this->onSelectedCalculatePrice ($cart, $cart_prices, $cart_prices_name);
	}

	public function plgVmOnCheckoutCheckDataPayment(  VirtueMartCart $cart) {
		if (!$this->selectedThisByMethodId($cart->virtuemart_paymentmethod_id)) {
			return NULL;
		}
			return true;
	}

	function plgVmOnStoreInstallPaymentPluginTable ($jplugin_id) {

		return $this->onStoreInstallPluginTable ($jplugin_id);
	}


	function plgVmonShowOrderPrintPayment ($order_number, $method_id) {
		return $this->onShowOrderPrint ($order_number, $method_id);
	}

	function plgVmDeclarePluginParamsPaymentVM3( &$data) {
		return $this->declarePluginParams('payment', $data);
	}
	function plgVmSetOnTablePluginParamsPayment ($name, $id, &$table) {

		return $this->setOnTablePluginParams ($name, $id, $table);
	}

	static function getPaymentCurrency (&$method, $selectedUserCurrency = false) {

		if (empty($method->payment_currency)) {
			$vendor_model = VmModel::getModel('vendor');
			$vendor = $vendor_model->getVendor($method->virtuemart_vendor_id);
			$method->payment_currency = $vendor->vendor_currency;
			return $method->payment_currency;
		} else {

			$vendor_model = VmModel::getModel( 'vendor' );
			$vendor_currencies = $vendor_model->getVendorAndAcceptedCurrencies( $method->virtuemart_vendor_id );

			if(!$selectedUserCurrency) {
				if($method->payment_currency == -1) {
					$mainframe = JFactory::getApplication();
					$selectedUserCurrency = $mainframe->getUserStateFromRequest( "virtuemart_currency_id", 'virtuemart_currency_id', vRequest::getInt( 'virtuemart_currency_id', $vendor_currencies['vendor_currency'] ) );
				} else {
					$selectedUserCurrency = $method->payment_currency;
				}
			}
			$vendor_currencies['all_currencies'] = explode(',', $vendor_currencies['all_currencies']);
			if(in_array($selectedUserCurrency,$vendor_currencies['all_currencies'])){
				$method->payment_currency = $selectedUserCurrency;
			} else {
				$method->payment_currency = $vendor_currencies['vendor_currency'];
			}
			return $method->payment_currency;
		}
	}

	public function getGateMsg ($msgId) {
		switch($msgId){
			case 999: $out = 'خطا در پرداخت';break;
			case	11: $out =  'شماره کارت نامعتبر است';break;
			case	12: $out =  'موجودي کافي نيست';break;
			case	13: $out =  'رمز نادرست است';break;
			case	14: $out =  'تعداد دفعات وارد کردن رمز بيش از حد مجاز است';break;
			case	15: $out =   'کارت نامعتبر است';break;
			case	17: $out =   'کاربر از انجام تراکنش منصرف شده است';break;
			case	18: $out =   'تاريخ انقضاي کارت گذشته است';break;
			case	21: $out =   'پذيرنده نامعتبر است';break;
			case	22: $out =   'ترمينال مجوز ارايه سرويس درخواستي را ندارد';break;
			case	23: $out =   'خطاي امنيتي رخ داده است';break;
			case	24: $out =   'اطلاعات کاربري پذيرنده نامعتبر است';break;
			case	25: $out =   'مبلغ نامعتبر است';break;
			case	31: $out =  'پاسخ نامعتبر است';break;
			case	32: $out =   'فرمت اطلاعات وارد شده صحيح نمي باشد';break;
			case	33: $out =   'حساب نامعتبر است';break;
			case	34: $out =   'خطاي سيستمي';break;
			case	35: $out =   'تاريخ نامعتبر است';break;
			case	41: $out =   'شماره درخواست تکراري است';break;
			case	42: $out =   'تراکنش Sale يافت نشد';break;
			case	43: $out =   'قبلا درخواست Verify داده شده است';break;
			case	44: $out =   'درخواست Verify يافت نشد';break;
			case	45: $out =   'تراکنش Settle شده است';break;
			case	46: $out =   'تراکنش Settle نشده است';break;
			case	47: $out =   'تراکنش Settle يافت نشد';break;
			case	48: $out =   'تراکنش Reverse شده است';break;
			case	49: $out =   'تراکنش Refund يافت نشد';break;
			case	51: $out =   'تراکنش تکراري است';break;
			case	52: $out =   'سرويس درخواستي موجود نمي باشد';break;
			case	54: $out =   'تراکنش مرجع موجود نيست';break;
			case	55: $out =   'تراکنش نامعتبر است';break;
			case	61: $out =   'خطا در واريز';break;
			case	100: $out =   'تراکنش با موفقيت انجام شد.';break;
			case	111: $out =   'صادر کننده کارت نامعتبر است';break;
			case	112: $out =   'خطاي سوئيچ صادر کننده کارت';break;
			case	113: $out =   'پاسخي از صادر کننده کارت دريافت نشد';break;
			case	114: $out =   'دارنده کارت مجاز به انجام اين تراکنش نيست';break;
			case	412: $out =   'شناسه قبض نادرست است';break;
			case	413: $out =   'شناسه پرداخت نادرست است';break;
			case	414: $out =   'سازمان صادر کننده قبض نامعتبر است';break;
			case	415: $out =   'زمان جلسه کاري به پايان رسيده است';break;
			case	416: $out =   'خطا در ثبت اطلاعات';break;
			case	417: $out =   'شناسه پرداخت کننده نامعتبر است';break;
			case	418: $out =   'اشکال در تعريف اطلاعات مشتري';break;
			case	419: $out =   'تعداد دفعات ورود اطلاعات از حد مجاز گذشته است';break;
			case	421: $out =   'IP نامعتبر است';break;
			case	500: $out =   'کاربر به صفحه زرین پال رفته ولي هنوز بر نگشته است';break;
			case	'error': $out ='خطا غیر منتظره رخ داده است';break;
			case	'hck2': $out = 'لطفا از کاراکترهای مجاز استفاده کنید';break;
			case	'notff': $out = 'سفارش پیدا نشد';break;
			case    'cls': $out = 'خطا در بستن فاکتور'; break;
		}
		return $out;
	}
}
