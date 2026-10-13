<?php
declare(strict_types=1);
namespace Vereinskiste\Intake;

final class PollProcess
{
    public static function run(string $instance): void
    {
        if (!is_executable('/usr/bin/timeout') || !is_executable('/usr/bin/prlimit') || !function_exists('proc_open')) { throw new \RuntimeException('Polling unavailable'); }
        $script=dirname(__DIR__,2).'/intake-poll.php';
        $process=proc_open(['/usr/bin/timeout','--signal=KILL','40','/usr/bin/prlimit','--as=536870912','--cpu=20','--',PHP_BINARY,'-d','memory_limit=128M',$script,$instance],
            [0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes);
        if (!is_resource($process) || proc_close($process)!==0) { throw new \RuntimeException('Polling failed'); }
    }
}
