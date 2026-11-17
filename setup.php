<?php
// Ejecutar UNA vez: http://localhost/paopcr/setup.php  (luego borrar este archivo)
$CFG=require __DIR__.'/api/config.php'; $d=$CFG['db'];
$pdo=new PDO("mysql:host={$d['host']};charset=utf8mb4",$d['user'],$d['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE DATABASE IF NOT EXISTS `{$d['name']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `{$d['name']}`");
foreach(array_filter(array_map('trim',explode(';',file_get_contents(__DIR__.'/database/schema.sql')))) as $q) $pdo->exec($q);
// Migración para instalaciones previas
try{$pdo->exec("ALTER TABLE pedidos MODIFY estado ENUM('pendiente','en_preparacion','listo','entregado','cancelado') NOT NULL DEFAULT 'pendiente'");}catch(Throwable $e){}
try{$pdo->exec("ALTER TABLE pedidos ADD COLUMN motivo_cancelacion VARCHAR(200) NULL");}catch(Throwable $e){}
if(!$pdo->query("SELECT COUNT(*) FROM usuarios")->fetchColumn()){
  $u=$pdo->prepare("INSERT INTO usuarios(nombre,email,password_hash,rol) VALUES(?,?,?,?)");
  foreach([['Administrador','admin@paopcr.local','Admin123*','administrador'],['Cajero Demo','cajero@paopcr.local','Cajero123*','cajero'],['Cocinero Demo','cocina@paopcr.local','Cocina123*','cocinero']] as $r)
    $u->execute([$r[0],$r[1],password_hash($r[2],PASSWORD_BCRYPT),$r[3]]);
  $pdo->exec("INSERT INTO categorias(id,nombre) VALUES(1,'Bebidas'),(2,'Comidas'),(3,'Postres')");
  $pdo->exec("INSERT INTO productos(id,categoria_id,nombre,precio) VALUES(1,1,'Café americano',4500),(2,1,'Capuchino',6500),(3,1,'Jugo natural',7000),(4,2,'Hamburguesa',18000),(5,2,'Sándwich club',15000),(6,3,'Pastel de chocolate',8500)");
  $pdo->exec("INSERT INTO insumos(id,nombre,unidad,stock,stock_minimo) VALUES(1,'Café molido','g',5000,500),(2,'Leche','ml',20000,2000),(3,'Fruta','g',8000,1000),(4,'Pan hamburguesa','und',50,10),(5,'Carne','g',6000,1000),(6,'Pan sándwich','und',40,10),(7,'Pollo','g',4000,800),(8,'Torta chocolate','porción',20,4)");
  $pdo->exec("INSERT INTO recetas(producto_id,insumo_id,cantidad) VALUES(1,1,10),(2,1,10),(2,2,150),(3,3,200),(4,4,1),(4,5,150),(5,6,2),(5,7,120),(6,8,1)");
}
echo '<h2>Instalación completa</h2><p>Base de datos <b>'.$d['name'].'</b> lista. <a href="index.html">Ir al login</a>. Borra setup.php.</p>';
