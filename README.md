# PAOPCR - Automatización de procesos operativos (cafetería-restaurante)
Stack: HTML/CSS/JS + PHP 8 (PDO) + MySQL/MariaDB (XAMPP). Tiempo real POS-KDS con Server-Sent Events (aprox. 0.7 s).

## Instalación
1. Inicia Apache y MySQL en XAMPP y copia la carpeta `paopcr` a `htdocs`.
2. Ajusta `api/config.php` si tu MySQL tiene clave (también `jwt_secret` e impuesto).
3. Abre `http://localhost/paopcr/setup.php` (crea la BD, o migra una instalación previa) y luego borra `setup.php`.
4. Entra a `http://localhost/paopcr/` (desde la LAN: `http://IP-DEL-SERVIDOR/paopcr/`).

## Usuarios demo
admin@paopcr.local / Admin123* | cajero@paopcr.local / Cajero123* | cocina@paopcr.local / Cocina123*

