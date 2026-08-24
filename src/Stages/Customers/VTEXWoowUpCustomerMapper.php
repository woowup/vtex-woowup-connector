<?php

namespace WoowUpConnectors\Stages\Customers;

use GuzzleHttp\Exception\RequestException;
use League\Pipeline\StageInterface;
use WoowUpConnectors\Exceptions\VTEXRequestException;

class VTEXWoowUpCustomerMapper implements StageInterface
{
    const COMMUNICATION_ENABLED = 'enabled';
    const COMMUNICATION_DISABLED = 'disabled';
    const DISABLED_REASON_OTHER = 'other';
    const INVALID_EMAILS = ['ct.vtex.com.br', 'mail.mercadolibre.com'];

    protected $vtexConnector;
    protected $logger;
    protected $getNewsletterOptIn;
    private $apiKey;

    public function __construct($vtexConnector, $logger,$apiKey, $ignoreOptIn = false)
    {
        $this->vtexConnector = $vtexConnector;
        $this->logger = $logger;
        $this->getNewsletterOptIn = !$ignoreOptIn;
        $this->apiKey = $apiKey;
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


            // El opt-in se escribe siempre y la protección la aplica el uploader, que reusa la
            // entidad del find del alta/actualización. Antes esto costaba un GET /multiusers/find
            // extra por cliente, con un cliente HTTP propio que no pasaba por las métricas.
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