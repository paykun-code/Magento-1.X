<?php

require_once(Mage::getModuleDir('controllers','Paykun_Pcheckout').DS.'errors/ErrorController.php');

class Paykun_Checkout_Errors_ValidationExceptionController extends Paykun_Pcheckout_Errors_ErrorController
{
    protected $field = null;

    public function __construct($message, $code, $field = null)
    {
        parent::__construct($message, $code);

        $this->field = $field;
    }

    public function getField()
    {
        return $this->field;
    }
}