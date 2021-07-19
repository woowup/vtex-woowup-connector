<?php

namespace WoowUpConnectors\Stages\Customers;

use League\Pipeline\StageInterface;

class VTEXWoowUpCustomerMapper implements StageInterface
{
    const COMMUNICATION_ENABLED  = 'enabled';
    const COMMUNICATION_DISABLED = 'disabled';
    const DISABLED_REASON_OTHER  = 'other';

	protected $vtexConnector;
    protected $logger;

	public function __construct($vtexConnector, $logger)
	{
		$this->vtexConnector = $vtexConnector;
        $this->logger        = $logger;

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
        $email    = isset($vtexCustomer->email) && !empty($vtexCustomer->email) ? $vtexCustomer->email : null;
        $document = isset($vtexCustomer->document) && !empty($vtexCustomer->document) ? $vtexCustomer->document : null;

        if (!empty($email) || !empty($document)) {
            $customer = [
                'email'         => $email,
                'document'      => $document,
                'first_name'    => ucwords(mb_strtolower($vtexCustomer->firstName)),
                'last_name'     => ucwords(mb_strtolower($vtexCustomer->lastName)),
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

            if (isset($vtexCustomer->isNewsletterOptIn)) {
                if (!$vtexCustomer->isNewsletterOptIn) {
                    $customer['mailing_enabled']        = self::COMMUNICATION_DISABLED;
                    $customer['sms_enabled']            = self::COMMUNICATION_DISABLED;
                    $customer['mailing_enabled_reason'] = self::DISABLED_REASON_OTHER;
                    $customer['sms_enabled_reason']     = self::DISABLED_REASON_OTHER;
                } else {
                    $customer['mailing_enabled'] = self::COMMUNICATION_ENABLED;
                    $customer['sms_enabled']     = self::COMMUNICATION_ENABLED;
                }
            }

            if (isset($vtexCustomer->isNewsletterOptIn)) {
                if (!$vtexCustomer->isNewsletterOptIn) {
                    $customer['custom_attributes']      = [
                        'opt-in-vtex'  => 'False',
                    ];
                } else {
                    $customer['custom_attributes']      = [
                        'opt-in-vtex'  => 'True',
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

    protected function buildAddress($vtexAddress)
    {
        $street  = ucwords(mb_strtolower($vtexAddress->street));
        $street .= isset($vtexAddress->number) ? (' ' . $vtexAddress->number) : '';

        $address = [
            'street'   => $street,
            'postcode' => $vtexAddress->postalCode,
            'city'     => ucwords(mb_strtolower($vtexAddress->city)),
            'state'    => ucwords(mb_strtolower($vtexAddress->state)),
            'country'  => $vtexAddress->country,
        ];

        return $address;
    }
}