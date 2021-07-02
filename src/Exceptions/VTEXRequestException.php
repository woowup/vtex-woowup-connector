<?php
namespace WoowUpConnectors\Exceptions;

class VTEXRequestException extends \Exception
{
    public function __construct($message = '', $code = 0, $endpoint = '')
    {
        parent::__construct("Codigo de Error: $code Mensaje: $message Endpoint $endpoint ");
    }
}