<?php

abstract class Mapper
{
    /**
     * @var mysqli
     */
    protected mysqli $db;
    private string $serviceBaseUrl; // will be something like https://services.jacq.org/jacq-services/rest

    public function __construct(mysqli $db)
    {
        $this->db = $db;

        // should tls be used?
        if (   (($_SERVER['REQUEST_SCHEME'] ?? '') == 'https')
            || (($_SERVER['HTTPS'] ?? '') == 'on')
            || (($_SERVER['SERVER_PORT'] ?? '') == '443')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') == 'https')
            || (($_SERVER['SERVER_NAME'] ?? '') == 'services.jacq.org')
           ) {
            $server_request_scheme = 'https://';
        } else {
            $server_request_scheme = 'http://';
        }
        $this->serviceBaseUrl = $server_request_scheme . $_SERVER['SERVER_NAME'] . dirname(dirname($_SERVER['SCRIPT_NAME']));
    }

    /**
     * @return string
     */
    public function getServiceBaseUrl(): string
    {
        return $this->serviceBaseUrl;
    }
}
