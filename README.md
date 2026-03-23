# EnaLogin - Gestión de Usuarios y Permisos (PHP + SQLite)

Sistema ligero, seguro y versátil para gestionar usuarios, grupos y permisos en sitios web. Diseñado para funcionar en un solo archivo, facilitando su integración en proyectos existentes.

## 🚀 Características
- **Seguridad**: Protección contra Inyección SQL (PDO), XSS y CSRF.
- **Portabilidad**: Base de datos SQLite integrada (no requiere MySQL).
- **Flexibilidad**: Gestión de permisos por grupos o excepciones individuales.
- **Recordar sesión**: Sistema de cookies seguras para mantener la sesión.

## 🛠️ Instalación
1. Copia `ena_login.php` a tu servidor.
2. Configura las variables iniciales al principio del archivo:
   ```php
   $DB_PATH = __DIR__ .'/login.sqlite';
   $ADMIN_NAME = "tu_usuario";
   $ADMIN_PASS = "tu_password_seguro";
   $URL_LIBRERIA = "http://tusitio.com/ruta/";
   ```
3. Accede al script desde tu navegador. Se creará automáticamente la base de datos y el usuario administrador.

## 📚 Funciones Útiles

### Control de Acceso
- `is_user_in_group($group_name)`: Devuelve `true` si el usuario actual pertenece al grupo.
- `check_permision($permission_name)`: Verifica si el usuario tiene un permiso específico (o es administrador).

### Interfaz (Vistas)
- `html_form_login($url_retorno)`: Imprime un formulario de login estilizado.
- `html_list_acciones_disponibles_usuario($url_retorno)`: Muestra enlaces de Logout, Panel de Admin o Ajustes.

### Gestión de Datos
- `get_user_by_id($id)`: Obtiene datos básicos de un usuario.
- `create_user($user, $email, $password)`: Crea un nuevo usuario en el sistema.

## 🛡️ Seguridad Avanzada
El sistema incluye protección activa contra:
- **SQL Injection**: Uso estricto de sentencias preparadas.
- **XSS**: Escapado de todas las salidas dinámicas.
- **CSRF**: Validación de tokens en todas las acciones sensibles.
- **Information Disclosure**: Reporte de errores desactivado por defecto.
