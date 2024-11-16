<?php

putenv("ASAN_OPTIONS=exitcode=139");
putenv("SYMFONY_DEPRECATIONS_HELPER=max[total]=999");
putenv("PHPSECLIB_ALLOW_JIT=1");

function printMutex(string $result): void {
    flock(STDOUT, LOCK_EX);
    fwrite(STDOUT, $result.PHP_EOL);
    flock(STDOUT, LOCK_UN);
}

function e(string $cmd, string $extra = ''): string {
    exec("bash -c ".escapeshellarg("$cmd 2>&1"), $result, $code);
    $result = implode("\n", $result);
    if ($code) {
        printMutex("An error occurred while executing $cmd (status $code, extra info $extra): $result");
        die(1);
    }
    return $result;
}

$parallel = (int) ($argv[1] ?? 0);
$parallel = $parallel ?: ((int)`nproc`);
$parallel = $parallel ?: 8;

$repos = [];

$repos["psalm"] = [
    "https://github.com/vimeo/psalm",
    "master",
    null,
    function (): iterable {
        $it = new RecursiveDirectoryIterator("tests");
        /** @var SplFileInfo $file */
        foreach(new RecursiveIteratorIterator($it) as $file) {
            if ($file->getExtension() == 'php' && ctype_upper($file->getBasename()[0])) {
                yield [
                    getcwd()."/phpunit",
                    dirname($file->getRealPath()), 
                ];
            }
        }
    },
    1
];

$finalStatus = 0;
$parentPids = [];

$waitOne = function () use (&$finalStatus, &$parentPids): void {
    $res = pcntl_wait($status);
    if ($res === -1) {
        printMutex("An error occurred while waiting with waitpid!");
        $finalStatus = $finalStatus ?: 1;
        return;
    }
    if (!isset($parentPids[$res])) {
        printMutex("Unknown PID $res returned!");
        $finalStatus = $finalStatus ?: 1;
        return;
    }
    $desc = $parentPids[$res];
    unset($parentPids[$res]);
    if (pcntl_wifexited($status)) {
        $status = pcntl_wexitstatus($status);
        printMutex("Child task $desc exited with status $status");
        if ($status !== 0) {
            $finalStatus = $status;
        }
    } elseif (pcntl_wifstopped($status)) {
        $status = pcntl_wstopsig($status);
        printMutex("Child task $desc stopped by signal $status");
        $finalStatus = 1;
    } elseif (pcntl_wifsignaled($status)) {
        $status = pcntl_wtermsig($status);
        printMutex("Child task $desc terminated by signal $status");
        $finalStatus = 1;
    }
};

$waitAll = function () use ($waitOne, &$parentPids): void {
    while ($parentPids) {
        $waitOne();
    }
};

printMutex("Cloning repos...");

foreach ($repos as $dir => [$repo, $branch, $prepare, $command, $repeat]) {
    $pid = pcntl_fork();
    if ($pid) {
        $parentPids[$pid] = "clone $dir";
        continue;
    }

    chdir(sys_get_temp_dir());
    if ($branch) {
        $branch = "--branch $branch";
    }
    e("git clone $repo $branch --depth 1 $dir");
    
    exit(0);
}

$waitAll();

printMutex("Done cloning repos!");

printMutex("Preparing repos (max $parallel processes)...");
foreach ($repos as $dir => [$repo, $branch, $prepare, $command, $repeat]) {
    chdir(sys_get_temp_dir()."/$dir");
    $rev = e("git rev-parse HEAD", $dir);

    $pid = pcntl_fork();
    if ($pid) {
        $parentPids[$pid] = "prepare $dir ($rev)";
        if (count($parentPids) >= $parallel) {
            $waitOne();
        }
        continue;
    }

    e("composer i --ignore-platform-reqs", $dir);
    if ($prepare) {
        $prepare();
    }

    exit(0);
}
$waitAll();

printMutex("Done preparing repos!");

printMutex("Running tests (max $parallel processes)...");
foreach ($repos as $dir => [$repo, $branch, $prepare, $command, $repeat]) {
    chdir(sys_get_temp_dir()."/$dir");
    $rev = e("git rev-parse HEAD", $dir);

    if ($command instanceof Closure) {
        $commands = iterator_to_array($command());
    } else {
        $commands = [$command];
    }

    foreach ($commands as $idx => $cmd) {
        $cmd = array_merge([
            PHP_BINARY,
            '--repeat',
            $repeat,
            '-f',
            __DIR__.'/jit_check.php',
        ], $cmd);

        $cmdStr = implode(" ", $cmd);

        $pid = pcntl_fork();
        if ($pid) {
            $parentPids[$pid] = "test $dir ($rev): $cmdStr";
            if (count($parentPids) >= $parallel) {
                $waitOne();
            }
            continue;
        }

        $output = sys_get_temp_dir()."/out_{$dir}_$idx.txt";
        
        $p = proc_open($cmd, [
            ["pipe", "r"], 
            ["file", $output, "a"],
            ["file", $output, "a"]
        ], $pipes, sys_get_temp_dir()."/$dir");

        if ($p === false) {
            printMutex("Failure starting $cmdStr");
            exit(1);
        }
        
        $final = 0;
        $status = proc_close($p);
        if ($status !== 0) {
            if ($status > 128) {
                $final = $status;
            }
            printMutex(
                "$dir ($rev): $cmdStr terminated with status $status:".PHP_EOL
                .file_get_contents($output).PHP_EOL
            );
        }

        exit($final);
    }
}

$waitAll();

printMutex("All done!");

die($finalStatus);
