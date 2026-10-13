<?php
declare(strict_types=1);
namespace Vereinskiste\Intake;

/** Fixed executables, no shell; no network, private mount namespace, resource/output limits. */
final class Sandbox
{
    public const BINARIES = ['/usr/bin/bwrap', '/usr/bin/prlimit', '/usr/bin/timeout', '/usr/bin/clamscan', '/usr/bin/tesseract', '/usr/bin/pdftoppm', '/usr/bin/pdfinfo'];

    public static function available(): bool
    {
        foreach (self::BINARIES as $path) { if (!is_executable($path)) { return false; } }
        return function_exists('proc_open') && is_dir('/var/lib/clamav');
    }

    public function run(array $command, string $directory, int $seconds = 20): array
    {
        if (!self::available() || !in_array($command[0] ?? '', self::BINARIES, true)) {
            throw new \RuntimeException('Local processing unavailable');
        }
        $argv = ['/usr/bin/timeout', '--signal=KILL', (string) $seconds,
            '/usr/bin/prlimit', '--as=2147483648', '--cpu=20', '--fsize=33554432', '--nofile=128', '--nproc=64', '--',
            '/usr/bin/bwrap', '--unshare-all', '--die-with-parent', '--new-session',
            '--ro-bind', '/usr', '/usr', '--proc', '/proc', '--dev', '/dev', '--tmpfs', '/tmp',
            '--bind', $directory, '/work', '--ro-bind', '/var/lib/clamav', '/signatures', '--chdir', '/work'];
        foreach (['/lib','/lib64'] as $root) { if (is_dir($root)) { array_push($argv, '--ro-bind', $root, $root); } }
        array_push($argv, '--', ...$command);
        $process = proc_open($argv, [0 => ['pipe','r'],1 => ['pipe','w'],2 => ['pipe','w']], $pipes, $directory,
            ['PATH' => '/usr/bin:/bin', 'OMP_THREAD_LIMIT' => '1', 'LANG' => 'C.UTF-8']);
        if (!is_resource($process)) { throw new \RuntimeException('Local processing unavailable'); }
        fclose($pipes[0]); stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
        $output = ''; $bytes = 0; $deadline = microtime(true) + $seconds + 3; $exit = -1;
        try {
            do {
                foreach ([1,2] as $i) {
                    $part = stream_get_contents($pipes[$i]); $bytes += strlen($part);
                    if ($i === 1) { $output .= $part; }
                }
                if ($bytes > 2097152 || microtime(true) > $deadline) { proc_terminate($process, 9); throw new \RuntimeException('Processing limit exceeded'); }
                $state = proc_get_status($process);
                if (!$state['running']) { $exit = $state['exitcode']; break; }
                usleep(20000);
            } while (true);
            $output .= stream_get_contents($pipes[1]);
        } finally { fclose($pipes[1]); fclose($pipes[2]); proc_close($process); }
        return ['code' => $exit, 'output' => $output];
    }
}
