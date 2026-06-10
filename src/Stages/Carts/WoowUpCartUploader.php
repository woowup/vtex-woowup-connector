<?php

namespace WoowUpConnectors\Stages\Carts;

use GuzzleHttp\Exception\RequestException;
use League\Pipeline\StageInterface;
use WoowUpV2\Models\AbandonedCartModel;
use WoowUpV2\Models\UserModel;

class WoowUpCartUploader implements StageInterface
{
    private $woowupV2Client;
    private $logger;
    private $woowupStats;

    public function __construct($woowupV2Client, $logger)
    {
        $this->woowupV2Client = $woowupV2Client;
        $this->logger         = $logger;
        $this->resetWoowupStats();
    }

    public function __invoke($payload)
    {
        if (!$payload || !isset($payload['cart'])) {
            return null;
        }

        /** @var AbandonedCartModel $cart */
        $cart         = $payload['cart'];
        $customerData = $payload['customer'] ?? null;

        if ($customerData) {
            $this->createOrCheckCustomer($customerData);
        }

        try {
            $this->woowupV2Client->abandonedCarts->create($cart);
            $this->logger->info("Cart {$cart->getExternalId()} created successfully.");
            $this->woowupStats['created']++;
            return true;
        } catch (RequestException $e) {
            $body = $e->hasResponse() ? json_decode((string) $e->getResponse()->getBody(), true) : [];
            $code = $body['code'] ?? (string) $e->getCode();
            $msg  = $body['payload']['errors'][0] ?? $body['message'] ?? $e->getMessage();
            $this->logger->info("Error uploading cart: [$code] $msg");
            $this->woowupStats['failed'][] = $cart;
            return false;
        } catch (\Exception $e) {
            $this->logger->warning("Invalid cart data, skipping", [
                'external_id' => $cart->getExternalId(),
                'error'       => $e->getMessage(),
            ]);
            $this->woowupStats['failed'][] = $cart;
            return false;
        }
    }

    private function createOrCheckCustomer(array $customerData): void
    {
        $identity = array_filter([
            'email'    => $customerData['email'] ?? '',
            'document' => $customerData['document'] ?? '',
        ]);

        if (empty($identity)) {
            return;
        }

        try {
            if (!$this->woowupV2Client->users->exist($identity)) {
                $customer = new UserModel();
                $customer->setEmail($customerData['email'], true);
                if (!empty($customerData['first_name'])) {
                    $customer->setFirstName($customerData['first_name']);
                }
                if (!empty($customerData['last_name'])) {
                    $customer->setLastName($customerData['last_name']);
                }
                if (!empty($customerData['document'])) {
                    $customer->setDocument($customerData['document']);
                }
                $this->woowupV2Client->users->create($customer);
                $this->logger->info("Customer created.");
            } else {
                $this->logger->info("Customer already exists.");
            }
        } catch (RequestException $e) {
            $body = $e->hasResponse() ? json_decode((string) $e->getResponse()->getBody(), true) : [];
            $code = $body['code'] ?? (string) $e->getCode();
            $msg  = $body['payload']['errors'][0] ?? $body['message'] ?? $e->getMessage();
            if ($code === 'internal_error' && strpos($msg, 'existente') !== false) {
                return;
            }
            $this->logger->info("Error creating/checking customer: [$code] $msg");
        }
    }

    public function getWoowupStats(): array
    {
        return $this->woowupStats;
    }

    public function resetWoowupStats(): void
    {
        $this->woowupStats = [
            'created' => 0,
            'failed'  => [],
        ];
    }
}
