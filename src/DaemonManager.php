<?php
namespace wapmorgan\SystemDaemon;

use Exception;

class DaemonManager
{
    /**
     * @var AbstractDaemon Daemon
     */
    protected $daemon;

    public function __construct(AbstractDaemon $daemon)
    {
        $this->daemon = $daemon;
    }

    /**
     *
     */
    public function handleConsole($argc, array $argv)
    {
        switch (isset($argv[1]) ? $argv[1] : null)
        {
            case 'start':
                try {
                    $pid = $this->daemon->start();
                    fwrite(STDOUT, 'Successfully started. Pid is '.$pid.PHP_EOL);
                } catch (Exception $e) {
                    fwrite(STDERR, 'Daemon can\'t be launched. Reason: '.$e->getMessage().PHP_EOL);
                    exit(1);
                }
                break;

            case 'status':
                $status = $this->daemon->getStatus();
                if ($status === false)
                    fwrite(STDOUT, 'Daemon is not running.'.PHP_EOL);
                else {
                    echo 'Daemon is running. Pid: '.$status->pid.PHP_EOL;
                }
                break;

            case 'stop':
                $result = $this->daemon->stop();
                if ($result === true)
                    echo 'Successfully stopped'.PHP_EOL;
                else if ($result === false)
                    echo 'Can\'t stop daemon.'.PHP_EOL;
                break;

            case 'kill':
                $result = $this->daemon->kill();
                if ($result === true)
                    echo 'Successfully stopped'.PHP_EOL;
                else if ($result === false)
                    echo 'Can\'t stop daemon.'.PHP_EOL;
                break;

            default:
                echo 'Manager for daemon "'.$this->daemon->name.'". Available operations: '.$argv[0].' (start | status | stop | kill)'.PHP_EOL;
                break;
        }
    }
}
