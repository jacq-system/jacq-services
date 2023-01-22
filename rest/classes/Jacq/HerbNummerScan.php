<?php

namespace Jacq;

use mysqli;

class HerbNummerScan
{

private string $HerbNummer;
private int $source_id = 0;
private mysqli $db;

/**
 * find a new HerbNummer within a scantext which is either a stable-ID or a barcode-text
 * cut off any trailing non-numeric characters beforehand
 *
 * @param string $searchtext scantext
 */
public function __construct($db, string $searchtext)
{
    $this->db = $db;

    $row = $this->db->query("SELECT id, source_id, collectionID, `text`, HerbNummerConstruct, LENGTH(`text`) AS match_length
                             FROM scanHerbNummer 
                             WHERE `text` = SUBSTRING('" . $this->db->real_escape_string($searchtext) . "', 1, LENGTH(`text`))
                             ORDER BY match_length DESC")
                  ->fetch_assoc();
    if (empty($row)) {
        $this->HerbNummer = $searchtext;
    } else {
        preg_match("/^\d+/", substr($searchtext, $row['match_length']), $matches);  // cut off any trailing non-numeric characters
        $remainingText = $matches[0];
        $constructor = $this->findConstructor($row['HerbNummerConstruct'], strlen($remainingText));
        $this->HerbNummer = $this->generateHerbNummer($remainingText, $constructor);
        $this->source_id = $row['source_id'];
    }
}

/**
 * @return string
 */
public function getHerbNummer(): string
{
    return $this->HerbNummer;
}

public function getSourceId()
{
    return $this->source_id;
}

// ---------------------------------------
// ---------- private functions ----------
// ---------------------------------------

/**
 * generate a HerbNummer from a source according to the given constructor
 * every character of the constructor is used as it is. Exceptions are:
 * '%' ... the following digit gives the number of characters to get from source
 * '*' ... use all of the remaining characters of source. Must be the last character of the constructor
 *
 * @param string $source source of new HerbNummer
 * @param string $constructor construction instructions
 * @return string the final HerbNummer
 */
private function generateHerbNummer(string $source, string $constructor): string
{
    $target = '';
    $sptr = 0;
    for ($cptr = 0; $cptr < strlen($constructor); $cptr++) {
        if ($constructor[$cptr] == '%') {
            $target .= substr($source, $sptr, $constructor[++$cptr]);
            $sptr += (int)$constructor[$cptr];
        } elseif ($constructor[$cptr] == '*') {
            $target .= substr($source, $sptr);
            break;
        } else {
            $target .= $constructor[$cptr];
        }
    }

    return trim($target);
}

/**
 * Analyse the column HerbNummerConstruct from the database to get the actual constructor
 * special characters:
 * '|' ... several constructors may be seperated with it. If present, length constraints of each constructor must be given
 * '/' ... seperates a length constraint from the connected constructor. Constructor will be used, when the remaining text of the searchstring has this length
 * '*' ... stands for an arbitrary length. Must be present, when more than one constructor is given
 *
 * @param string $HerbNummerConstruct constructor, read directly from database
 * @param int $remainingTextLen length of the remaining text, which will be the source of the function generateHerbNummer
 * @return string the constructor to use
 */
private function findConstructor(string $HerbNummerConstruct, int $remainingTextLen): string
{
    if (strpos($HerbNummerConstruct, '|') !== false) {
        $constructors_raw = explode('|', $HerbNummerConstruct);
        $constructor_universal = $constructor = '';
        foreach ($constructors_raw as $item) {
            $parts = explode('/', $item, 2);
            if ($parts[0] == '*') {
                $constructor_universal = $parts[1];
            } else {
                if ($remainingTextLen == $parts[0]) {
                    $constructor = $parts[1];
                }
            }
        }
        if (empty($constructor)) {
            $constructor = $constructor_universal;
        }
    } else {
        $constructor = $HerbNummerConstruct;
    }

    return $constructor;
}

}
