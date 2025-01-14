<?php
namespace Marin\LaravelRequestInjector\Exceptions;

use Exception;

class MissParamException extends Exception
{
    public function __construct($message = null, $code = 0,Exception $previous = null)
    {
        parent::__construct( $message, $code, $previous);
    }
}
