<?php
return [
  'db' => ['host'=>'127.0.0.1','name'=>'paopcr','user'=>'root','pass'=>''],
  'jwt_secret' => 'CAMBIA_ESTA_CLAVE_LARGA_Y_ALEATORIA_2026',
  'jwt_ttl' => 43200,   // 12 horas
  'bloquear_sin_stock' => true, // rechaza pedidos si no alcanzan los insumos
  'impuesto' => 0.08,   // INC 8% (usa 0.19 para IVA)
];
