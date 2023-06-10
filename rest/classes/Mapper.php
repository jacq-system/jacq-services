<?php

abstract class Mapper
{
    /**
     * @var mysqli
     */
    protected mysqli $db;
    protected string $serviceBaseUri = "https://services.jacq.org/jacq-services/rest";

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }
}
