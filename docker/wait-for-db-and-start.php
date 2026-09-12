<?php
$host=getenv('DB_HOST') ?: 'db'; $port=getenv('DB_PORT') ?: '3306'; $user=getenv('DB_USER') ?: 'vas_user'; $pass=getenv('DB_PASSWORD') ?: 'vas_password';
$schemas=['vas_portal','HeraProduction','HeraTesting'];
for($i=1;$i<=180;$i++){
  try{ foreach($schemas as $schema){ $pdo=new PDO("mysql:host=$host;port=$port;dbname=$schema;charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); $pdo->query('SELECT 1'); } fwrite(STDOUT,"MySQL ready. Starting Apache...\n"); passthru('apache2-foreground'); exit; }
  catch(Throwable $e){ fwrite(STDOUT,"MySQL not ready yet... attempt $i/180: ".$e->getMessage()."\n"); sleep(2); }
}
fwrite(STDERR,"Database did not become ready.\n"); exit(1);
