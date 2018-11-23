<?php


class Paykun_Pcheckout_Errors_ErrorController extends Exception
{
    public function __construct($message, $code)
    {
        $this->code = $code;

        $this->message = $message;
    }
}
