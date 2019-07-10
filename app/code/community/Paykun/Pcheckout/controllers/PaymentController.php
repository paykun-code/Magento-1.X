<?php

if (!function_exists('boolval')) {
    function boolval($val) {
        return (bool) $val;
    }
}

require_once(Mage::getModuleDir('controllers','Paykun_Pcheckout').DS.'PkPaymentController.php');
require_once(Mage::getModuleDir('controllers','Paykun_Pcheckout').DS.'errors/ValidationExceptionController.php');
require_once(Mage::getModuleDir('controllers','Paykun_Pcheckout').DS.'errors/ErrorCodesController.php');

class Paykun_Pcheckout_PaymentController extends Mage_Core_Controller_Front_Action
{
    // Redirect to Paykun
    private $LOG_FILE_NAME = 'paykun.log';
    private $_isError;
    private $_errorMessage;
    private $_errorCode;
    private $_orderTransactionId;
    private $_paykunTransactionId;
    private $_orderId;

    protected $ALLOWED_CURRENCIES = array('INR');

    private $_isLogEnabled;
    private $_merchantId;
    private $_accessToken;
    private $_encKey;
    private $_isFieldError = false;
    private $isLive = true;
    private function setConfig() {

        $storeId = Mage::app()->getStore()->getStoreId();
//        $storeCode = Mage::app()->getStore()->getCode();
        $this->_isActive = Mage::getStoreConfig('payment/pcheckout/active', $storeId);
        $this->_isLogEnabled = Mage::getStoreConfig('payment/pcheckout/debug', $storeId);
        $this->_merchantId = Mage::getStoreConfig('payment/pcheckout/merchant_id', $storeId);
        $this->_accessToken = Mage::getStoreConfig('payment/pcheckout/auth_token', $storeId);
        $this->_encKey = Mage::getStoreConfig('payment/pcheckout/enc_key', $storeId);
        $this->isLive = (Mage::getStoreConfig('payment/pcheckout/is_live', $storeId) == 1) ? true: false;
//        $this->_merchantId = "";

    }
    private function addLog($log) {

        if($this->_isLogEnabled) {

            Mage::Log($log, Zend_Log::DEBUG, $this->LOG_FILE_NAME);

        }

    }
    public function redirectAction() {
        try {

            $this->setConfig();
            $this->addLog("Step 5 Process: Loading the redirect.html page");
            $this->loadLayout();
            // Get latest order data
            $this->_orderId = Mage::getSingleton('checkout/session')->getLastRealOrderId();
            $order = Mage::getModel('sales/order')->loadByIncrementId($this->_orderId);

            if($this->isGivenCurrencyAllowed($order) === false) {

                $this->saveItemsBackToCart($order->getItemsCollection(), Mage::getSingleton('checkout/session'));
                parent::_redirect('checkout/cart');

            }

            // Set status to payment pending
            $order->setState(Mage_Sales_Model_Order::STATE_PENDING_PAYMENT, true)->save();

            $amount = $order-> getBaseGrandTotal();
            $email = $order->getCustomerEmail();
            $name = $order->getCustomerName();
            $phone = substr(str_replace(' ', '', $order->getBillingAddress()->getTelephone()), 0, 20);
            $this->_orderTransactionId = time();


            $index = strpos($amount, '.');
            if ($index !== False){
                $amount = substr($amount, 0, $index+3);
            }

//            $this->addLog("Store ID and Code: $storeId | $storeCode");

//            $url = Mage::getStoreConfig('payment/pcheckout/payment_url', $storeId);
            $url = "Testing URL: ";




            $this->addLog("Data from Backend: $url | $this->_merchantId | $this->_accessToken | $this->_encKey");
            $this->addLog("Transaction-order ID: " . ($this->_orderTransactionId . "-". $this->_orderId));

            $link = $url;
            $link.="&data_amount=$amount&data_name=$name&data_email=$email&data_phone=$phone";

            $payment = $order->getPayment();
            $payment->setTransactionId($this->_orderTransactionId); // Make it unique.

            /*[+]Add new transaction*/
            $transaction = $payment->addTransaction(Mage_Sales_Model_Order_Payment_Transaction::TYPE_AUTH,
                null,
                false,
                'New Paykun Transaction');

            $transaction->setAdditionalInformation(Mage_Sales_Model_Order_Payment_Transaction::RAW_DETAILS,
                array('Context'=>'Token payment',
                    'Amount'=>$amount,
                    'Status'=>0,
                    'Url'=>$link,
                    'TXN_ID' => $this->_orderTransactionId
                )
            );

            $transaction->setIsTransactionClosed(false); // Close the transaction on return?
            $transaction->save();

            /*[-]Add new transaction*/

            $order->save();

            $requestData = array(
                /*Merchant detail*/
                'merchantId' => $this->_merchantId, 'accessToken'   => $this->_accessToken, 'encKey' => $this->_encKey,

                /*Order detail*/
                'orderId'   => $this->getOrderIdForPaykun($this->_orderId), 'purpose'   => $this->getItemPurpose($order->getAllItems()),
                'amount'    => $amount,
                'successUrl'=> Mage::getModel('core/url')->sessionUrlVar(Mage::getUrl('pkcheckout/payment/responsesuccess')),
                'failedUrl' => Mage::getModel('core/url')->sessionUrlVar(Mage::getUrl('pkcheckout/payment/responseFailed')),

                /*Customer detail*/
                'customerName' => $name, 'customerEmail' => $email, 'customerPhone' => $phone,

                /*Shipping Address*/
                's_country' => $order->getShippingAddress()->getCountry(),
                's_state'   => $order->getShippingAddress()->getRegion(),
                's_city'    => $order->getShippingAddress()->getCity(),
                's_pincode' => $order->getShippingAddress()->getPostcode(),
                's_address' => $order->getShippingAddress()->getStreet(),

                /*Billing address */
                'b_country' => $order->getBillingAddress()->getCountry(),
                'b_state'   => $order->getBillingAddress()->getRegion(),
                'b_city'    => $order->getBillingAddress()->getCity(),
                'b_pincode' => $order->getBillingAddress()->getPostcode(),
                'b_address' => $order->getBillingAddress()->getStreet()
            );

            $request = $this->getPaykunPaymentRequest($requestData, true);

            $block = $this->getLayout()->createBlock('Mage_Core_Block_Template', 'pcheckout',
                array('template' => 'paykun/redirect.phtml'))
                ->assign(array_merge( array( 'data'=> $request , 'isErrorExist' => $this->_isFieldError)));

            $this->getLayout()->getBlock('content')->append($block);
            $this->renderLayout();

        } catch (Exception $e){

            Mage::logException($e);
            $this->addLog($e);
            parent::_redirect('checkout/cart');

        }
    }

    private function isGivenCurrencyAllowed($order) {

        if(!in_array($order->getOrderCurrencyCode(), $this->ALLOWED_CURRENCIES)) {

            $order->setState(Mage_Sales_Model_Order::STATE_CANCELED, true)->save();
            Mage::getSingleton('core/session')->addError(
                Paykun_Pcheckout_Errors_ErrorCodesController::CURRIENCY_NOT_ALLOEWD_STRING
            );
            return false;

        }
        return true;

    }

    private function getItemPurpose($orderedItems) {

        $itemPurpose = "";
        $numItems = count($orderedItems);
        $currentCount = 0;

        foreach($orderedItems as $item){

            $extraStuff = ', ';

            if(++$currentCount === $numItems) {
                $extraStuff = '';
            }

            $item_detail = (array) $item->getData();
            $itemPurpose .= $item_detail['name'].$extraStuff;

        }

        return $itemPurpose;
    }


    private function getPaykunPaymentRequest($data, $shouldEncrypt = true) {

        try {

            $this->addLog(
                "merchantId => ".$data['merchantId'].
                ", accessToken=> ".$data['accessToken'].
                ", encKey => ".$data['encKey'].
                ", orderId => ".$data['orderId'].
                ", purpose=>".$data['purpose'].
                ", amount=> ".$data['amount']
            );

            $obj = new Paykun_Pcheckout_PkPaymentController($data['merchantId'], $data['accessToken'], $data['encKey'], $this->isLive, true);

            // Initializing Order
            $obj->initOrder($data['orderId'], $data['purpose'], $data['amount'], $data['successUrl'],  $data['failedUrl']);
            // Add Customer
            $obj->addCustomer($data['customerName'], $data['customerEmail'], $data['customerPhone']);

            // Add Shipping address
            $s_country = $country_name=Mage::app()->getLocale()->getCountryTranslation($data['s_country']);

            $obj->addShippingAddress($s_country, $data['s_state'], $data['s_city'], $data['s_pincode'], implode(", ",$data['s_address']));

            // Add Billing Address
            $b_country = $country_name=Mage::app()->getLocale()->getCountryTranslation($data['b_country']);
            $obj->addBillingAddress($b_country, $data['b_state'], $data['b_city'], $data['b_pincode'], implode(", ",$data['b_address']));

            //Render template and submit the form

            $obj->setCustomFields(array('udf_1' => $this->_orderTransactionId, 'udf_2' => $this->_orderId));

            return $obj->submit($shouldEncrypt);

        }
        catch (Exception $e) {

            $this->_isFieldError = true;
            $this->addLog($e->getMessage());
            return $e->getMessage();

        }


    }

    // Redirect from Paykun checkout
    // The response action is triggered when your gateway sends back a response after processing the customer's payment
    public function responseSuccessAction() { //Original method name
      //public function responseFailedAction() { //Testing method name
        $this->setConfig();
        $this->addLog("Running response action");
        $storeId    = Mage::app()->getStore()->getStoreId();
        $storeCode  = Mage::app()->getStore()->getCode();
        $this->addLog("Store ID and Code: $storeId | $storeCode");

        $this->_paykunTransactionId = $this->getRequest()->getParam('payment-id');
        $this->loadLayout();

        $this->addLog("Payment ID: $this->_paykunTransactionId");
        $response           = $this->_getcurlInfo($this->_paykunTransactionId);

        if(isset($response['status']) && $response['status'] == "1" || $response['status'] == 1 ) {
            $payment_status = $response['data']['transaction']['status'];

            if($payment_status === "Success") {
            //if(1) {
                $resAmout = $response['data']['transaction']['order']['gross_amount'];
                $order = Mage::getModel('sales/order');
                $this->_orderId = $response['data']['transaction']['custom_field_2'];
                $order->loadByIncrementId($this->_orderId);

                if((floor($order->getBaseGrandTotal())== floor($resAmout))) {


                    $this->addLog("Value of Tran-order ID: ");
                    $this->_orderTransactionId  = $response['data']['transaction']['custom_field_1'];
                    // Get order details

                    $this->addLog("Payment was successfull for $this->_orderTransactionId");
                    $order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, true);
                    $this->addLog("Pending payment is set to False");
                    $this->sendOrderMail();
                    $order->save();
                    $this->updateTransaction($order->getPayment(), $resAmout, 'Success');
                    Mage::getSingleton('core/session')->addSuccess("Thank you for your order. Your order is successfully placed with the order Id #".$order->getIncrementId());
                    $this->_redirect('checkout/onepage/success', array('_secure'=>true));

                } else {

                    $this->addLog("Some fraud activity is happening with the payment Id: $this->_paykunTransactionId With Order Id $this->_orderId");
                    //Some fraud activity is happening here
                    $order->cancel()->setState(Mage_Sales_Model_Order::STATE_CANCELED, true, 'Invalid transaction ID or request.')->save();
                    $this->_redirect('');

                }

            } else {

                $this->_orderId = $response['data']['transaction']['custom_field_2'];
                $order          = Mage::getModel('sales/order');
                $order->loadByIncrementId($this->_orderId);
                if($order->getId()) {
                    $order->cancel()->setState(Mage_Sales_Model_Order::STATE_CANCELED, true, 'Invalid transaction ID or request.')->save();
                }
                $this->_redirect('');
            }
        }

    }

    private function sendOrderMail() {

        $order_mail = new Mage_Sales_Model_Order();
        $order_mail->loadByIncrementId($this->_orderId);
        $i = Mage::getVersion();
        $updatedVersion=false;
        if(strpos($i,"1.9") === 0){
            $updatedVersion = true;
        }
        if(!$updatedVersion){   // above 1.9.0 version not support sendNewOrderEmail() fumction
            try{
                $order_mail->sendNewOrderEmail();
            } catch (Exception $ex) {
                Mage::throwException('Mail couldn\'t sent');
            }
        }
    }

    public function responseFailedAction() { //Original method name
    //public function responseSuccessAction() {   //Testing method name
        $this->setConfig();
        $this->_paykunTransactionId   = $this->getRequest()->getParam('payment-id');
        $response       = $this->_getcurlInfo($this->_paykunTransactionId);

        if(isset($response['status']) && $response['status'] == "1" || $response['status'] == 1 ) {
            $this->_orderId = $response['data']['transaction']['custom_field_2'];
            $this->_orderTransactionId  = $response['data']['transaction']['custom_field_1'];
            /*The transaction detail is fetched successfully from the server, now process the server response*/
            /*$transactionId  = 	$data['data']['transaction']['custom_field_1'];*/
            $session        =  Mage::getSingleton('checkout/session');
            $order          = Mage::getModel('sales/order')->loadByIncrementId($this->_orderId);
            $amount         = $order-> getBaseGrandTotal();

            //Make this order status as a cancel
            $order->cancel()->setState(Mage_Sales_Model_Order::STATE_CANCELED, true,
                'Payment failed with transaction id => '. $this->_paykunTransactionId)->save();
            $this->updateTransaction($order->getPayment(), $amount, 'Failed');
            /*Restore cart back to it's previous state*/
            $this->saveItemsBackToCart($order->getItemsCollection(), $session);

            /*Add error message for cancelled payment*/
            Mage::getSingleton('core/session')->addError('Your payment failed. Please try again later');

        } else {

            Mage::getSingleton('core/session')->addError('The transaction that you are trying to fetch is not available.');

        }
        $this->_redirect('checkout/cart');
    }

    private function updateTransaction($payment, $amount, $status = 'False') {

        //Get transaction id when transaction was saved with transaction id and then try to fetch the order
        // I was here and getting an error
        $transaction = $payment->getTransaction($this->_orderTransactionId);

        //$data = $transaction->getAdditionalInformation();
        //$url = $data['raw_details_info']['Url'];

        $transaction->setAdditionalInformation(Mage_Sales_Model_Order_Payment_Transaction::RAW_DETAILS,
            array(
                'PaykunId TXN Id'   => $this->_paykunTransactionId,
                'Context'           => 'Token payment',
                /*'Amount'            => $amount,*/
                'Status'            => $status,
                'Parent Transaction Id' => $this->_orderTransactionId
                //'Url'=>$url
            )
        )->save();

        $transaction->setParentTxnId($this->_orderTransactionId)->save();
        $payment->setIsTransactionClosed(1);
        $payment->save();
        $transaction->save();

    }

    private function saveItemsBackToCart($items, $session) {

        try {

            $cart = Mage::getSingleton('checkout/cart');
            foreach ($items as $item) {
                try {

                    $cart->addOrderItem($item);

                } catch (Mage_Core_Exception $e) {

                    $session->addError($this->__($e->getMessage()));
                    Mage::logException($e);
                    continue;

                }
            }
            $cart->save();

        } catch (Exception $e) {

            $session->addError($this->__($e->getMessage()));
            Mage::logException($e);

        }

    }

    private function getOrderIdForPaykun($orderId) {

        try {

            $orderNumber = str_pad((string)$orderId, 10, '0', STR_PAD_LEFT);

            return $orderNumber;

        } catch (Paykun_Checkout_Errors_ValidationExceptionController $e) {

            $this->_isError         = true;
            $this->_errorMessage    = Paykun_Pcheckout_Errors_ErrorCodesController::SESSION_ORDER_ID_NOT_FOUND_STRING;
            $this->_errorCode       = Paykun_Pcheckout_Errors_ErrorCodesController::SESSION_ORDER_ID_NOT_FOUND_CODE;

        }

    }

    // Get the order id from Paykun based the transaction id
    private function _getcurlInfo($iTransactionId) {

        try {

            $cUrl        = 'https://api.paykun.com/v1/merchant/transaction/' . $iTransactionId . '/';
            if(!$this->isLive) {
                $cUrl        = 'https://sandbox.paykun.com/api/v1/merchant/transaction/' . $iTransactionId . '/';
            }
            $storeId    = Mage::app()->getStore()->getStoreId();
            $storeCode  = Mage::app()->getStore()->getCode();

            $this->addLog("Store ID and Code: $storeId | $storeCode");

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $cUrl);
            curl_setopt($ch, CURLOPT_HEADER, FALSE);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array("MerchantId:$this->_merchantId", "AccessToken:$this->_accessToken"));
            if( isset($_SERVER['HTTPS'] ) ) {
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, TRUE);
            } else {
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
            }

            $response       = curl_exec($ch);
            $error_number   = curl_errno($ch);
            $error_message  = curl_error($ch);

            $this->addLog("Error number: $error_number");
            $this->addLog("Error number: $error_message");

            $res = json_decode($response, true);
            curl_close($ch);

            return ($error_message) ? null : $res;

        } catch (Exception $e) {

            Mage::logException($e);
            $this->addLog($e);
            return null;
            //throw $e;

        }

    }

}
