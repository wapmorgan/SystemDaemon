<?php
namespace wapmorgan\SystemDaemon\Loggers;

class FilesLogger
{
    protected $log;

    public function __construct($logFile)
    {
        $this->log = fopen($logFile, 'a');
    }

    public function __destruct()
    {
        fclose($this->log);
    }

    public function log($level, $message)
    {
        fputs($this->log, date('Y-m-d G:i:s').' '.$level.': '.$message.PHP_EOL);
    }
}
