<?php
class ObjectsMapper extends Mapper
{

public function getSpecimenData($specimenID)
{
    $specimen = new SpecimenMapper($this->db, intval($specimenID));

//    return array_merge($specimen->getDC(), $specimen->getDWC(), $specimen->getJACQ());
    return array("DC"   => $specimen->getDC(),
                 "DWC"  => $specimen->getDWC(),
                 "JACQ" => $specimen->getJACQ());
}

public function getSpecimenDataWithValues($specimenID)
{
    $data = $this->getSpecimenData($specimenID);
    $result = array();
    foreach ($data as $format => $group) {
        foreach ($group as $key => $value) {
            if (!empty($value)) {
                $result[$format][$key] = $value;
            }
        }
    }
    return $result;
}

















}