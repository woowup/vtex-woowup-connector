<?php

namespace WoowUpConnectors\Stages\Customers;

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ServerException;
use League\Pipeline\StageInterface;
use WoowUpConnectors\Exceptions\VTEXRequestException;
use GuzzleHttp\Client;

class VTEXWoowUpCustomerMapper implements StageInterface
{
    const COMMUNICATION_ENABLED = 'enabled';
    const COMMUNICATION_DISABLED = 'disabled';
    const DISABLED_REASON_OTHER = 'other';
    const INVALID_EMAILS = ['ct.vtex.com.br', 'mercadolibre.com'];

    protected $vtexConnector;
    protected $logger;
    protected $getNewsletterOptIn;
    private $apiKey;
    private $_httpClient;

    public function __construct($vtexConnector, $logger,$apiKey)
    {
        $this->vtexConnector = $vtexConnector;
        $this->logger = $logger;
        $this->getNewsletterOptIn = true;
        $this->apiKey = $apiKey;
        $this->_httpClient = new Client();
        return $this;
    }

    public function __invoke($payload)
    {
        if (is_null($payload)) {
            return null;
        }

        return $this->buildCustomer($payload);
    }

    protected function buildCustomer($vtexCustomer)
    {
        $email = isset($vtexCustomer->email) && !empty($vtexCustomer->email) ? $vtexCustomer->email : null;
        $document = isset($vtexCustomer->document) && !empty($vtexCustomer->document) ? $vtexCustomer->document : null;

        if (!empty($email) || !empty($document)) {
            $customer = [
                'email' => $email,
                'document' => $document,
                'first_name' => ucwords(mb_strtolower($vtexCustomer->firstName)),
                'last_name' => ucwords(mb_strtolower($vtexCustomer->lastName)),
            ];

            if (isset($vtexCustomer->birthDate) && !empty($vtexCustomer->birthDate)) {
                $birthdate = date('Y-m-d', strtotime($vtexCustomer->birthDate));
                $customer['birthdate'] = $birthdate;
            }

            if (isset($vtexCustomer->homePhone) && !empty($vtexCustomer->homePhone)) {
                $customer['phone'] = $vtexCustomer->homePhone;
            }

            if (isset($vtexCustomer->documentType) && !empty($vtexCustomer->documentType)) {
                $customer['document_type'] = $vtexCustomer->documentType;
            }


            if ($this->newComunicationOptIn($customer)) {
                if (isset($vtexCustomer->isNewsletterOptIn) && ($this->getNewsletterOptIn)) {
                    if (!$vtexCustomer->isNewsletterOptIn) {
                        $customer['mailing_enabled'] = self::COMMUNICATION_DISABLED;
                        $customer['sms_enabled'] = self::COMMUNICATION_DISABLED;
                        $customer['mailing_enabled_reason'] = self::DISABLED_REASON_OTHER;
                        $customer['sms_enabled_reason'] = self::DISABLED_REASON_OTHER;
                    } else {
                        $customer['mailing_enabled'] = self::COMMUNICATION_ENABLED;
                        $customer['sms_enabled'] = self::COMMUNICATION_ENABLED;
                    }
                }
            }

            if (isset($customer['email'])) {
                foreach (self::INVALID_EMAILS as $email) {
                    if (stripos($customer['email'], $email) !== false) {
                        $customer['email'] = $customer['document'] . '@noemail.com';
                        $customer['mailing_enabled'] = self::COMMUNICATION_DISABLED;
                        $customer['mailing_enabled_reason'] = self::DISABLED_REASON_OTHER;
                    }
                }
            }

            if (isset($vtexCustomer->isNewsletterOptIn)) {
                if (!$vtexCustomer->isNewsletterOptIn) {
                    $customer['custom_attributes'] = [
                        'opt_in_vtex' => 'False',
                    ];
                } else {
                    $customer['custom_attributes'] = [
                        'opt_in_vtex' => 'True',
                    ];
                }
            }

            try {
                $vtexAddress = $this->vtexConnector->getAddress($vtexCustomer->id);
                if (isset($vtexAddress)) {
                    $address = $this->buildAddress($vtexAddress);
                    $customer += $address;
                }
            } catch (\Exception $e) {
                $this->logger->info("Error getting address: " . $e->getMessage());
            }

            foreach ($customer as $key => $value) {
                if (is_null($customer[$key]) || empty($customer[$key])) {
                    unset($customer[$key]);
                }
            }

            return $customer;
        }

        return null;
    }


    protected function newComunicationOptIn($customer)
    {
        $endpoint = env('WOOWUP_HOST').'/'.env('WOOWUP_VERSION').'/multiusers/find';
        $queryParams = [];
        if (isset($customer['email']) && !empty($customer['email'])) {
            $queryParams['email'] = $customer['email'];
        }
        if (isset($customer['document']) && !empty($customer['document'])) {
            $queryParams['document'] = $customer['document'];
        }

        if (empty($queryParams)) {
            return false;
        }

        try {
            $response = $this->_httpClient->request('GET', $endpoint, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Authorization' => 'Basic ' . $this->apiKey,
                ],
                'query' => $queryParams,
            ]);
            $code = $response->getStatusCode();
            if (in_array($code, [200, 206])) {
                $body = json_decode((string) $response->getBody());
                if (($body->payload->mailing_enabled_reason == null) || ($body->payload->mailing_enabled_reason == 'other')) {
                    return true;
                }
            }
        }catch (ClientException $e) {
            if ($e->hasResponse()) {
                $code = $e->getResponse()->getStatusCode();
                $this->logger->error("Client Error [" . $code . "] ");
                if ($code == 404){
                    return true;
                }
            }
        }catch (ServerException $e){
            if ($e->hasResponse()) {
                $code = $e->getResponse()->getStatusCode();
                $this->logger->error("Server Error [" . $code . "] " );
            }
        }

        return false;
    }

    protected function buildAddress($vtexAddress)
    {
        $street = ucwords(mb_strtolower($vtexAddress->street));
        $street .= isset($vtexAddress->number) ? (' ' . $vtexAddress->number) : '';

        $address = [
            'street' => $street,
            'postcode' => $vtexAddress->postalCode,
            'city' => ucwords(mb_strtolower($vtexAddress->city)),
            'state' => ucwords(mb_strtolower($vtexAddress->state)),
            'country' => $vtexAddress->country,
        ];

        return $address;
    }
}