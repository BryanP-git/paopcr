<?php
date_default_timezone_set('America/Bogota');
$CFG = require __DIR__.'/config.php';
function db(){ static $p; global $CFG;
  if(!$p){ $d=$CFG['db'];
    $p=new PDO("mysql:host={$d['host']};dbname={$d['name']};charset=utf8mb4",$d['user'],$d['pass'],
      [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $p->exec("SET time_zone='-05:00'"); }
  return $p; }
function b64($s){ return rtrim(strtr(base64_encode($s),'+/','-_'),'='); }
function jwt_make($pl){ global $CFG; $h=b64('{"alg":"HS256","typ":"JWT"}'); $pl['exp']=time()+$CFG['jwt_ttl']; $p=b64(json_encode($pl));
  return "$h.$p.".b64(hash_hmac('sha256',"$h.$p",$CFG['jwt_secret'],true)); }
function jwt_read($t){ global $CFG; $x=explode('.',$t); if(count($x)!==3) return null;
  if(!hash_equals(b64(hash_hmac('sha256',"$x[0].$x[1]",$CFG['jwt_secret'],true)),$x[2])) return null;
  $p=json_decode(base64_decode(strtr($x[1],'-_','+/')),true); return ($p&&$p['exp']>time())?$p:null; }
function out($d,$c=200){ http_response_code($c); header('Content-Type: application/json'); echo json_encode($d,JSON_UNESCAPED_UNICODE); exit; }
// RBAC: el administrador siempre tiene acceso; $roles lista los demás roles permitidos
function auth(array $roles=[]){
  $h=$_SERVER['HTTP_AUTHORIZATION']??($_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'');
  $t=preg_match('/Bearer\s+(.+)/',$h,$m)?$m[1]:($_GET['token']??'');
  $u=jwt_read($t); if(!$u) out(['error'=>'No autenticado'],401);
  if($u['rol']!=='administrador' && !in_array($u['rol'],$roles)) out(['error'=>'Sin permiso para esta acción'],403);
  return $u; }
function activos(){ $db=db();
  $ps=$db->query("SELECT id,mesa,tipo,estado,total,pagado,TIMESTAMPDIFF(SECOND,creado_en,NOW()) seg FROM pedidos
    WHERE estado IN('pendiente','en_preparacion','listo') OR (estado='entregado' AND pagado=0) ORDER BY creado_en")->fetchAll();
  $s=$db->prepare("SELECT nombre,cantidad,observaciones FROM pedido_items WHERE pedido_id=?");
  foreach($ps as &$p){ $s->execute([$p['id']]); $p['items']=$s->fetchAll(); } return $ps; }
function cajaAbierta(){ return db()->query("SELECT * FROM cajas WHERE estado='abierta' ORDER BY id DESC LIMIT 1")->fetch(); }
