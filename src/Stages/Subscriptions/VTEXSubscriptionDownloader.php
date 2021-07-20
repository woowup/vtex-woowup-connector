<?php
namespace WoowUpConnectors\Stages\Subscriptions;

use League\Pipeline\StageInterface;

class VTEXSubscriptionDownloader implements StageInterface
{
    private $vtexConnector;
    private $logger;
    public function __construct($vtexConnector, $logger)
    {
        $this->vtexConnector = $vtexConnector;
        $this->logger = $logger;
        return $this;
    }

    public function __invoke($payload)
    {
        $page = 0;
        do {
            $page++;
            $this->logger->info("Subscriptions page: " . $page);
            $params = [
                'size' => 100,
                'page' => $page
            ];
            $response = $this->_get('/rns/pub/subscriptions', $params);
            $this->logger->info("Success!");

            yield json_decode($response->getBody());

            $totalCustomers = $response->getHeader('X-Total-Count')[0];
        } while (((100 * $page) < $totalCustomers) && !empty(json_decode($response->getBody())));
    }
}