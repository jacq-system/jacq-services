<?php

namespace Jacq\Oai;

use DateTime;
use DateTimeZone;
use Exception;
use mysqli;
use XMLWriter;

class Response
{

private mysqli $db;
private array $params;
private bool $errorOccurred;
private string $baseURL = 'https://services.jacq.org/jacq-services/oai/';
private XMLWriter $xml;

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

    $this->xml = new XMLWriter();
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
 * @return XMLWriter the prepared xml als XMLWriter-class
 */
public function getXml(): XMLWriter
{
    return $this->xml;
}

// ---------------------------------------
// ---------- private functions ----------
// ---------------------------------------

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
        $this->xml->writeElement('adminEmail', 'office@ap4net.at');//'info@jacq.org');
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
    $this->xml->endElement();
}

/**
 * process the verb ListSets
 *
 * @return void
 */
private function listSets(): void
{
    // as we don't support sets, we return an error
    $this->error('noSetHierarchy', 'This repository does not support sets');
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
        $arguments['from'] = $this->params['from'] ?? '';
        $arguments['until'] = $this->params['until'] ?? '';
        $arguments['metadataPrefix'] = $this->params['metadataPrefix'] ?? '';
        $arguments['start'] = 0;
    }
    if ($arguments['metadataPrefix'] != 'oai_dc' && $arguments['metadataPrefix'] != 'oai_edm') {
        $this->error('cannotDisseminateFormat', "The metadata format '{$arguments['metadataPrefix']}' is not supported by this repository.");
    }
    if ($this->errorOccurred) {
        return;
    }

    $constraintParts = array();
    if ($arguments['from']) {
        $constraintParts[] = "aktualdatum >= '" . $this->changeTimeZone($arguments['from'], 'UTC', 'Europe/Vienna') . "'";
    }
    if ($arguments['until']) {
        $constraintParts[] = "aktualdatum <= '" . $this->changeTimeZone($arguments['until'], 'UTC', 'Europe/Vienna') . "'";
    }
    if (!empty($constraintParts)) {
        $constraint = " WHERE collectionID = 5 AND " . implode(' AND ', $constraintParts);
    } else {
        $constraint = " WHERE collectionID = 5 ";
    }
    $blocksize = ($identifiersOnly) ? 1000 : 20;
    $rows = $this->db->query("SELECT specimen_ID, aktualdatum
                              FROM tbl_specimens
                              $constraint
                              ORDER BY specimen_ID
                              LIMIT {$arguments['start']}, $blocksize")
                     ->fetch_all(MYSQLI_ASSOC);
    $numRows = $this->db->query("SELECT COUNT(*) FROM tbl_specimens $constraint")->fetch_row()[0];

    if ($numRows == 0) {
        $this->error('noRecordsMatch', "The combination of the given values results in an empty list.");
        return;
    }

    $this->request();
    if ($identifiersOnly) {
        $this->xml->startElement('ListIdentifiers');
        foreach ($rows as $row) {
            $this->xml->startElement('header');
            $this->xml->writeElement('identifier', "oai:jacq.org:{$row['specimen_ID']}");
            $this->xml->writeElement('datestamp', $this->changeTimeZone($row['aktualdatum'], 'Europe/Vienna', 'UTC'));
            $this->xml->endElement();
        }
    } else {
        $this->xml->startElement('ListRecords');
        foreach ($rows as $row) {
            $specimen = new SpecimenMapper($this->db, $row['specimen_ID']);
            $this->exportRecord($specimen, $arguments['metadataPrefix']);
        }
    }
    if ($numRows > $arguments['start'] + $blocksize) {
        $this->xml->startElement('resumptionToken');
            $this->xml->writeAttribute('cursor', $arguments['start']);
            $this->xml->writeAttribute('completeListSize', $numRows);
            $this->xml->text("start=" . ($arguments['start'] + $blocksize)
                            . (($arguments['from']) ? "|from={$arguments['from']}" : '')
                            . (($arguments['until']) ? "|until={$arguments['until']}" : '')
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
    }
    $specimen = new SpecimenMapper($this->db, intval(substr($this->params['identifier'], 13)));
    if (!str_starts_with($this->params['identifier'], 'oai:jacq.org:') || !$specimen->isValid()) {
        $this->error('idDoesNotExist', "The identifier '{$this->params['identifier']}' does not exist.");
        return;
    }
    $this->request();

    $this->xml->startElement('GetRecord');
        $this->exportRecord($specimen, $this->params['metadataPrefix']);
    $this->xml->endElement();
}

/**
 * do the actual xml-work for a given specimen
 *
 * @param SpecimenMapper $specimen a specimen represented ba a SpecimenMapper-Class
 * @param string $metadataPrefix the metadataPrefix that is to be used
 * @return void
 */
private function exportRecord(SpecimenMapper $specimen, string $metadataPrefix): void
{
    $this->xml->startElement('record');
        $this->xml->startElement('header');
            $this->xml->writeElement('identifier', "oai:jacq.org:{$specimen->getSpecimenID()}");
            $this->xml->writeElement('datestamp', $this->changeTimeZone($specimen->getProperty('aktualdatum'), 'Europe/Vienna', 'UTC'));
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
                $this->xml->startElement('oai_edm');
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
            } elseif (array_key_exists('set', $this->params)) {
                $this->error('noSetHierarchy', 'This repository does not support sets');
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
    $result = array('from' => '', 'until' => '', 'start' => 0, 'metadataPrefix' => '');
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
