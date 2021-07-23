<?php


namespace WoowUpConnectors\Stages\Subscriptions;


class VTEXSubscriptionMapper implements \League\Pipeline\StageInterface
{

    private $vtexConnector;
    public function __construct($vtexConnector)
    {
        $this->vtexConnector = $vtexConnector;
        return $this;
    }
    public function __invoke($payload)
    {
        if (is_null($payload)) {
            return null;
        }
        return $this->buildCustomer($payload);
    }

    protected function buildCustomer($vtexSubscription)
    {
        $customer['email'] = $vtexSubscription->customerEmail;
        $vtexCustomer = $this->vtexConnector->getCustomerFromId($vtexSubscription->customerId);
        if ($vtexCustomer) {
            if (isset($vtexCustomer->email) && !empty($vtexCustomer->email)) {
                $customer['email'] = $vtexCustomer->email;
            }
            if (isset($vtexCustomer->document) && !empty($vtexCustomer->document)) {
                $customer['document'] = $vtexCustomer->document;
            }
            if (isset($vtexCustomer->documentType) && !empty($vtexCustomer->documentType)) {
                $customer['document_type'] = $vtexCustomer->documentType;
            }
            if (isset($vtexCustomer->homePhone) && !empty($vtexCustomer->homePhone)) {
                $customer['phone'] = $vtexCustomer->homePhone;
            }
            $customer['first_name'] = ucwords(mb_strtolower($vtexCustomer->firstName));
            $customer['last_name'] = ucwords(mb_strtolower($vtexCustomer->lastName));
        }
        $customer['custom_attributes']['status_suscripcion'] = $this->getStatus($vtexSubscription->status);
        $customer['custom_attributes']['proxima_compra'] = date('c', strtotime($vtexSubscription->nextPurchaseDate));
        $customer['custom_attributes']['ultima_compra'] = date('c', strtotime($vtexSubscription->lastPurchaseDate));
        $skus = [];
        if (isset($vtexSubscription->items) && !empty($vtexSubscription->items)) {
            foreach ($vtexSubscription->items as $item) {
                $skus[] = $item->skuId;
            }
            $customer['custom_attributes']['sku'] = implode(';',$skus);
        }
        $customer['custom_attributes']['compra_omitida'] = $vtexSubscription->isSkipped ? 'is Skipped' : 'is not skipped';
        $validity = $vtexSubscription->plan->validity;
        $customer['custom_attributes']['fecha_validez_inicial'] = date('c', strtotime($validity->begin));
        $customer['custom_attributes']['fecha_validez_final'] = date('c', strtotime($validity->end));
        $frequency = $vtexSubscription->plan->frequency;
        $customer['custom_attributes']['frecuencia_compra_periodicidad'] = $frequency->periodicity;
        $customer['custom_attributes']['frecuencia_compra_intervalo'] = (int)$frequency->interval;
        $customer['custom_attributes']['fecha_ultima_modificacion'] = date('c', strtotime($vtexSubscription->lastUpdate));
        $customer = $this->cleanArray($customer);
        if (isset($customer['email']) || isset($customer['document'])) {
            return $customer;
        }
        return null;
    }

    public function getStatus($subscriptionStatus)
    {
        $status = ['ACTIVE' => 'Activo', 'CANCELED' => 'Cancelado', 'PAUSED' => 'Pausado'];
        return $status[$subscriptionStatus];
    }

    public function cleanArray($array)                // cleans null values and empty strings recursively
    {
        foreach ($array as $key => $value) {
            if (gettype($value) === 'array') {
                $array[$key] = $this->cleanArray($value);
            } elseif ($value === null || $value === '') {
                unset($array[$key]);
            }
        }
        return $array;
    }

}