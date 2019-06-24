<?php

namespace WoowUpConnectors\Stages\Customers;

use League\Pipeline\StageInterface;

class VTEXWoowUpCustomerMapper implements StageInterface
{
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
            	$customer['birthdate'] = $vtexCustomer->birthDate;
            }

            if (isset($vtexCustomer->homePhone) && !empty($vtexCustomer->homePhone)) {
            	$customer['phone'] = $vtexCustomer->homePhone;
            }

            if (isset($vtexCustomer->documentType) && !empty($vtexCustomer->documentType)) {
            	$customer['document_type'] = $vtexCustomer->documentType;
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