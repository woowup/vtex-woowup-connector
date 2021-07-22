<?php


namespace WoowUpConnectors\Stages\Subscriptions;


class VTEXSubscriptionMapper implements \League\Pipeline\StageInterface
{

    public function __invoke($payload)
    {
        if (is_null($payload)) {
            return null;
        }
        return $this->buildCustomer($payload);
    }

    protected function buildCustomer($vtexSubscription)
    {
        $vtexCustomer = $vtexSubscription->customerInfo ?? null;
        $email    = isset($vtexCustomer->email) && !empty($vtexCustomer->email) ? $vtexCustomer->customerInfo->email : null;
        $document = isset($vtexCustomer->document) && !empty($vtexCustomer->document) ? $vtexCustomer->customerInfo->document : null;
        if (is_null($email)) {
            $email = $vtexSubscription->customerEmail;
        }
        $customer = [
            'email'         => $email,
            'document'      => $document,
            'first_name'    => ucwords(mb_strtolower($vtexCustomer->firstName)),
            'last_name'     => ucwords(mb_strtolower($vtexCustomer->lastName)),
        ];

        if (isset($vtexCustomer->homePhone) && !empty($vtexCustomer->homePhone)) {
            $customer['phone'] = $vtexCustomer->homePhone;
        }

        if (isset($vtexCustomer->documentType) && !empty($vtexCustomer->documentType)) {
            $customer['document_type'] = $vtexCustomer->documentType;
        }
        $customer['custom_attributes']['status_suscripcion'] = $this->getStatus($vtexSubscription->status);
        $customer['custom_attributes']['proxima_compra'] = date('c', strtotime($vtexSubscription->nextPurchaseDate));
        $customer['custom_attributes']['ultima_compra'] = date('c', strtotime($vtexSubscription->lastPurchaseDate));
        if (isset($vtexSubscription->items) &&
            isset($vtexSubscription->items[0]) &&
            isset($vtexSubscription->items[0]->skuId)) {
            $customer['custom_attributes']['sku'] = (int)$vtexSubscription->items[0]->skuId;
        }
        $customer['custom_attributes']['compra_omitida'] = $vtexSubscription->isSkipped?'is Skipped':'is not skipped';
        $validity = $vtexSubscription->plan->validity;
        $customer['custom_attributes']['fecha_validez_inicial'] = date('c', strtotime($validity->begin));
        $customer['custom_attributes']['fecha_validez_final'] = date('c', strtotime($validity->end));
        $frequency = $vtexSubscription->plan->frequency;
        $customer['custom_attributes']['frecuencia_compra_periodicidad'] = $frequency->periodicity;
        $customer['custom_attributes']['frecuencia_compra_intervalo'] = (int)$frequency->interval;
        $customer = $this->cleanArray($customer);
        if (isset($email) || isset($document)) {
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