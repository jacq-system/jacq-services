<?php

namespace Jacq;

class ExternalScinames
{
private array $scinames = [];
private array $curlCh = [];

public function searchAll($name): array
{
    $this->scinames['searchString'] = $name;
    $this->scinames['results'] = array();

    $this->curlCh['gbif'] = curl_init();
    $this->curlCh['wfo']  = curl_init();

    $this->gbif_setup();
    $this->wfo_setup();

    $mh = curl_multi_init();
    curl_multi_add_handle($mh, $this->curlCh['gbif']);
    curl_multi_add_handle($mh, $this->curlCh['wfo']);

    // execute all queries simultaneously, and continue when all are complete
    do {
        curl_multi_exec($mh, $running);
    } while ($running);

    curl_multi_remove_handle($mh, $this->curlCh['wfo']);
    curl_multi_remove_handle($mh, $this->curlCh['gbif']);
    curl_multi_close($mh);

    $this->gbif_read();
    $this->wfo_read();

    curl_close($this->curlCh['gbif']);
    curl_close($this->curlCh['wfo']);

    return $this->scinames;
}


////////////////////////////// private functions //////////////////////////////

private function gbif_setup(): void
{
    curl_setopt($this->curlCh['gbif'], CURLOPT_URL, "https://api.gbif.org/v1/species/search?datasetKey=d7dddbf4-2cf0-4f39-9b2a-bb099caae36c&q=" . urlencode($this->scinames['searchString']));
    curl_setopt($this->curlCh['gbif'], CURLOPT_RETURNTRANSFER, true);
    curl_setopt($this->curlCh['gbif'], CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($this->curlCh['gbif'], CURLOPT_SSL_VERIFYPEER, false);

}

private function wfo_setup(): void
{
    curl_setopt($this->curlCh['wfo'], CURLOPT_URL, "https://list.worldfloraonline.org/matching_rest.php?input_string=" . urlencode($this->scinames['searchString']));
    curl_setopt($this->curlCh['wfo'], CURLOPT_RETURNTRANSFER, true);
    curl_setopt($this->curlCh['wfo'], CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($this->curlCh['wfo'], CURLOPT_SSL_VERIFYPEER, false);

}

private function gbif_read(): void
{
    $curl_response = curl_multi_getcontent($this->curlCh['gbif']);
    $result = json_decode($curl_response, true);
    if (isset($result['count']) && $result['count'] > 0) {
        if ($result['count'] === 1) {
            $this->scinames['results']['gbif'] = array('match'      => array('id'    => $result['results'][0]['key'],
                                                                             'name'  => $result['results'][0]['scientificName']),
                                                       'candidates' => array());
        } else {
            $this->scinames['results']['gbif'] = array('match'      => null,
                                                       'candidates' => array());
            foreach ($result['results'] as $candidate) {
                $this->scinames['results']['gbif']['candidates'][] = array('id'   => $candidate['key'],
                                                                           'name' => $candidate['scientificName']);
            }
        }
    } else {
        $this->scinames['results']['gbif'] = array('match'      => null,
                                                   'candidates' => array());

    }
}

private function wfo_read(): void
{
    $curl_response = curl_multi_getcontent($this->curlCh['wfo']);
    $result = json_decode($curl_response, true);
    if (!empty($result['match'])) {
        $this->scinames['results']['wfo'] = array('match'      => array('id'    => $result['match']['wfo_id'],
                                                                        'name'  => $result['match']['full_name_plain']),
                                                  'candidates' => array());
    } elseif (!empty($result['candidates'])) {
        $this->scinames['results']['wfo'] = array('match'      => null,
                                                  'candidates' => array());
        foreach ($result['candidates'] as $candidate) {
            $this->scinames['results']['wfo']['candidates'][] = array('id'   => $candidate['wfo_id'],
                                                                      'name' => $candidate['full_name_plain']);
        }
    } else {
        $this->scinames['results']['wfo'] = array('match'      => null,
                                                  'candidates' => array());
    }
}

}
