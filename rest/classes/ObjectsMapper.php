<?php
class ObjectsMapper extends Mapper
{

public function getSpecimenData($specimenID)
{
    $specimen = new SpecimenMapper($this->db, intval($specimenID));

    return array_merge($specimen->getDC(), $specimen->getDWC());
}

public function getSpecimenDataWithValues($specimenID)
{
    $data = $this->getSpecimenData($specimenID);
    $result = array();
    foreach ($data as $key => $value) {
        if (!empty($value)) {
            $result[$key] = $value;
        }
    }
    return $result;
}

















}