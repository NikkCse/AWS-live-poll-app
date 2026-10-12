<?php
// Copy this to config.php, fill in your values, and upload it to:
//   s3://YOUR-BUCKET/config/config.php
// The EC2 startup script copies it to /etc/poll-app/config.php (outside the web root).
// NEVER commit the real config.php to GitHub.

return [
    // RDS endpoint from the RDS console (Connectivity & security tab)
    'db_dsn' => 'mysql:host=YOUR_RDS_ENDPOINT;port=3306;dbname=polldb;charset=utf8mb4',,
    'db_user'   => 'admin',
    'db_pass'   => 'xxxxxxx',

    // The key you type on /admin.php to create polls. Make it long and random.
    'admin_key' => 'YOU-CAN-ANY-TEXT-HERE',
];
