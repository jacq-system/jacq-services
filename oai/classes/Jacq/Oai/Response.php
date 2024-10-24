<?php

namespace Jacq\Oai;

use DateTime;
use DateTimeZone;
use Exception;
use mysqli;

class Response
{

private mysqli $db;
private array $params;
private bool $errorOccurred;
private string $baseURL = 'https://services.jacq.org/jacq-services/oai/';
private string $identifierPrefixJacq = "oai:jacq.org:";
private array $setsAllowed = [1, 4, 5, 6];
private array $setsAllowedGbif = [10001, 10002];
private XMLOaiWriter $xml;

/**
 * class constructor. Validate all parameters and prepare the xml.
 *
 * @param mysqli $db instance of mysqli-database
 * @param array $params all parameters extracted either from $_GET or $_POST
 */
public function __construct(mysqli $db, array $params)
{
    $this->db            = $db;
    $this->params        = $params;
    $this->errorOccurred = false;

    $this->xml = new XMLOaiWriter();
    $this->xml->openMemory();
    $this->xml->setIndent(true);
    $this->xml->setIndentString('    ');
    $this->xml->startDocument('1.0', 'UTF-8');
    $this->xml->startElement('OAI-PMH');
        $this->xml->writeAttribute('xmlns', 'http://www.openarchives.org/OAI/2.0/');
        $this->xml->writeAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $this->xml->writeAttribute('xsi:schemaLocation', 'http://www.openarchives.org/OAI/2.0/ http://www.openarchives.org/OAI/2.0/OAI-PMH.xsd');
        $this->xml->writeElement('responseDate', gmdate('Y-m-d\TH:i:sp'));
        if ($this->validateParameters()) {
            switch ($this->params['verb']) {
                case 'Identify':
                    $this->identify();
                    break;
                case 'ListMetadataFormats':
                    $this->listMetadataFormats();
                    break;
                case 'ListSets':
                    $this->listSets();
                    break;
                case 'ListIdentifiers':
                    $this->listIdentifiersRecords(true);
                    break;
                case 'ListRecords':
                    $this->listIdentifiersRecords();
                    break;
                case 'GetRecord':
                    $this->getRecord();
                    break;
            }
        }
    $this->xml->endElement();
    $this->xml->endDocument();
}

/**
 * return the prepared xml
 *
 * @return XMLOaiWriter the prepared xml als XMLWriter-class
 */
public function getXml(): XMLOaiWriter
{
    return $this->xml;
}

// ------------------------------------
// ---------- verb functions ----------
// ------------------------------------

/**
 * process the verb Identify
 *
 * @return void
 */
private function identify(): void
{
    $this->request();

    $this->xml->startElement('Identify');
        $this->xml->writeElement('repositoryName', 'JACQ');
        $this->xml->writeElement('baseURL', $this->baseURL);
        $this->xml->writeElement('protocolVersion', '2.0');
        $this->xml->writeElement('earliestDatestamp', '2004-11-01T00:00:00Z');
        $this->xml->writeElement('deletedRecord', 'no');
        $this->xml->writeElement('granularity', 'YYYY-MM-DDThh:mm:ssZ');
        $this->xml->writeElement('adminEmail', 'info@jacq.org');
        $this->xml->writeElement('adminEmail', 'office@ap4net.at');
    $this->xml->endElement();
}

/**
 * process the verb ListMetadataFormats
 *
 * @return void
 */
private function listMetadataFormats(): void
{
    $this->request();

    $this->xml->startElement('ListMetadataFormats');
        $this->xml->startElement('metadataFormat');
            $this->xml->writeElement('metadataPrefix', 'oai_dc');
            $this->xml->writeElement('schema', 'http://www.openarchives.org/OAI/2.0/oai_dc.xsd');
            $this->xml->writeElement('metadataNamespace', 'http://www.openarchives.org/OAI/2.0/oai_dc/');
        $this->xml->endElement();
        $this->xml->startElement('metadataFormat');
            $this->xml->writeElement('metadataPrefix', 'oai_edm');
            $this->xml->writeElement('schema', 'http://gams.uni-graz.at/edm/2017-08/EDM.xsd');
            $this->xml->writeElement('metadataNamespace', 'http://www.europeana.eu/schemas/edm/');
        $this->xml->endElement();
    $this->xml->endElement();
}

/**
 * process the verb ListSets
 *
 * @return void
 */
private function listSets(): void
{
    $sets = $this->db->query("SELECT MetadataID, OwnerOrganizationName 
                               FROM metadata
                               WHERE MetadataID IN (" . implode(',', $this->setsAllowed) . ")
                              UNION
                              SELECT source_id AS MetadataID, OwnerOrganizationName 
                               FROM gbif_cache.sources
                               WHERE source_id IN (" . implode(',', $this->setsAllowedGbif) . ")
                              ORDER BY MetadataID")
                     ->fetch_all(MYSQLI_ASSOC);

    $this->request();

    $this->xml->startElement('ListSets');
    foreach ($sets as $set) {
        $this->xml->startElement('set');
            $this->xml->writeElement('setSpec', "source_{$set['MetadataID']}");
            $this->xml->writeElement('setName', $set['OwnerOrganizationName']);
        $this->xml->endElement();
    }
    $this->xml->endElement();
}

/**
 * process the verbs ListIdentifiers and ListRecords
 *
 * @param bool $identifiersOnly
 * @return void
 */
private function listIdentifiersRecords(bool $identifiersOnly = false): void
{
    if (!empty($this->params['resumptionToken'])) {
        $arguments = $this->parseResumptionToken();
    } else {
        if (isset($this->params['from']) && !preg_match("/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z/", $this->params['from'])) {
            $this->error('badArgument', "The from format of '{$this->params['from']}' is wrong.");
        }
        if (isset($this->params['until']) && !preg_match("/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z/", $this->params['until'])) {
            $this->error('badArgument', "The until format of '{$this->params['until']}' is wrong.");
        }
        if (isset($this->params['set'])) {
            if ($this->params['set'] == 'null') {   // metis-sandbox.europeana.eu sometimes sends this, if no set is chosen
                unset($this->params['set']);
            } elseif (!preg_match("/^source_\d+$/", $this->params['set'])) {
                $this->error('badArgument', "The set format of '{$this->params['set']}' is wrong.");
            }
        }
        $arguments['from'] = $this->params['from'] ?? '';
        $arguments['until'] = $this->params['until'] ?? '';
        $arguments['set'] = $this->params['set'] ?? '';
        $arguments['metadataPrefix'] = $this->params['metadataPrefix'] ?? '';
        $arguments['start'] = 0;
        $arguments['off'] = 0;
    }
    if ($arguments['metadataPrefix'] != 'oai_dc' && $arguments['metadataPrefix'] != 'oai_edm') {
        $this->error('cannotDisseminateFormat', "The metadata format '{$arguments['metadataPrefix']}' is not supported by this repository.");
    }
    if ($this->errorOccurred) {
        return;
    }

    $constraint = '';
    if ($arguments['from']) {
        $constraint .= " AND s.aktualdatum >= '" . $this->changeTimeZone($arguments['from'], 'UTC', 'Europe/Vienna') . "'";
    }
    if ($arguments['until']) {
        $constraint .= " AND s.aktualdatum <= '" . $this->changeTimeZone($arguments['until'], 'UTC', 'Europe/Vienna') . "'";
    }
    if ($arguments['set']) {
        $constraintSourceG = " AND s.source_id = " . intval(substr($arguments['set'], strlen('source_')));
        $constraintSourceJ = " AND mc.source_id = " . intval(substr($arguments['set'], strlen('source_')));
    } else {
        $constraintSourceG = $constraintSourceJ = '';
    }
    $blocksize = ($identifiersOnly) ? 1000 : 100;

    if ($arguments['off'] == 0) {
        $rows = $this->db->query("SELECT s.specimen_ID, s.aktualdatum, mc.source_id
                                  FROM tbl_specimens s
                                   JOIN (SELECT specimen_ID
                                         FROM tbl_specimens_stblid
                                         WHERE visible = 1
                                         GROUP BY specimen_ID) ss ON ss.specimen_ID = s.specimen_ID
                                   JOIN tbl_management_collections mc ON mc.collectionID = s.collectionID
                                  WHERE s.`accessible` = 1
                                   AND s.`digital_image` = 1
                                   AND s.HerbNummer IS NOT NULL
                                   AND mc.source_id IN (" . implode(',', $this->setsAllowed) . ")
                                   $constraint
                                   $constraintSourceJ
                                  LIMIT {$arguments['start']}, $blocksize")
                         ->fetch_all(MYSQLI_ASSOC);
        if (count($rows) < $blocksize) {
            $startG = 0;
            $limitG = $blocksize - count($rows);
            $offset = $arguments['start'] + count($rows);
        } else {
            $startG = $limitG = $offset = 0;
        }
    } else {
        $rows = array();
        $startG = $arguments['start'] - $arguments['off'];
        $limitG = $blocksize;
        $offset = $arguments['off'];
    }
    if ($limitG) {
        $rowsG = $this->db->query("SELECT s.specimen_ID, s.aktualdatum, s.source_id
                                   FROM gbif_cache.specimens s
                                   WHERE s.source_id IN (" . implode(',', $this->setsAllowedGbif) . ")
                                    $constraint
                                    $constraintSourceG
                                   LIMIT $startG, $limitG")
                          ->fetch_all(MYSQLI_ASSOC);
        $rows = array_merge($rows, $rowsG);
    }

    if (count($rows) == 0) {
        $this->error('noRecordsMatch', "The combination of the given values results in an empty list.");
        return;
    }

    $this->request();
    if ($identifiersOnly) {
        $this->xml->startElement('ListIdentifiers');
        foreach ($rows as $row) {
            $this->xml->startElement('header');
                $this->xml->writeElement('identifier', $this->identifierPrefixJacq . (($row['source_id'] > 10000) ? 'g' : '') . $row['specimen_ID']);
                $this->xml->writeElement('datestamp', $this->changeTimeZone($row['aktualdatum'], 'Europe/Vienna', 'UTC'));
                $this->xml->writeElement('setSpec', "source_{$row['source_id']}");
            $this->xml->endElement();
        }
    } else {
        $this->xml->startElement('ListRecords');
        foreach ($rows as $row) {
            if ($row['source_id'] > 10000) {
                $specimen = new SpecimenGbifMapper($this->db, $row['specimen_ID']);
            } else {
                $specimen = new SpecimenMapper($this->db, $row['specimen_ID']);
            }
            if ($specimen->isValid()) {
                $this->exportRecord($specimen, $arguments['metadataPrefix']);
            }
        }
    }
    if (count($rows) == $blocksize) {
        $this->xml->startElement('resumptionToken');
            $this->xml->writeAttribute('cursor', $arguments['start']);
            $this->xml->text("start=" . ($arguments['start'] + $blocksize)
                            . (($arguments['from']) ? "|from={$arguments['from']}" : '')
                            . (($arguments['until']) ? "|until={$arguments['until']}" : '')
                            . (($arguments['set']) ? "|set={$arguments['set']}" : '')
                            . (($offset) ? "|off=$offset" : '')
                            . "|metadataPrefix={$arguments['metadataPrefix']}");
        $this->xml->endElement();
    }
    $this->xml->endElement();
}

/**
* process the verb GetRecord
*
* @return void
*/
private function getRecord(): void
{
    if ($this->params['metadataPrefix'] != 'oai_dc' && $this->params['metadataPrefix'] != 'oai_edm') {
        $this->error('cannotDisseminateFormat', "The metadata format '{$this->params['metadataPrefix']}' is not supported by this repository.");
        return;
    }
    $id = substr($this->params['identifier'], strlen($this->identifierPrefixJacq));
    if (substr($id, 0, 1) == 'g') {
        $specimen = new SpecimenGbifMapper($this->db, intval(substr($id, 1)));
    } else {
        $specimen = new SpecimenMapper($this->db, intval($id));
    }
    if (!str_starts_with($this->params['identifier'], $this->identifierPrefixJacq) || !$specimen->isValid()) {
        $this->error('idDoesNotExist', "The identifier '{$this->params['identifier']}' does not exist.");
        return;
    }
    $this->request();

    $this->xml->startElement('GetRecord');
        $this->exportRecord($specimen, $this->params['metadataPrefix']);
    $this->xml->endElement();
}

// ---------------------------------------
// ---------- private functions ----------
// ---------------------------------------

/**
 * do the actual xml-work for a given specimen
 *
 * @param SpecimenInterface $specimen a specimen represented ba a SpecimenMapper- or SpecimenGbifMapper-Class
 * @param string $metadataPrefix the metadataPrefix that is to be used
 * @return void
 */
private function exportRecord(SpecimenInterface $specimen, string $metadataPrefix): void
{
    $this->xml->startElement('record');
        $this->xml->startElement('header');
            $this->xml->writeElement('identifier', $this->identifierPrefixJacq . $specimen->getSpecimenID());
            $this->xml->writeElement('datestamp', $this->changeTimeZone($specimen->getProperty('aktualdatum'), 'Europe/Vienna', 'UTC'));
            $this->xml->writeElement('setSpec', "source_" . $specimen->getProperty('source_id'));
        $this->xml->endElement();
        $this->xml->startElement('metadata');
            if ($metadataPrefix == 'oai_dc') {
                $this->xml->startElement('oai_dc:dc');
                    $this->xml->writeAttribute('xmlns:oai_dc', 'http://www.openarchives.org/OAI/2.0/oai_dc/');
                    $this->xml->writeAttribute('xmlns:dc', 'http://purl.org/dc/elements/1.1/');
                    $this->xml->writeAttribute('xmlns:xsi', 'http://www.openarchives.org/OAI/2.0/oai_dc/');
                    foreach ($specimen->getDC() as $key => $value) {
                        if (is_array($value)) {
                            foreach ($value as $item) {
                                if (!empty($item)) {
                                    $this->xml->writeElement($key, $item);
                                }
                            }
                        } else {
                            if (!empty($value)) {
                                $this->xml->writeElement($key, $value);
                            }
                        }
                    }
                $this->xml->endElement();
            } elseif ($metadataPrefix == 'oai_edm') {
                $specimenEdm = $specimen->getEdm();
                $this->xml->startElement('rdf:RDF');
                    $this->xml->writeAttribute('xmlns:rdf', 'http://www.w3.org/1999/02/22-rdf-syntax-ns#');
                    $this->xml->writeAttribute('xmlns:ore', 'http://www.openarchives.org/ore/terms/');
                    $this->xml->writeAttribute('xmlns:rdaGr2', 'http://rdvocab.info/ElementsGr2/');
                    $this->xml->writeAttribute('xmlns:oai', 'http://www.openarchives.org/OAI/2.0/');
                    $this->xml->writeAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
                    $this->xml->writeAttribute('xmlns:xs', 'http://www.w3.org/2001/XMLSchema');
                    $this->xml->writeAttribute('xmlns:wgs84_pos', 'http://www.w3.org/2003/01/geo/wgs84_pos#');
                    $this->xml->writeAttribute('xmlns:dcterms', 'http://purl.org/dc/terms/');
                    $this->xml->writeAttribute('xmlns:t', 'http://www.tei-c.org/ns/1.0');
                    $this->xml->writeAttribute('xmlns:oai_dc', 'http://www.openarchives.org/OAI/2.0/oai_dc/');
                    $this->xml->writeAttribute('xmlns:edm', 'http://www.europeana.eu/schemas/edm/');
                    $this->xml->writeAttribute('xmlns:owl', 'http://www.w3.org/2002/07/owl#');
                    $this->xml->writeAttribute('xmlns:skos', 'http://www.w3.org/2004/02/skos/core#');
                    $this->xml->writeAttribute('xmlns:svcs', 'http://rdfs.org/sioc/services#');
                    $this->xml->writeAttribute('xmlns:lido', 'http://www.lido-schema.org');
                    $this->xml->writeAttribute('xmlns:dc', 'http://purl.org/dc/elements/1.1/');
                    $this->xml->writeAttribute('xmlns:doap', 'http://usefulinc.com/ns/doap#');
                    $this->xml->writeAttribute('xmlns:europeana', 'http://www.europeana.eu/schemas/ese/');
                    $this->xml->writeAttribute('xsi:schemaLocation', 'http://www.w3.org/1999/02/22-rdf-syntax-ns# https://gams.uni-graz.at/edm/2017-08/EDM.xsd');
                    $this->xml->startElement('ore:Aggregation');
                        $this->xml->writeAttribute('rdf:about', $specimenEdm['ore:Aggregation']['rdf:about']);
                        $this->xml->writeElementWithResource('edm:aggregatedCHO', $specimenEdm['ore:Aggregation']['edm:aggregatedCHO']);
                        $this->xml->writeElement('edm:dataProvider', $specimenEdm['ore:Aggregation']['edm:dataProvider']);
                        $this->xml->writeElementWithResource('edm:isShownAt', $specimenEdm['ore:Aggregation']['edm:isShownAt']);
                        $this->xml->writeElementWithResource('edm:isShownBy', $specimenEdm['ore:Aggregation']['edm:isShownBy']);
                        $this->xml->writeElement('edm:provider', 'Kulturpool');
                        $this->xml->writeElementWithResource('edm:rights', $specimenEdm['ore:Aggregation']['edm:rights']);
                        $this->xml->writeNonemptyElementWithResource('edm:object', $specimenEdm['ore:Aggregation']['edm:object']);
                        if (!empty($specimenEdm['ore:Aggregation']['edm:hasView'])) {
                            foreach ($specimenEdm['ore:Aggregation']['edm:hasView'] as $view) {
                                $this->xml->writeElementWithResource('edm:hasView', $view);
                            }
                        }
                    $this->xml->endElement();
                    $this->xml->startElement('edm:ProvidedCHO');
                        $this->xml->writeAttribute('rdf:about', $specimenEdm['edm:ProvidedCHO']['rdf:about']);
                        $this->xml->writeElement('dc:title', $specimenEdm['edm:ProvidedCHO']['dc:title']);
                        $this->xml->writeElementWithLang('dc:description', 'en', $specimenEdm['edm:ProvidedCHO']['dc:description']);
                        $this->xml->writeElement('dc:identifier', $specimenEdm['edm:ProvidedCHO']['dc:identifier']);
                        $this->xml->writeElement('dc:language', $specimenEdm['edm:ProvidedCHO']['dc:language']);
                        $this->xml->writeElement('edm:type', $specimenEdm['edm:ProvidedCHO']['edm:type']);
                        $this->xml->writeElementWithResource('dc:type', $specimenEdm['edm:ProvidedCHO']['dc:type']);
                        $this->xml->writeNonemptyElement('dcterms:spatial', $specimenEdm['edm:ProvidedCHO']['dcterms:spatial']);
                        $this->xml->writeNonemptyElement('dc:date', $specimenEdm['edm:ProvidedCHO']['dc:date']);
                        $this->xml->writeNonemptyElement('dc:creator', $specimenEdm['edm:ProvidedCHO']['dc:creator']);
                    $this->xml->endElement();
                    foreach ($specimenEdm['edm:WebResource'] as $webResource) {
                        $this->xml->startElement('edm:WebResource');
                            $this->xml->writeAttribute('rdf:about', $webResource['rdf:about']);
                            $this->xml->writeNonemptyElement('dc:rights', $webResource['dc:rights']);
                            $this->xml->writeNonemptyElementWithResource('edm:rights', $webResource['edm:rights']);
                            $this->xml->writeNonemptyElement('dc:type', $webResource['dc:type']);
                        $this->xml->endElement();
                    }
                    if ($specimenEdm['edm:ProvidedCHO']['dc:type'] == 'http://rs.tdwg.org/dwc/terms/PreservedSpecimen') {
                        $this->xml->startElement('skos:Concept');
                            $this->xml->writeAttribute('rdf:about', 'http://rs.tdwg.org/dwc/terms/PreservedSpecimen');
                            $this->xml->writeElementWithLang('skos:prefLabel', 'en', 'Preserved Specimen');
                            $this->xml->writeElementWithLang('skos:altLabel', 'en', 'Preserved Specimen');
                        $this->xml->endElement();
                    }
                    if ($specimenEdm['edm:ProvidedCHO']['dc:type'] == 'http://rs.tdwg.org/dwc/terms/HumanObservation') {
                        $this->xml->startElement('skos:Concept');
                            $this->xml->writeAttribute('rdf:about', 'http://rs.tdwg.org/dwc/terms/HumanObservation');
                            $this->xml->writeElementWithLang('skos:prefLabel', 'en', 'Human Observation');
                            $this->xml->writeElementWithLang('skos:altLabel', 'en', 'Human Observation');
                        $this->xml->endElement();
                    }
                $this->xml->endElement();
            }
        $this->xml->endElement();
    $this->xml->endElement();
}

/**
 * format a correct request-Element
 *
 * @param bool $baseUrlOnly when true, show no attributes at all, since at least one error was found
 * @return void
 */
private function request(bool $baseUrlOnly = false): void
{
    $this->xml->startElement('request');
    if (!$baseUrlOnly) {
        foreach ($this->params as $key => $value) {
            if (!empty($value)) {
                $this->xml->writeAttribute($key, $value);
            }
        }
    }
    $this->xml->text($this->baseURL);
    $this->xml->endElement();
}

/**
 * validate all given parameters according to the verb
 *
 * @return bool everything ok?
 */
private function validateParameters(): bool
{
    if (isset($this->params['verb'])) {
        switch ($this->params['verb']) {
            case 'Identify':
            case 'ListSets':
                $this->checkArguments();
                break;
            case 'ListMetadataFormats':
                $this->checkArguments(['identifier']);
                break;
            case 'ListIdentifiers':
            case 'ListRecords':
                if (isset($this->params['resumptionToken'])) {
                    if (count($this->params) > 2) {
                        $this->error('badArgument', 'The usage of resumptionToken as an argument allows no other arguments.');
                    }
                } elseif (!array_key_exists('metadataPrefix', $this->params)) {
                    $this->error('badArgument', "The required argument 'metadataPrefix' is missing in the request.");
                } else {
                    $this->checkArguments(['from', 'until', 'metadataPrefix', 'set']);
                }
                break;
            case 'GetRecord':
                if (!array_key_exists('identifier', $this->params)) {
                    $this->error('badArgument', "The required argument 'identifier' is missing in the request.");
                }
                if (!array_key_exists('metadataPrefix', $this->params)) {
                    $this->error('badArgument', "The required argument 'metadataPrefix' is missing in the request.");
                }
                $this->checkArguments(['identifier', 'metadataPrefix']);
                break;
            default:
                $this->error('badVerb', "The verb '{$this->params['verb']}' provided in the request is illegal.");
        }
    } else {
        $this->error('badVerb', "The verb is missing in the request.");
    }

    return !$this->errorOccurred;
}

/**
 * check if there is a parameter which is no valid argument
 *
 * @param array $allowedList give a list of allowed arguments in addition to 'verb'
 * @return void
 */
private function checkArguments(array $allowedList = array()): void
{
    // check if any additional parameters are given
    foreach ($this->params as $key => $value) {
        if ($key != 'verb' && !(in_array($key, $allowedList))) {
            $this->error('badArgument', "The argument '$key' (value='" . htmlspecialchars($value) . "') included in the request is not valid.");
        }
    }
}

/**
 * parse a resumptionToken and return any found errors
 *
 * @return array the recognized parts of the token
 */
private function parseResumptionToken(): array
{
    $result = array('from' => '', 'until' => '', 'start' => 0, 'metadataPrefix' => '', 'set' => '', 'off' => 0);
    foreach (explode('|', $this->params['resumptionToken']) as $chunk) {
        $tokenParam = explode("=", $chunk);
        if (count($tokenParam) == 2) {
            switch ($tokenParam[0]) {
                case 'from':
                    if (preg_match("/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z/", $tokenParam[1])) {
                        $result['from'] = $tokenParam[1];
                    } else {
                        $this->error('badResumptionToken', "The resumptionToken '{$this->params['resumptionToken']}' is faulty.");
                    }
                    break;
                case 'until':
                    if (preg_match("/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z/", $tokenParam[1])) {
                        $result['until'] = $tokenParam[1];
                    } else {
                        $this->error('badResumptionToken', "The resumptionToken '{$this->params['resumptionToken']}' is faulty.");
                    }
                    break;
                case 'start':
                    if (intval($tokenParam[1]) > 0) {
                        $result['start'] = intval($tokenParam[1]);
                    } else {
                        $this->error('badResumptionToken', "The resumptionToken '{$this->params['resumptionToken']}' is faulty.");
                    }
                    break;
                case 'metadataPrefix':
                    if ($tokenParam[1] == 'oai_dc' || $tokenParam[1] == 'oai_edm') {
                        $result['metadataPrefix'] = $tokenParam[1];
                    } else {
                        $this->error('badResumptionToken', "The resumptionToken '{$this->params['resumptionToken']}' is faulty.");
                    }
                    break;
                case 'set':
                    if (preg_match("/^source_\d+$/", $tokenParam[1])) {
                        $result['set'] = $tokenParam[1];
                    } elseif ($tokenParam[1] != 'null') { // metis-sandbox.europeana.eu sometimes sends this, if no set is chosen
                        $this->error('badResumptionToken', "The resumptionToken '{$this->params['resumptionToken']}' is faulty.");
                    }
                    break;
                case 'off':
                    if (intval($tokenParam[1]) > 0) {
                        $result['off'] = intval($tokenParam[1]);
                    }
                    break;
                default:
                    $this->error('badResumptionToken', "The resumptionToken '{$this->params['resumptionToken']}' is faulty.");
            }
        } else {
            $this->error('badResumptionToken', "The resumptionToken '{$this->params['resumptionToken']}' is faulty.");
        }
    }

    return $result;
}

/**
 * an error was found, so include it in the xml
 *
 * @param string $code the error-code to include
 * @param string $text the error-message to include
 * @return void
 */
private function error(string $code, string $text): void
{
    if (!$this->errorOccurred) {
        $this->request(true);
    }
    $this->errorOccurred = true;
    $this->xml->startElement('error');
        $this->xml->writeAttribute('code', $code);
        $this->xml->text($text);
    $this->xml->endElement();
}

/**
 * as the server uses CEST and not UTC we have to convert the used timestamps
 *
 * @param string $dateString the date to convert
 * @param string $timeZoneSource from this timezone
 * @param string $timeZoneTarget to this one
 * @return string the converted date
 */
private function changeTimeZone(string $dateString, string $timeZoneSource, string $timeZoneTarget): string
{
    try {
        $dt = new DateTime($dateString, new DateTimeZone($timeZoneSource));
        $dt->setTimezone(new DateTimeZone($timeZoneTarget));

        if ($timeZoneTarget == 'UTC') {
            $result = $dt->format("Y-m-d\TH:i:sp");
        } else {
            $result = $dt->format("Y-m-d H:i:s");
        }
    } catch (Exception $e) {
        $result = '';
        error_log($e->getMessage());
    }
    return $result;
}

}
