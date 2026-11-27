<?php
// Chhota test runner (bina PHPUnit): app boot + sqlite memory DB
putenv('DB_CONNECTION=sqlite'); putenv('DB_DATABASE=:memory:'); putenv('CACHE_STORE=array'); putenv('APP_ENV=testing');
$_ENV['DB_CONNECTION'] = 'sqlite'; $_ENV['DB_DATABASE'] = ':memory:'; $_ENV['CACHE_STORE'] = 'array';
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
$GLOBALS['__fails'] = 0; $GLOBALS['__n'] = 0;
function ok($cond, $msg) { $GLOBALS['__n']++; if ($cond) { echo "  ok  $msg\n"; } else { $GLOBALS['__fails']++; echo "  FAIL $msg\n"; } }
function done() { echo "\n{$GLOBALS['__n']} checks, {$GLOBALS['__fails']} fail\n"; exit($GLOBALS['__fails'] ? 1 : 0); }
