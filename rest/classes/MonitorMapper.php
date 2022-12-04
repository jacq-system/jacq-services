<?php
class MonitorMapper extends Mapper
{
    /**
     * @param string $from
     * @param string $to
     * @return array
     */
    public function getRecordingsForChart(string $from, string $to): array
    {
        $rows = $this->db->query("SELECT UNIX_TIMESTAMP(created_at) * 1000 AS uxtime, `used`, `committed`, `max`
                                 FROM `monitor`.`tbl_wildfly_local`
                                 WHERE DATE(created_at) >= '" . $this->formatDate($from) . "'
                                 " . ((!empty($to)) ? "AND DATE(created_at) <= '" . $this->formatDate($to) . "'" : '') . "
                                 ORDER BY created_at")
                         ->fetch_all(MYSQLI_ASSOC);

        $list = [];
        foreach ($rows as $row) {
            foreach (['used', 'committed', 'max'] as $item) {
                $list[$item][] = [$row['uxtime'], $row[$item] / 1024 / 1024];
            }
        }
        return $list;
    }



////////////////////////////// private functions //////////////////////////////

    /**
     * @param $date
     * @return string
     */
    private function formatDate($date): string
    {
        if (!$this->strContains($date, '-')) {
            return sprintf("%s-%s-%s", substr($date, 0, 4),
                (strlen($date > 4)) ? substr($date, 4, 2) : '01',
                (strlen($date) > 6) ? substr($date, 6, 2) : '01');
        } else {
            $parts = explode('-', $date);
            if (count($parts) > 2) {
                return $date;
            } else {
                return sprintf("%s-01", $date);
            }
        }
    }

    private function strContains($haystack, $needle)
    {
        return $needle !== '' && mb_strpos($haystack, $needle) !== false;
    }
}
