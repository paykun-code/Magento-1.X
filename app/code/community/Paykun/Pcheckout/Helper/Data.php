<?php
class Paykun_Pcheckout_Helper_Data extends Mage_Core_Helper_Abstract
{
	public function indexAction()
	{
	    echo "";
	}

	public function getPendigStatusAsPerVersion(){
        if (version_compare(Mage::getVersion(), '1.4.0', '<')) {
            return Mage_Sales_Model_Order::STATE_HOLDED;
        }
        return Mage_Sales_Model_Order::STATE_PENDING_PAYMENT;
    }
}