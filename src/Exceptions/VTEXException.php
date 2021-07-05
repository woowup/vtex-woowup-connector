<?php
namespace WoowUpConnectors\Exceptions;

class VTEXException extends \Exception
{
    private $requestParams;
    public function __construct($endpoint = "",$params = [])
    {
        parent::__construct("Mensaje: Maxima cantidad de intentos alcanzada Endpoint: $endpoint");
        $this->requestParams = $params;
    }

    public function getRequestParams():array
    {
        return $this->requestParams;
    }
}