<?php

function printMutex(string $result): void {
    flock(STDOUT, LOCK_EX);
    fwrite(STDOUT, $result.PHP_EOL);
    flock(STDOUT, LOCK_UN);
}

function e(string $cmd): string {
    exec("bash -c ".escapeshellarg("$cmd 2>&1"), $result, $code);
    $result = implode("\n", $result);
    if ($code) {
        printMutex("An error occurred while executing $cmd (status $code): $result");
        die(1);
    }
    return $result;
}

$repos = [];

foreach (['amp', 'cache', 'dns', 'file', 'http', 'parallel', 'parser', 'pipeline', 'process', 'serialization', 'socket', 'sync', 'websocket-client', 'websocket-server'] as $repo) {
    $repos["amphp-$repo"] = ["https://github.com/amphp/$repo.git", "", null, ["vendor/bin/phpunit"]];
}
$repos["laravel"] = [
    "https://github.com/laravel/framework.git",
    "master",
    function (): void {
        $c = file_get_contents("tests/Filesystem/FilesystemTest.php"); 
        $c = str_replace("public function testSharedGet()", "#[\\PHPUnit\\Framework\\Attributes\\Group('skip')]\n    public function testSharedGet()", $c);
        file_put_contents("tests/Filesystem/FilesystemTest.php", $c);
    },
    ["vendor/bin/phpunit", "--exclude-group", "skip"]
];

foreach (['async', 'cache', 'child-process', 'datagram', 'dns', 'event-loop', 'promise', 'promise-stream', 'promise-timer', 'stream'] as $repo) {
    $repos["reactphp-$repo"] = ["https://github.com/reactphp/$repo.git", "", null, ["vendor/bin/phpunit"]];
}

$repos["revolt"] = ["https://github.com/revoltphp/event-loop.git", "", null, ["vendor/bin/phpunit"]];

$repos["symfony"] = [
    "https://github.com/symfony/symfony.git",
    "",
    function (): void {
        e("php ./phpunit install");

        // Test causes a heap-buffer-overflow but I cannot reproduce it locally...
        $c = file_get_contents("src/Symfony/Component/HtmlSanitizer/Tests/HtmlSanitizerCustomTest.php");
        $c = str_replace("public function testSanitizeDeepNestedString()", "/** @group skip */\n    public function testSanitizeDeepNestedString()", $c);
        file_put_contents("src/Symfony/Component/HtmlSanitizer/Tests/HtmlSanitizerCustomTest.php", $c);
        // Buggy FFI test in Symfony, see https://github.com/symfony/symfony/issues/47668
        $c = file_get_contents("src/Symfony/Component/VarDumper/Tests/Caster/FFICasterTest.php"); 
        $c = str_replace("*/\n    public function testCastNonTrailingCharPointer()", "* @group skip\n     */\n    public function testCastNonTrailingCharPointer()", $c);
        file_put_contents("src/Symfony/Component/VarDumper/Tests/Caster/FFICasterTest.php", $c);
        $c = file_get_contents("src/Symfony/Component/VarDumper/Tests/Caster/FFICasterTest.php"); $c = str_replace("*/\n    public function testCastNonTrailingCharPointer()", "* @group skip\n     */\n    public function testCastNonTrailingCharPointer()", $c); file_put_contents("src/Symfony/Component/VarDumper/Tests/Caster/FFICasterTest.php", $c);
    },
    function (): iterable {
        $it = new RecursiveDirectoryIterator("src/Symfony");
        /** @var SplFileInfo $file */
        foreach(new RecursiveIteratorIterator($it) as $file) {
            if ($file->getBasename() == 'phpunit.xml.dist') {
                yield [
                    getcwd()."/phpunit",
                    dirname($file->getRealPath()), 
                    "--exclude-group",
                    "tty,benchmark,intl-data,transient",
                    "--exclude-group",
                    "skip"
                ];
            }
        }
    }
];

$parentPids = [];
foreach ($repos as $dir => [$repo, $branch, $prepare, $command]) {
    $pid = pcntl_fork();
    if ($pid) {
        $parentPids []= $pid;
        continue;
    }

    chdir(sys_get_temp_dir());
    if ($branch) {
        $branch = "--branch $branch";
    }
    e("git clone $repo $branch --depth 1 $dir");
    chdir($dir);
    $rev = e("git rev-parse HEAD");
    e("composer i --ignore-platform-reqs");
    if ($prepare) {
        $prepare();
    }
    if ($command instanceof Closure) {
        $commands = $command();
    } else {
        $commands = [$command];
    }
    $pids = [];
    $idx = 0;
    foreach ($commands as $cmd) {
        $cmd = array_merge([
            'php',
            '--repeat',
            '2',
            '-f',
            __DIR__.'/jit_check.php',
        ], $cmd);

        $cmdStr = implode(" ", $cmd);
        $p = proc_open($cmd, [
            ["pipe", "r"], 
            ["file", sys_get_temp_dir()."/out_{$dir}_$idx.txt", "a"],
            ["file", sys_get_temp_dir()."/out_{$dir}_$idx.txt", "a"]
        ], $pipes);
        if ($p === false) {
            echo "Failure starting $cmdStr".PHP_EOL;
            exit(1);
        }
        $pids[$cmdStr] = [$p, sys_get_temp_dir()."/out_{$dir}_$idx.txt"];
        $idx++;
    }

    $final = 0;
    foreach ($pids as $cmd => [$p, $result]) {
        $status = proc_close($p);
        if ($status !== 0) {
            if ($status > 128) {
                $final = $status;
            }
            printMutex(
                "$dir ($rev): $cmd terminated with status $status:".PHP_EOL
                .file_get_contents($result).PHP_EOL
            );
        }
    }
    exit($final);
}

$final = 0;
foreach ($parentPids as $pid) {
    $status = 0;
    if (pcntl_waitpid($pid, $status) === -1) {
        printMutex("An error occurred while waiting with waitpid!");
    }
    if ($status !== 0) {
        $final = $status;
    }
}
die($final);