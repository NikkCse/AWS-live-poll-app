<?php
// Load balancer health check.
// Deliberately does NOT touch the database: if RDS has a hiccup, we don't
// want the ALB to mark every server unhealthy and take the whole site down.
http_response_code(200);
header('Content-Type: text/plain');
echo 'ok';
