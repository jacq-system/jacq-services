<?php

namespace Jacq;

class Settings
{

/********************\
|                    |
|  static variables  |
|                    |
\********************/

private static Settings $instance;

/********************\
|                    |
|  static functions  |
|                    |
\********************/

/**
 * instances the class clsSettings
 *
 * @return Settings new instance of that class
 */
public static function Load(): Settings
{
    if (empty(self::$instance)) {
        self::$instance = new Settings();
    }
    return self::$instance;
}

/*************\
|             |
|  variables  |
|             |
\*************/

protected array $options = array();

/***************\
|               |
|  constructor  |
|               |
\***************/

/**
 * constructor of the class
 */
protected function __construct()
{
    include __DIR__ . "/../../inc/variables.php";
    $this->options = $_CONFIG;
}

/********************\
|                    |
|  public functions  |
|                    |
\********************/

/**
 * get a single or a group of settings
 *
 * @param string $level1 first level of keys
 * @param string $level2 second level of keys (optional)
 * @param string $level3 third level of keys (optional)
 * @return mixed single setting or array of settings
 */
public function get(string $level1, string $level2 = '', string $level3 = ''): mixed
{
    if ($level1) {
        if ($level2) {
            if ($level3) {
                return $this->options[$level1][$level2][$level3];
            } else {
                return $this->options[$level1][$level2];
            }
        } else {
            return $this->options[$level1];
        }
    } else {
        return '';
    }
}


/*********************\
|                     |
|  private functions  |
|                     |
\*********************/

/**
 * to prevent cloning of this singleton
 *
 */
private function __clone()
{
}

}
