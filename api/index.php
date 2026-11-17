<?php
require __DIR__.'/lib.php';
header('Cache-Control: no-store');
$m=$_SERVER['REQUEST_METHOD']; $seg=explode('/',trim($_GET['r']??'','/'));
$key=$m.' '.implode('/',array_map(fn($s)=>ctype_digit($s)?'#':$s,$seg)); $id=(int)($seg[1]??0);
if($seg[0]==='admin') $id=(int)($seg[2]??0);
$in=json_decode(file_get_contents('php://input'),true)?:[];
try{ $db=db();
switch($key){

case 'POST auth/login':
  $s=$db->prepare("SELECT * FROM usuarios WHERE email=? AND activo=1"); $s->execute([trim($in['email']??'')]); $u=$s->fetch();
  if(!$u||!password_verify($in['password']??'',$u['password_hash'])) out(['error'=>'Credenciales inválidas'],401);
  out(['token'=>jwt_make(['id'=>$u['id'],'nombre'=>$u['nombre'],'rol'=>$u['rol']]),'user'=>['id'=>$u['id'],'nombre'=>$u['nombre'],'rol'=>$u['rol']]]);

case 'GET menu': auth(['cajero','cocinero']);
  out(['cats'=>$db->query("SELECT id,nombre FROM categorias WHERE activo=1")->fetchAll(),
       'prods'=>$db->query("SELECT p.id,p.categoria_id,p.nombre,p.precio,(SELECT MIN(FLOOR(i.stock/r.cantidad)) FROM recetas r JOIN insumos i ON i.id=r.insumo_id WHERE r.producto_id=p.id AND r.cantidad>0) disp FROM productos p WHERE p.activo=1 ORDER BY p.nombre")->fetchAll(),
       'impuesto'=>$CFG['impuesto']]);

case 'GET pedidos': auth(['cajero','cocinero']); out(activos());

case 'GET stream': auth(['cajero','cocinero']); set_time_limit(0);
  header('Content-Type: text/event-stream'); header('X-Accel-Buffering: no'); while(ob_get_level()) ob_end_flush();
  echo "retry: 1000\n\n"; $last=''; $t0=time(); $hb=0;
  while(!connection_aborted() && time()-$t0<55){
    $a=activos(); $sig=md5(json_encode(array_map(fn($p)=>[$p['id'],$p['estado'],$p['pagado']],$a)));
    if($sig!==$last||time()-$hb>=10){ echo 'data: '.json_encode($a,JSON_UNESCAPED_UNICODE)."\n\n"; $last=$sig; $hb=time(); } else echo ": ping\n\n";
    flush(); usleep(700000); }
  exit;

case 'POST pedidos': $u=auth(['cajero']);
  $caja=cajaAbierta(); if(!$caja) out(['error'=>'Abre la caja antes de tomar pedidos'],409);
  if(empty($in['items'])) out(['error'=>'El pedido está vacío'],422);
  $db->beginTransaction(); $sub=0; $lines=[]; $tc=[];
  $gp=$db->prepare("SELECT id,nombre,precio FROM productos WHERE id=? AND activo=1");
  foreach($in['items'] as $it){ $gp->execute([(int)$it['id']]); $p=$gp->fetch(); $q=max(1,(int)$it['cantidad']);
    if(!$p){ $db->rollBack(); out(['error'=>'Producto no disponible'],422); }
    $sub+=$p['precio']*$q; $lines[]=[$p,$q,mb_substr(trim($it['obs']??''),0,200)]; }
  $imp=round($sub*$CFG['impuesto'],2); $tot=$sub+$imp;
  $db->prepare("INSERT INTO pedidos(mesa,tipo,subtotal,impuesto,total,usuario_id,caja_id) VALUES(?,?,?,?,?,?,?)")
     ->execute([mb_substr(trim($in['mesa']??'')?:'-',0,20),($in['tipo']??'')==='llevar'?'llevar':'mesa',$sub,$imp,$tot,$u['id'],$caja['id']]);
  $pid=$db->lastInsertId();
  $ii=$db->prepare("INSERT INTO pedido_items(pedido_id,producto_id,nombre,precio,cantidad,observaciones) VALUES(?,?,?,?,?,?)");
  $rc=$db->prepare("SELECT insumo_id,cantidad FROM recetas WHERE producto_id=?");
  $up=$db->prepare("UPDATE insumos SET stock=stock-? WHERE id=?");
  $mv=$db->prepare("INSERT INTO movimientos_inventario(insumo_id,pedido_id,cantidad) VALUES(?,?,?)");
  foreach($lines as [$p,$q,$o]){ $ii->execute([$pid,$p['id'],$p['nombre'],$p['precio'],$q,$o?:null]);
    $rc->execute([$p['id']]); foreach($rc->fetchAll() as $r){ $c=$r['cantidad']*$q; $up->execute([$c,$r['insumo_id']]); $tc[$r['insumo_id']]=1; $mv->execute([$r['insumo_id'],$pid,$c]); } }
  if(($CFG['bloquear_sin_stock']??true)&&$tc){ $bad=$db->query("SELECT nombre FROM insumos WHERE stock<0 AND id IN(".implode(',',array_map('intval',array_keys($tc))).")")->fetchAll(PDO::FETCH_COLUMN);
    if($bad){ $db->rollBack(); out(['error'=>'Stock insuficiente de: '.implode(', ',$bad)],409); } }
  $db->commit(); out(['id'=>(int)$pid,'total'=>$tot],201);

case 'POST pedidos/#/estado':
  $tr=['en_preparacion'=>['pendiente','cocinero'],'listo'=>['en_preparacion','cocinero'],'entregado'=>['listo','cajero']];
  $n=$in['estado']??''; if(!isset($tr[$n])) out(['error'=>'Estado inválido'],422); auth([$tr[$n][1]]);
  $s=$db->prepare("UPDATE pedidos SET estado=?,inicio_prep_en=IF(?='en_preparacion',NOW(),inicio_prep_en),listo_en=IF(?='listo',NOW(),listo_en) WHERE id=? AND estado=?");
  $s->execute([$n,$n,$n,$id,$tr[$n][0]]); if(!$s->rowCount()) out(['error'=>'Transición no permitida'],409); out(['ok'=>1]);

case 'POST pedidos/#/pagar': auth(['cajero']);
  $caja=cajaAbierta(); if(!$caja) out(['error'=>'No hay caja abierta'],409);
  $s=$db->prepare("SELECT * FROM pedidos WHERE id=?"); $s->execute([$id]); $p=$s->fetch();
  if(!$p) out(['error'=>'Pedido no encontrado'],404); if($p['pagado']) out(['error'=>'Ya está pagado'],409);
  $met=$in['metodo']??''; if(!in_array($met,['efectivo','tarjeta','transferencia'])) out(['error'=>'Método inválido'],422);
  $rec=$met==='efectivo'?(float)($in['recibido']??0):(float)$p['total'];
  if($rec<$p['total']) out(['error'=>'El valor recibido es menor al total'],422);
  $db->prepare("INSERT INTO pagos(pedido_id,caja_id,metodo,monto,recibido) VALUES(?,?,?,?,?)")->execute([$id,$caja['id'],$met,$p['total'],$rec]);
  $db->prepare("UPDATE pedidos SET pagado=1 WHERE id=?")->execute([$id]);
  $s=$db->prepare("SELECT nombre,cantidad,precio,observaciones FROM pedido_items WHERE pedido_id=?"); $s->execute([$id]);
  out(['comprobante'=>['id'=>$id,'fecha'=>date('Y-m-d H:i'),'mesa'=>$p['mesa'],'items'=>$s->fetchAll(),'subtotal'=>$p['subtotal'],'impuesto'=>$p['impuesto'],'total'=>$p['total'],'metodo'=>$met,'recibido'=>$rec,'cambio'=>$rec-$p['total']]]);

case 'GET caja': auth(['cajero']); $c=cajaAbierta()?:null; $res=null;
  if($c){ $s=$db->prepare("SELECT metodo,SUM(monto) t,COUNT(*) n FROM pagos WHERE caja_id=? GROUP BY metodo"); $s->execute([$c['id']]); $res=$s->fetchAll(); }
  out(['caja'=>$c,'resumen'=>$res]);

case 'POST pedidos/#/cancelar': auth(['cajero']);
  $mot=trim($in['motivo']??''); if(mb_strlen($mot)<3) out(['error'=>'Indica el motivo de la cancelación'],422);
  $db->beginTransaction(); $s=$db->prepare("UPDATE pedidos SET estado='cancelado',motivo_cancelacion=? WHERE id=? AND pagado=0 AND estado IN('pendiente','en_preparacion','listo')"); $s->execute([mb_substr($mot,0,200),$id]);
  if(!$s->rowCount()){ $db->rollBack(); out(['error'=>'Este pedido ya no se puede cancelar'],409); }
  $s=$db->prepare("SELECT insumo_id,SUM(cantidad) c FROM movimientos_inventario WHERE pedido_id=? AND tipo='consumo' GROUP BY insumo_id"); $s->execute([$id]);
  foreach($s->fetchAll() as $r){ $db->prepare("UPDATE insumos SET stock=stock+? WHERE id=?")->execute([$r['c'],$r['insumo_id']]);
    $db->prepare("INSERT INTO movimientos_inventario(insumo_id,pedido_id,cantidad,tipo) VALUES(?,?,?,'devolucion')")->execute([$r['insumo_id'],$id,$r['c']]); }
  $db->commit(); out(['ok'=>1]);

case 'GET historial': auth(['cajero']); $f=preg_match('/^\d{4}-\d{2}-\d{2}$/',$_GET['fecha']??'')?$_GET['fecha']:date('Y-m-d');
  $s=$db->prepare("SELECT p.id,p.mesa,p.tipo,p.estado,p.subtotal,p.impuesto,p.total,p.pagado,p.motivo_cancelacion,DATE_FORMAT(p.creado_en,'%H:%i') hora,DATE_FORMAT(p.creado_en,'%Y-%m-%d %H:%i') fecha,g.metodo,g.recibido FROM pedidos p LEFT JOIN pagos g ON g.pedido_id=p.id WHERE DATE(p.creado_en)=? ORDER BY p.id DESC"); $s->execute([$f]); $ps=$s->fetchAll();
  $si=$db->prepare("SELECT nombre,cantidad,precio,observaciones FROM pedido_items WHERE pedido_id=?"); foreach($ps as &$p){ $si->execute([$p['id']]); $p['items']=$si->fetchAll(); } out($ps);

case 'POST caja/abrir': $u=auth(['cajero']);
  if(cajaAbierta()) out(['error'=>'Ya hay una caja abierta'],409);
  $db->prepare("INSERT INTO cajas(usuario_id,base_inicial) VALUES(?,?)")->execute([$u['id'],max(0,(float)($in['base']??0))]); out(['ok'=>1],201);

case 'POST caja/cerrar': auth(['cajero']);
  $c=cajaAbierta(); if(!$c) out(['error'=>'No hay caja abierta'],409);
  $s=$db->prepare("SELECT COUNT(*) FROM pedidos WHERE caja_id=? AND pagado=0 AND estado<>'cancelado'"); $s->execute([$c['id']]);
  if($s->fetchColumn()) out(['error'=>'Hay pedidos sin cobrar o cancelar. Resuélvelos antes de cerrar la caja'],409);
  $s=$db->prepare("SELECT metodo,SUM(monto) t,COUNT(*) n FROM pagos WHERE caja_id=? GROUP BY metodo"); $s->execute([$c['id']]); $mt=$s->fetchAll();
  $tv=array_sum(array_column($mt,'t')); $ef=0; foreach($mt as $r) if($r['metodo']==='efectivo') $ef=$r['t'];
  $esp=$c['base_inicial']+$ef; $cont=(float)($in['contado']??0); $dif=$cont-$esp;
  $db->prepare("UPDATE cajas SET estado='cerrada',cerrada_en=NOW(),total_ventas=?,esperado=?,contado=?,diferencia=? WHERE id=?")->execute([$tv,$esp,$cont,$dif,$c['id']]);
  out(['arqueo'=>['base'=>$c['base_inicial'],'metodos'=>$mt,'total_ventas'=>$tv,'esperado_efectivo'=>$esp,'contado'=>$cont,'diferencia'=>$dif]]);

// ---------- ADMIN ----------
case 'GET admin/categorias': auth(['administrador']); out($db->query("SELECT * FROM categorias WHERE activo=1")->fetchAll());
case 'POST admin/categorias': auth(['administrador']); $db->prepare("INSERT INTO categorias(nombre) VALUES(?)")->execute([trim($in['nombre']??'')]); out(['ok'=>1],201);

case 'GET admin/productos': auth(['administrador']); out($db->query("SELECT p.*,c.nombre categoria FROM productos p JOIN categorias c ON c.id=p.categoria_id ORDER BY c.nombre,p.nombre")->fetchAll());
case 'POST admin/productos': auth(['administrador']);
  if(!empty($in['id'])) $db->prepare("UPDATE productos SET categoria_id=?,nombre=?,precio=?,activo=? WHERE id=?")->execute([$in['categoria_id'],trim($in['nombre']),$in['precio'],(int)$in['activo'],$in['id']]);
  else $db->prepare("INSERT INTO productos(categoria_id,nombre,precio,activo) VALUES(?,?,?,?)")->execute([$in['categoria_id'],trim($in['nombre']),$in['precio'],(int)($in['activo']??1)]);
  out(['ok'=>1]);

case 'GET admin/insumos': auth(['administrador']); out($db->query("SELECT * FROM insumos ORDER BY nombre")->fetchAll());
case 'POST admin/insumos': auth(['administrador']);
  if(!empty($in['id'])) $db->prepare("UPDATE insumos SET nombre=?,unidad=?,stock=?,stock_minimo=? WHERE id=?")->execute([trim($in['nombre']),$in['unidad'],$in['stock'],$in['stock_minimo'],$in['id']]);
  else $db->prepare("INSERT INTO insumos(nombre,unidad,stock,stock_minimo) VALUES(?,?,?,?)")->execute([trim($in['nombre']),$in['unidad'],$in['stock'],$in['stock_minimo']]);
  out(['ok'=>1]);

case 'GET admin/receta/#': auth(['administrador']); $s=$db->prepare("SELECT insumo_id,cantidad FROM recetas WHERE producto_id=?"); $s->execute([$id]); out($s->fetchAll());
case 'POST admin/receta/#': auth(['administrador']); $db->beginTransaction();
  $db->prepare("DELETE FROM recetas WHERE producto_id=?")->execute([$id]); $s=$db->prepare("INSERT INTO recetas VALUES(?,?,?)");
  foreach($in['items']??[] as $r) $s->execute([$id,$r['insumo_id'],$r['cantidad']]); $db->commit(); out(['ok'=>1]);

case 'GET admin/usuarios': auth(['administrador']); out($db->query("SELECT id,nombre,email,rol,activo FROM usuarios ORDER BY nombre")->fetchAll());
case 'POST admin/usuarios': $uA=auth(['administrador']);
  if(!in_array($in['rol']??'',['administrador','cajero','cocinero'])) out(['error'=>'Rol inválido'],422);
  if(!empty($in['id'])&&$in['id']==$uA['id']&&((int)$in['activo']===0||$in['rol']!=='administrador')) out(['error'=>'No puedes desactivar ni cambiar el rol de tu propio usuario'],422);
  if(!empty($in['id'])){ $db->prepare("UPDATE usuarios SET nombre=?,email=?,rol=?,activo=? WHERE id=?")->execute([$in['nombre'],$in['email'],$in['rol'],(int)$in['activo'],$in['id']]);
    if(!empty($in['password'])) $db->prepare("UPDATE usuarios SET password_hash=? WHERE id=?")->execute([password_hash($in['password'],PASSWORD_BCRYPT),$in['id']]); }
  else { if(strlen($in['password']??'')<6) out(['error'=>'Contraseña mínima de 6 caracteres'],422);
    $db->prepare("INSERT INTO usuarios(nombre,email,password_hash,rol,activo) VALUES(?,?,?,?,?)")->execute([$in['nombre'],$in['email'],password_hash($in['password'],PASSWORD_BCRYPT),$in['rol'],(int)($in['activo']??1)]); }
  out(['ok'=>1]);

case 'GET admin/cajas': auth(['administrador']); out($db->query("SELECT c.*,u.nombre cajero FROM cajas c JOIN usuarios u ON u.id=c.usuario_id ORDER BY c.id DESC LIMIT 60")->fetchAll());
case 'POST admin/insumos/#/entrada': auth(['administrador']); $q=(float)($in['cantidad']??0); if($q<=0) out(['error'=>'Cantidad inválida'],422);
  $db->prepare("UPDATE insumos SET stock=stock+? WHERE id=?")->execute([$q,$id]); $db->prepare("INSERT INTO movimientos_inventario(insumo_id,cantidad,tipo) VALUES(?,?,'entrada')")->execute([$id,$q]); out(['ok'=>1]);

case 'GET admin/reportes': auth(['administrador']); $r=[]; $rx='/^\d{4}-\d{2}-\d{2}$/';
  $d1=preg_match($rx,$_GET['desde']??'')?$_GET['desde']:date('Y-m-d'); $d2=preg_match($rx,$_GET['hasta']??'')?$_GET['hasta']:$d1; $P=[$d1,$d2];
  $q=function($sql)use($db,$P){ $s=$db->prepare($sql); $s->execute($P); return $s; }; $r['rango']=$P;
  $r['hoy']=$q("SELECT COALESCE(SUM(total),0) ventas,COUNT(*) pedidos,COALESCE(AVG(total),0) ticket FROM pedidos WHERE pagado=1 AND DATE(creado_en) BETWEEN ? AND ?")->fetch();
  $r['canceladas']=(int)$q("SELECT COUNT(*) FROM pedidos WHERE estado='cancelado' AND DATE(creado_en) BETWEEN ? AND ?")->fetchColumn();
  $r['metodos']=$q("SELECT metodo,SUM(monto) total FROM pagos WHERE DATE(creado_en) BETWEEN ? AND ? GROUP BY metodo")->fetchAll();
  $r['top']=$q("SELECT i.nombre,SUM(i.cantidad) cant,SUM(i.cantidad*i.precio) total FROM pedido_items i JOIN pedidos p ON p.id=i.pedido_id WHERE p.estado<>'cancelado' AND DATE(p.creado_en) BETWEEN ? AND ? GROUP BY i.nombre ORDER BY cant DESC LIMIT 8")->fetchAll();
  $r['tiempos']=$q("SELECT ROUND(AVG(TIMESTAMPDIFF(SECOND,inicio_prep_en,listo_en))/60,1) prep,ROUND(AVG(TIMESTAMPDIFF(SECOND,creado_en,listo_en))/60,1) total FROM pedidos WHERE listo_en IS NOT NULL AND DATE(creado_en) BETWEEN ? AND ?")->fetch();
  $r['dias']=$q("SELECT DATE(creado_en) dia,SUM(total) ventas FROM pedidos WHERE pagado=1 AND DATE(creado_en) BETWEEN ? AND ? GROUP BY dia ORDER BY dia")->fetchAll();
  $r['horas']=$q("SELECT HOUR(creado_en) h,COUNT(*) n FROM pedidos WHERE estado<>'cancelado' AND DATE(creado_en) BETWEEN ? AND ? GROUP BY h ORDER BY h")->fetchAll();
  $r['alertas']=$db->query("SELECT nombre,unidad,stock,stock_minimo FROM insumos WHERE stock<=stock_minimo")->fetchAll();
  out($r);

default: out(['error'=>'Ruta no encontrada'],404);
} }catch(Throwable $e){ if(isset($db)&&$db->inTransaction()) $db->rollBack(); out(['error'=>$e->getMessage()],500); }
