<?php
// Render synthetic content only. No SMTP or database writes.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mkpos-platform-user-email.html';
file_put_contents($path, view('emails.platform-user-message', ['name' => 'Aye Aye', 'subject' => 'An update from MKPOS', 'message' => "Thank you for shopping with MKPOS.\n\nWe are here to help with your platform account."])->render());
echo $path.PHP_EOL;
