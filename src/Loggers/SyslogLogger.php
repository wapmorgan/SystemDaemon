<?php
namespace wapmorgan\SystemDaemon\Loggers;

use wapmorgan\SystemDaemon\AbstractDaemon;

class SyslogLogger
{
    static protected $syslogLevels = [
        AbstractDaemon::ERROR => LOG_ERR,
        AbstractDaemon::WARNING => LOG_WARNING,
        AbstractDaemon::NOTICE => LOG_NOTICE,
        AbstractDaemon::INFO => LOG_INFO,
        AbstractDaemon::DEBUG => LOG_DEBUG,
    ];

    public function log($level, $message)
    {
        $level = self::$syslogLevels[$level];
        syslog($level, $message);
    }
}
