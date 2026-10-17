<?php
// Render a synthetic email only; no SMTP connection or account mutation.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mkpos-password-reset-email.html';
file_put_contents($path, view('emails.password-reset-otp', ['name' => 'MKPOS Customer', 'code' => '012345', 'audience' => 'MKPOS Shops'])->render());
echo $path.PHP_EOL;
