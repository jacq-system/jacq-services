<?php

namespace Jacq;

use GuzzleHttp\Client;
use GuzzleHttp\Promise;
use mysqli;

class ExternalScinames
{
private $db;
private array $externalServices;
private array $scinames = [];

/**
 * Class to search various external services for a scientific name
 *
 * @param mysqli $db link to database herbarinput
 * @param array $services which services shall we search
 */
public function __construct(mysqli $db, array $services = [])
{
    $this->db = $db;
    $this->externalServices = $services;
}

/**
 * search all configured services for a scientific name
 *
 * @param string $name the scientific name to find
 * @return array search results
 */
public function searchAll(string $name): array
{
    $this->scinames['searchString'] = $name;
    $this->scinames['results'] = array();

    $client = new Client(['timeout' => 8]);
    $promises = array();
    foreach ($this->externalServices as $key => $externalService) {
        $promises[$key] = $client->getAsync($externalService['url'] . urlencode($this->scinames['searchString']));
    }
    $responses = Promise\Utils::settle($promises)->wait();
    foreach ($responses as $key => $response) {
        $this->scinames['results'][$this->externalServices[$key]['code']] = array('match'      => array(),
                                                                                  'candidates' => array(),
                                                                                  'serviceID'  => $this->externalServices[$key]['serviceID'],
                                                                                  'name'       => $this->getServiceName($this->externalServices[$key]['serviceID']),
                                                                                  'error'      => null);
        if ($response['state'] == 'fulfilled') {
            $result = json_decode($response['value']->getBody()->getContents(), true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
            switch ($this->externalServices[$key]['code']) {
                case 'gbif':
                    $this->gbif_read($result);
                    break;
                case 'wfo':
                    $this->wfo_read($result);
                    break;
                case 'worms':
                    if ($response['value']->getStatusCode() == 200) {
                        $this->worms_read($result);
                    }
                    break;
            }
        } else {
            $this->scinames['results'][$this->externalServices[$key]['code']]['error'] = $response['reason']->getMessage();
        }
    }
    return $this->scinames;
}

////////////////////////////// private functions //////////////////////////////

/**
 * read tbl_nom_service and get the name of an external service
 *
 * @param int $serviceID serviceID of the used service
 * @return string name of the service
 */
private function getServiceName(int $serviceID): string
{
    $res = $this->db->query("SELECT `name` FROM `tbl_nom_service` WHERE `serviceID` = $serviceID")
                    ->fetch_assoc();
    return $res['name'] ?? '';
}

////////////////////////////// functions to read the external services //////////////////////////////

/**
 * read GBIF and store data into internal array, needs just the result of the service
 *
 * @param array $result given result of the service, json decoded
 * @return void
 */
private function gbif_read(array $result): void
{
    if (isset($result['count']) && $result['count'] > 0) {
        if ($result['count'] === 1) {
            $this->scinames['results']['gbif']['match'] = array('id'    => $result['results'][0]['key'],
                                                                'name'  => $result['results'][0]['scientificName']);
        } else {
            foreach ($result['results'] as $candidate) {
                $this->scinames['results']['gbif']['candidates'][] = array('id'   => $candidate['key'],
                                                                           'name' => $candidate['scientificName']);
            }
        }
    }
}

/**
 * read World Flora Online and store data into internal array, needs just the result of the service
 *
 * @param array $result given result of the service, json decoded
 * @return void
 */
private function wfo_read($result): void
{
    if (!empty($result['match'])) {
        $this->scinames['results']['wfo']['match'] = array('id'    => $result['match']['wfo_id'],
                                                           'name'  => $result['match']['full_name_plain']);
    } elseif (!empty($result['candidates'])) {
        foreach ($result['candidates'] as $candidate) {
            $this->scinames['results']['wfo']['candidates'][] = array('id'   => $candidate['wfo_id'],
                                                                      'name' => $candidate['full_name_plain']);
        }
    }
}

/**
 *  read World Register of Marine Species (VLIZ) and store data into internal array, needs just the result of the service
 *
 * @param array $result given result of the service, json decoded
 * @return void
 */
private function worms_read(array $result): void
{
    if (count($result) > 1) {
        foreach ($result as $candidate) {
            $this->scinames['results']['worms']['candidates'][] = array('id'   => $candidate['AphiaID'],
                                                                        'name' => $candidate['scientificname']);
        }
    } else {
        $this->scinames['results']['worms']['match'] = array('id'   => $result[0]['AphiaID'],
                                                             'name' => $result[0]['scientificname']);
    }
}

}
