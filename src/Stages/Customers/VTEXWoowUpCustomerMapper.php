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
    const OPT_IN_MAX_ATTEMPTS = 25;

    protected $vtexConnector;
    protected $logger;
    protected $getNewsletterOptIn;
    private $apiKey;
    private $_httpClient;

    public function __construct($vtexConnector, $logger,$apiKey, $ignoreOptIn = false)
    {
        $this->vtexConnector = $vtexConnector;
        $this->logger = $logger;
        $this->getNewsletterOptIn = !$ignoreOptIn;
        $this->apiKey = $apiKey;
        $this->_httpClient = new Client();
        return $this;
    }

    public function __invoke($payload)
    {
        if (!is_null($payload)) {
            return $this->buildCustomer($payload);
        }

        return null;
    }

    /**
     * Maps a VTEX customer to WoowUp's format
     * @param  object $vtexCustomer   VTEX customer
     * @return array                  WoowUp customer
     */
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

            if (isset($vtexCustomer->gender) && !empty($vtexCustomer->gender)) {
                $customer['gender'] = $vtexCustomer->gender == 'male' ? "M" : "F";
            }

            if (isset($vtexCustomer->birthDate) && !empty($vtexCustomer->birthDate)) {
                $birthdate = date('Y-m-d', strtotime($vtexCustomer->birthDate));
                $customer['birthdate'] = $birthdate;
            }

            if (isset($vtexCustomer->homePhone) && !empty($vtexCustomer->homePhone)) {
                $customer['telephone'] = $vtexCustomer->homePhone;
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
                        $customer['whatsapp_enabled'] = self::COMMUNICATION_DISABLED;
                        $customer['whatsapp_enabled_reason'] = self::DISABLED_REASON_OTHER;
                    } else {
                        $customer['mailing_enabled'] = self::COMMUNICATION_ENABLED;
                        $customer['sms_enabled'] = self::COMMUNICATION_ENABLED;
                        $customer['whatsapp_enabled'] = self::COMMUNICATION_ENABLED;
                    }
                }
            }

            if (isset($customer['email'])) {
                foreach (self::INVALID_EMAILS as $email) {
                    if (stripos($customer['email'], $email) !== false) {
                        $customer['email'] = array_key_exists('document', $customer)
                            ? $customer['document'] . '@noemail.com'
                            : 'noemail@noemail.com';
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

            if (isset($vtexCustomer->updatedIn)) {
                $customer['custom_attributes']['updated_in'] =
                    date('Y-m-d H:i:s', strtotime($vtexCustomer->updatedIn));
            }

            if (isset($vtexCustomer->createdIn)) {
                $customer['custom_attributes']['created_in'] =
                    date('Y-m-d H:i:s', strtotime($vtexCustomer->createdIn));
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


    /**
     * Checks WoowUp to determine whether the customer's mailing opt-in should be mapped.
     *
     * Makes a GET request to /multiusers/find. Returns true if the customer does not exist
     * in WoowUp yet (404) or if their mailing_enabled_reason is null or 'other' (meaning the
     * connector is allowed to overwrite it). Returns false in all other cases.
     *
     * Retries up to OPT_IN_MAX_ATTEMPTS times on 429, sleeping the number of seconds
     * indicated by the Retry-After header before each retry.
     *
     * @param array $customer Customer data array, expected to contain 'email' and/or 'document'.
     * @return bool True if the opt-in should be mapped, false otherwise.
     */
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

        for ($attempt = 0; $attempt < self::OPT_IN_MAX_ATTEMPTS; $attempt++) {
            try {
                $response = $this->_httpClient->request('GET', $endpoint, [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Accept'        => 'application/json',
                        'Authorization' => 'Basic ' . $this->apiKey,
                    ],
                    'query' => $queryParams,
                ]);

                $code = $response->getStatusCode();
                if (in_array($code, [200, 206])) {
                    $body = json_decode((string) $response->getBody());
                    $reason = $body->payload->mailing_enabled_reason ?? null;
                    if ($reason === null || $reason === 'other') {
                        return true;
                    }
                }
                return false;

            } catch (ClientException $e) {
                if ($e->hasResponse()) {
                    $code = $e->getResponse()->getStatusCode();

                    if ($code === 404) {
                        return true;
                    }

                    if ($code === 429) {
                        $this->logger->warning("newComunicationOptIn: 429 on attempt " . ($attempt + 1) . " for " . $this->customerIdentity($customer));
                        $isLastAttempt = ($attempt + 1) === self::OPT_IN_MAX_ATTEMPTS;
                        if (!$isLastAttempt) {
                            $retryAfter = (int) $e->getResponse()->getHeaderLine('Retry-After');
                        sleep($retryAfter ?: (int) pow(2, $attempt));
                            continue;
                        }
                        $this->logger->error("newComunicationOptIn: 429 unresolved after " . self::OPT_IN_MAX_ATTEMPTS . " attempts — opt-in silenced for " . $this->customerIdentity($customer));
                        return false;
                    }

                    $this->logger->error("Client Error [" . $code . "] ");
                }
                return false;

            } catch (ServerException $e) {
                if ($e->hasResponse()) {
                    $this->logger->error("Server Error [" . $e->getResponse()->getStatusCode() . "] ");
                }
                return false;
            }
        }

        return false;
    }

    /**
     * Returns a string identifying the customer for use in log messages.
     *
     * @param array $customer Customer data array.
     * @return string Comma-separated email and/or document, or 'sin identidad' if neither is present.
     */
    private function customerIdentity(array $customer): string
    {
        $parts = array_filter([
            $customer['email'] ?? null,
            $customer['document'] ?? null,
        ]);
        return implode(',', $parts) ?: 'sin identidad';
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