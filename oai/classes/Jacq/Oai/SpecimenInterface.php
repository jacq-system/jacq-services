<?php

namespace Jacq\Oai;

interface SpecimenInterface
{
    public function getProperties();
    public function getProperty(string $property);
    public function getSpecimenID();
    public function isValid();
    public function getDescription();
    public function getDC();
    public function getEDM();
}
