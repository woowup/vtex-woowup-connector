<?php


class VTEXException extends \Exception
{
    public function __construct($endpoint = "")
    {
        parent::__construct("Mensaje: Maxima cantidad de intentos alcanzada Endpoint: $endpoint");
    }
}