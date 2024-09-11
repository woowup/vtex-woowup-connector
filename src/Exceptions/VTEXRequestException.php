<?php

namespace WoowUpConnectors\Exceptions;

class VTEXRequestException extends \Exception
{
    private $requestParams;
    private $sendToClient;

    public function __construct($message = '', $code = 0, $endpoint = '', $params = [], $sendToClient = false)
    {
        parent::__construct("Codigo de Error: $code Mensaje: $message Endpoint $endpoint ", $code);
        $this->requestParams = $params;
        $this->sendToClient = $sendToClient;
    }

    public function getRequestParams():array
    {
        return $this->requestParams;
    }

    public function getSendToClient():bool
    {
        return $this->sendToClient;
    }
}