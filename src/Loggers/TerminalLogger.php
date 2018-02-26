<?php
namespace wapmorgan\SystemDaemon\Loggers;

use wapmorgan\SystemDaemon\AbstractDaemon;

class TerminalLogger
{
    public function log($level, $message)
    {
        if (in_array($level, [AbstractDaemon::ERROR, AbstractDaemon::WARNING], true))
            $output = STDERR;
        else
            $output = STDOUT;

        fwrite($output, date('Y-m-d G:i:s').' '.$level.': '.$message.PHP_EOL);
    }
}
