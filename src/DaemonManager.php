<?php
namespace wapmorgan\SystemDaemon;

use Exception;

class DaemonManager
{
    public $restartTimeout = 30;

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
                    fwrite(STDERR, 'Daemon can\'t be launched. Reason: '.$e->getMessage().' ('.$e->getCode().')'.PHP_EOL);
                    fwrite(STDERR, $e->getTraceAsString().PHP_EOL);
                    exit(1);
                }
                break;

            case 'status':
                $status = $this->daemon->getStatus();
                if ($status === false)
                    fwrite(STDOUT, 'Daemon is not running.'.PHP_EOL);
                else {
                    fwrite(STDOUT, 'Daemon is running. Pid: '.$status->pid.PHP_EOL);
                }
                break;

            case 'stop':
                $status = $this->daemon->getStatus();
                if ($status === false) {
                    fwrite(STDOUT, 'Daemon is not running'.PHP_EOL);
                    break;
                }

                $result = $this->daemon->stop();
                if ($result === true) {
                    $timeout = time() + $this->restartTimeout;
                    while (time() < $timeout && $this->daemon->getStatus() !== false) {
                        sleep(1);
                    }

                    if ($this->daemon->getStatus() === false) {
                        fwrite(STDOUT, 'Successfully stopped' . PHP_EOL);
                        return true;
                    } else {
                        fwrite(STDERR, 'Stopping is failed'.PHP_EOL);
                        return false;
                    }
                } else if ($result === false) {
                    fwrite(STDOUT, 'Can\'t stop daemon.' . PHP_EOL);
                    return false;
                }
                break;

            case 'restart':
                $status = $this->daemon->getStatus();
                if ($status !== false)
                    $this->handleConsole(1, [__FILE__, 'stop']);

                $this->handleConsole(1, [__FILE__, 'start']);
                break;

            case 'kill':
                $result = $this->daemon->kill();
                if ($result === true)
                    fwrite(STDOUT, 'Successfully stopped'.PHP_EOL);
                else if ($result === false)
                    fwrite(STDOUT, 'Can\'t stop daemon.'.PHP_EOL);
                break;

            default:
                fwrite(STDOUT, 'Manager for daemon "'.$this->daemon->name.'". Available operations: '.$argv[0].' (start | status | stop | restart | kill)'.PHP_EOL);
                break;
        }
    }
}
