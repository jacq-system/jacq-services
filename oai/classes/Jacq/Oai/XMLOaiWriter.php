<?php

namespace Jacq\Oai;

use XMLWriter;

class XMLOaiWriter extends XMLWriter
{
    /**
     * write an element with the single attribute 'rdf:resource' and optional text
     *
     * @param string $elementName     name of the element
     * @param string $attributeValue  value of this attribute
     * @param string|null $text       optional text in element
     */
    public function writeElementWithResource(string $elementName, string $attributeValue, ?string $text = ''): void
    {
        $this->startElement($elementName);
        $this->writeAttribute('rdf:resource', $attributeValue);
        if (!empty($text)) {
            $this->text($text);
        }
        $this->endElement();

    }

    /**
     * write an element with the single attribute 'xml:lang' and optional text
     *
     * @param string $elementName name of the element
     * @param string $language    language-code (e.g. en)
     * @param string|null $text   optional text in element
     */
    public function writeElementWithLang(string $elementName, string $language, ?string $text = ''): void
    {
        $this->startElement($elementName);
        $this->writeAttribute('xml:lang', $language);
        if (!empty($text)) {
            $this->text($text);
        }
        $this->endElement();

    }

    /**
     * check if the value of an element with the single attribute 'rdf:resource' and an optional text is not empty and only writeElement if yes
     *
     * @param string $elementName name of the element
     * @param string $attributeValue value of this attribute
     * @param string|null $text optional text in element
     */
    public function writeNonemptyElementWithResource(string $elementName, string $attributeValue, ?string $text = ''): void
    {
        if (!empty($attributeValue)) {
            $this->writeElementWithResource($elementName, $attributeValue, $text);
        }
    }

    /**
     * check if the value of an element is not empty and only writeElement if yes
     *
     * @param string $elementName    name of the element
     * @param string $attributeValue value of the element
     */
    public function writeNonemptyElement(string $elementName, string $attributeValue): void
    {
        if (!empty($attributeValue)) {
            $this->writeElement($elementName, $attributeValue);
        }
    }

}
