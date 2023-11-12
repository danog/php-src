<?php

$queueF = __DIR__.'/queue';
$queue = json_decode(file_exists($queueF) ? file_get_contents($queueF) : '[]', true);

if ($argv[1] === 'run') {
    $pids = [];
    $idx = 0;
    foreach ($queue as [$cwd, $cmd]) {
        $cmdStr = implode(" ", $cmd);
        echo "Starting $cmdStr...".PHP_EOL;
        $p = proc_open($cmd, [
            ["pipe", "r"], 
            ["file", sys_get_temp_dir()."/out_$idx.txt", "a"],
            ["file", sys_get_temp_dir()."/out_$idx.txt", "a"]
        ], $pipes, $cwd);
        if ($p === false) {
            echo "Failure starting $cmdStr".PHP_EOL;
            exit(1);
        }
        $pids[$cmdStr] = [$p, $idx, $cwd];
        $idx++;
    }

    $final = 0;
    foreach ($pids as $cmd => [$p, $idx, $cwd]) {
        $status = proc_close($p);
        if ($status > 128) {
            $final = $status;
            echo "$cwd: $cmd terminated with status $status".PHP_EOL;
            chdir($cwd);
            echo "git rev-parse HEAD: ".`git rev-parse HEAD`.PHP_EOL;
            echo file_get_contents(sys_get_temp_dir()."/out_$idx.txt").PHP_EOL;
        }
    }
    exit($final);
}

$cmd = array_slice($argv, 1);
$cmd[0] = realpath($cmd[0]);

$queue []= [getcwd(), $cmd];
file_put_contents($queueF, json_encode($queue));