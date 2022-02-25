<?php

namespace App\Service;

use App\Entity\ClientB2b;
use App\Entity\DistributorB2b;
use App\Entity\StoreB2b;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class ClientsImportService
{
    private const DEBUG_LOG = false;
    private const IMPORT_CHUNK_SIZE = 1000;
    private EntityManagerInterface $em;
    private LoggerInterface $logger;
    private string $sessionId;

    private array $clientsObject;
    private array $distributorsObject;
    private array $storesObject;

    public function __construct
    (
        EntityManagerInterface $em,
        LoggerInterface $importLogger
    )
    {
        $this->em = $em;
        $this->logger = $importLogger;
        $this->sessionId = md5(time() . uniqid('', true));
    }

    /**
     * @param array $result
     */
    public function execute(array $result): void
    {
        $startTimeToLog = microtime(true);
        $this->beforeData();
        $this->writeLog('b2b/import/clients/start: time:'. $startTimeToLog);

        $this->writeLog('b2b/import/processing-distributors: time:'. (microtime(true) - $startTimeToLog));
        $this->updateDistributorB2b($result['distributors']);

        $this->writeLog('b2b/import/processing-stores: time:'. (microtime(true) - $startTimeToLog));
        $this->updateStoreB2b($result['stores']);

        $this->writeLog('b2b/import/processing-clients: time:'. (microtime(true) - $startTimeToLog));
        $this->updateClientsB2b($result['clients']);

        $this->writeLog('b2b/import/clients/end: time:'. (microtime(true) - $startTimeToLog));
    }

    private function beforeData(): void
    {
        $this->clientsObject = $this->indexed($this->em->getRepository(ClientB2b::class)->findAll());
        $this->distributorsObject = $this->indexed($this->em->getRepository(DistributorB2b::class)->findAll());
        $this->storesObject = $this->indexed($this->em->getRepository(StoreB2b::class)->findAll());
    }

    /**
     * @param $object
     * @return array
     */
    private function indexed($object): array
    {
        $array = [];
        foreach ($object as $item) {
            $array[$item->getB2bId()] = $item;
        }
        return $array;
    }

    /**
     * @param $distributors
     */
    private function updateDistributorB2b($distributors): void
    {
        if (isset($distributors)) {
            /** @var DistributorB2b $distributorItem */
            foreach ($distributors as $key => $distributor) {
                $distributorItem = $this->getDistributorObject($distributor);

                $distributorItem->setName($distributor['name'] ?? '');
                $distributorItem->setB2bId($distributor['id']);

                $this->em->persist($distributorItem);
                if ($key > 0 &&($key) % self::IMPORT_CHUNK_SIZE === 0) {
                    $this->em->flush();
                    $this->writeDebugLog('Обработано distributors обновлено' . $key);
                }
            }
            $this->em->flush();
        }
    }

    /**
     * @param $stores
     */
    private function updateStoreB2b($stores): void
    {
        if (isset($stores)) {
            /** @var StoreB2b $storeItem */
            foreach ($stores as $key => $store) {
                $storeItem = $this->getStoreObject($store);

                $storeItem->setName($store['name'] ?? '');
                $storeItem->setB2bId($store['id']);
                $storeItem->setDistributorId($store['distributor_id'] ?? null);

                $this->em->persist($storeItem);
                if ($key > 0 &&($key) % self::IMPORT_CHUNK_SIZE === 0) {
                    $this->em->flush();
                    $this->writeDebugLog('Обработано stores обновлено' . $key);
                }
            }
            $this->em->flush();
        }
    }

    /**
     * @param $clients
     */
    private function updateClientsB2b($clients): void
    {
        if (isset($clients)) {
            /** @var ClientB2b $clientItem */
            foreach ($clients as $key => $client) {

                $clientItem = $this->getClientObject($client);

                $clientItem->setName($client['fullName'] ?? '');
                $clientItem->setB2bId($client['id']);
                $clientItem->setEmail($client['email']);
                $clientItem->setStoreId($client['store_id'] ?? null);
                $clientItem->setRole([$client['roles']]);

                $this->em->persist($clientItem);
                if ($key > 0 &&($key) % self::IMPORT_CHUNK_SIZE === 0) {
                    $this->em->flush();
                    $this->writeDebugLog('Обработано clients обновлено' . $key);
                }
            }

            $this->em->flush();
        }
    }

    /**
     * @param $array
     * @return ClientB2b
     */
    private function getClientObject($array): ClientB2b
    {
        if (isset($this->clientsObject[$array['id']])) {
            return $this->clientsObject[$array['id']];
        }

        return new ClientB2b();
    }

    /**
     * @param $array
     * @return DistributorB2b
     */
    private function getDistributorObject($array): DistributorB2b
    {
        if (isset($this->distributorsObject[$array['id']])) {
            return $this->distributorsObject[$array['id']];
        }

        return new DistributorB2b();
    }

    /**
     * @param $array
     * @return StoreB2b
     */
    private function getStoreObject($array): StoreB2b
    {
        if (isset($this->storesObject[$array['id']])) {
            return $this->storesObject[$array['id']];
        }

        return new StoreB2b();
    }

    /**
     * @param $message
     */
    private function writeLog($message): void
    {
        $memory = memory_get_usage();
        $message = "[$this->sessionId] $message : memory: $memory";
        $this->logger->info($message);
    }

    /**
     * @param $message
     *
     * @return void
     */
    private function writeDebugLog($message): void
    {
        if (self::DEBUG_LOG) {
            $this->writeLog($message);
        }
    }
}