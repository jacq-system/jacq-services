<?php

namespace Jacq\Input;

use mysqli;

class Organisation
{

private mysqli $db; // use this database
private int $id;    // primary key of organisation in tbl_organisation
private array $children;    // list of all children of organisation

public function __construct(mysqli $db, int $id)
{
    $this->db = $db;
    $this->id = $id;
}

public function getDescription(): string
{
    $row = $this->db->query("SELECT description 
                         FROM tbl_organisation 
                         WHERE id = $this->id")
                    ->fetch_assoc();
    return $row['description'] ?? '';
}

public function getAllChildren(): array
{
    if (empty($this->children)) {
        $this->children = $this->getChildren($this->id);
    }
    return $this->children;
}

// ---------------------------------------
// ---------- private functions ----------
// ---------------------------------------

private function getChildren(int $parentId): array
{
    $ret = array($parentId);
    $children = $this->db->query("SELECT id 
                                  FROM tbl_organisation 
                                  WHERE parent_organisation_id = $parentId")
                         ->fetch_all(MYSQLI_ASSOC);
    foreach ($children as $child) {
        $ret = array_merge($ret, $this->getChildren($child['id']));
    }
    return $ret;
}

}
