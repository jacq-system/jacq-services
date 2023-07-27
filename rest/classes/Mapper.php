<?php

abstract class Mapper
{
    /**
     * @var mysqli
     */
    protected mysqli $db;
    private string $serviceBaseUrl = "https://services.jacq.org/jacq-services/rest";

    public function __construct(mysqli $db)
    {
        $this->db = $db;
//        $this->serviceBaseUrl = 'https://' . $_SERVER['SERVER_NAME'] . dirname(dirname($_SERVER['SCRIPT_NAME']));
    }

    /**
     * @return string
     */
    public function getServiceBaseUrl(): string
    {
        return $this->serviceBaseUrl;
    }
}
