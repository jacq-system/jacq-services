<!DOCTYPE html>
<html lang="en">
<head>
    <title>JACQ webservices</title>
    <meta charset="UTF-8">
    <link rel="shortcut icon" href="JACQ_LOGO.png"/>
    <link rel="stylesheet" type="text/css" href="../css/description.css">
</head>
<body>

<h1>JACQ Webservices</h1>

<h3><a href="../autocomplete/description.html">autocomplete</a></h3>
<table>
    <tr>
        <td class="type"><a href="../autocomplete/description.html#section_scientificNames">scientificNames</a></td>
        <td>Search for fitting scientific names and return them</td>
    </tr>
</table>

<h3><a href="../classification/description.html">classification</a></h3>
<table>
    <tr>
        <td class="type"><a href="../classification/description.html#section_references">references</a></td>
        <td>Fetch a list of all references (which have a classification attached) or get the name of a single reference</td>
    </tr><tr>
        <td class="type"><a href="../classification/description.html#section_nameReferences">nameReferences</a></td>
        <td>Return (other) references for this name which include them in their classification</td>
    </tr><tr>
        <td class="type"><a href="../classification/description.html#section_children">children</a></td>
        <td>get all citations which belong to the given periodical or get classification children of a given taxonID (or first generation if none given) according to a given citation</td>
    </tr><tr>
        <td class="type"><a href="../classification/description.html#section_synonyms">synonyms</a></td>
        <td>fetch synonyms (and basionym) for a given taxonID, according to a given citation</td>
    </tr>
</table>

<h3><a href="../iiif/description.html">iiif</a></h3>
<table>
    <tr>
        <td class="type"><a href="../iiif/description.html#section_manifestUri">manifestUri</a></td>
        <td>get the manifest URI for a given specimen-ID</td>
    </tr><tr>
        <td class="type"><a href="../iiif/description.html#section_manifest">manifest</a></td>
        <td>act as a proxy and get the manifest for a given specimen-ID from a backend, supplemented by some additional information.<br>
            If no backend is configured, the webservice returns HTTP 303 with the actual target-uri.
        </td>
    </tr>
</table>

<h3><a href="../objects/description.html">objects</a></h3>
<table>
    <tr>
        <td class="type"><a href="../objects/description.html#section_specimens">specimens</a></td>
        <td>get the properties of a specimen</td>
    </tr>
</table>

<h3><a href="../JACQscinames/description.html">scinames</a></h3>
<table>
    <tr>
        <td class="type"><a href="../JACQscinames/description.html#section_uuid">uuid</a></td>
        <td>Get uuid, uuid-url and scientific name of a given taxonID</td>
    </tr><tr>
        <td class="type"><a href="../JACQscinames/description.html#section_name">name</a></td>
        <td>Get scientific name, uuid and uuid-url of a given taxonID</td>
    </tr><tr>
        <td class="type"><a href="../JACQscinames/description.html#section_search">search</a></td>
        <td>do a fulltext search for scientific names and taxon names and also get their taxonIDs; all parts of the search term are mandatory for the search</td>
    </tr><tr>
        <td class="type"><a href="../JACQscinames/description.html#section_resolve">resolve</a></td>
        <td>Get scientific name, uuid-url and taxon-ID of a given uuid</td>
    </tr>
</table>

<h3><a href="../stableIdentifier/description.html">stable Identifier</a></h3>
<table>
    <tr>
        <td class="type"><a href="../stableIdentifier/description.html#section_sid">sid</a></td>
        <td>Get specimen-id, valid stable identifier and all stable identifiers of a given specimen-id</td>
    </tr><tr>
        <td class="type"><a href="../stableIdentifier/description.html#section_resolve">resolve</a></td>
        <td>Get specimen-id, valid stable identifier and all stable identifiers of a given stable identifier</td>
    </tr><tr>
        <td class="type"><a href="../stableIdentifier/description.html#section_errors">errors</a></td>
        <td>get a list of all errors which prevent the generation of stable identifiers</td>
    </tr><tr>
        <td class="type"><a href="../stableIdentifier/description.html#section_multi">multi</a></td>
        <td>Get all entries with more than one stable identifier per specimen-ID</td>
    </tr>
</table>

</body>
</html>
