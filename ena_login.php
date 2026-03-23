<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

/**
 EnaLogin v26.03.230
 * Sistema de Gestión de Usuarios y Permisos (SQLite)
 * Archivo de utilidad única.
 */

/* CONFIGURACIÓN: [EDITAR AL INSTALAR] */
$DB_PATH = $DB_PATH ?? __DIR__ . '/login.sqlite'; // Ruta donde se guarda la base de datos (local) (Debería estár fuera de la carpeta pública)
$ADMIN_NAME = "admin"; //Nombre del administrador.
$ADMIN_PASS = "12345"; //Password inicial del administrador. 
$URL_LIBRERIA = "http://localhost:8000/"; //Url donde se ubica la librería para formularios locales



/* INICIALIZAR */
if (session_status() === PHP_SESSION_NONE) {
	session_start();
}
// 🔒 Generar token CSRF si no existe
if (empty($_SESSION['csrf_token'])) {
	$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
if (!file_exists($DB_PATH)) {
	echo install_database($DB_PATH); // Si no existe, llamamos a la función de instalación que creamos antes
}
else { // Si ya existe, simplemente creamos la conexión global para usarla en la web
	try {
		$db = new PDO("sqlite:$DB_PATH");
		$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	}
	catch (PDOException $e) {
		die("Error de conexión: " . $e->getMessage());
	}
}
if (!isset($_SESSION['logged_in'])) { //Si el usuario NO tiene sesión, intentamos loguearlo por Cookie (el "Recuérdame")
	login_from_cookie();
}


/* FUNCIONES MODELO */
function get_user_by_id($id)
{
	global $db;

	try {
		$stmt = $db->prepare('SELECT username, email, created_at FROM users WHERE id = ? LIMIT 1');
		$stmt->execute([$id]);

		return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

	}
	catch (PDOException $e) {
		error_log($e->getMessage());
		return null;
	}
}
function verify_user_password($id, $password)
{
	global $db;

	try {
		// Obtenemos solo el hash de la base de datos
		$stmt = $db->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
		$stmt->execute([$id]);
		$stored_hash = $stmt->fetchColumn();

		// Si el usuario no existe, devolvemos false
		if (!$stored_hash)
			return false;

		// Comparamos la contraseña plana con el hash
		return password_verify($password, $stored_hash);

	}
	catch (PDOException $e) {
		error_log($e->getMessage());
		return false;
	}
}
function is_user_in_group($group_name)
{
	global $db;
	static $user_groups = null; // Caché para no repetir la consulta

	if (!isset($_SESSION['user_id']))
		return false;

	// Si es la primera vez en esta carga de página, traemos todos sus grupos
	if ($user_groups === null) {
		$stmt = $db->prepare("SELECT g.name FROM groups g 
                              JOIN user_groups ug ON g.id = ug.group_id 
                              WHERE ug.user_id = ?");
		$stmt->execute([$_SESSION['user_id']]);
		$user_groups = $stmt->fetchAll(PDO::FETCH_COLUMN); // Devuelve array de nombres
	}

	return in_array($group_name, $user_groups);
}
function get_all_users_with_groups()
{
	global $db;

	try {
		$sql = 'SELECT u.id, u.username, u.email, u.created_at, 
                       GROUP_CONCAT(g.name, \', \') AS groups_list
                FROM users u
                LEFT JOIN user_groups ug ON u.id = ug.user_id
                LEFT JOIN groups g ON ug.group_id = g.id
                GROUP BY u.id
                ORDER BY u.username ASC';

		$stmt = $db->query($sql);
		return $stmt->fetchAll(PDO::FETCH_ASSOC);

	}
	catch (PDOException $e) {
		error_log($e->getMessage());
		return [];
	}
}
function get_all_users()
{
	global $db;

	try {
		$sql = 'SELECT u.id, u.username
                FROM users u';

		$stmt = $db->query($sql);
		return $stmt->fetchAll(PDO::FETCH_ASSOC);

	}
	catch (PDOException $e) {
		error_log($e->getMessage());
		return [];
	}
}
function create_user($username, $email, $password)
{
	global $db;

	try {
		// 1. Cifrar la contraseña
		$hashedPass = password_hash($password, PASSWORD_DEFAULT);

		// 2. Preparar la inserción
		$stmt = $db->prepare('INSERT INTO users (username, email, password) VALUES (?, ?, ?)');

		// 3. Ejecutar
		$stmt->execute([$username, $email, $hashedPass]);

		return ['success' => true, 'id' => $db->lastInsertId()];

	}
	catch (PDOException $e) {
		// Error común: usuario o email ya existen (si tienes índices UNIQUE)
		return ['success' => false, 'error' => $e->getMessage()];
	}
}
function get_all_groups_with_users()
{
	global $db;

	try {
		$sql = 'SELECT g.id, g.name, g.description,
                       GROUP_CONCAT(u.username, \', \') AS members_list
                FROM groups g
                LEFT JOIN user_groups ug ON g.id = ug.group_id
                LEFT JOIN users u ON ug.user_id = u.id
                GROUP BY g.id
                ORDER BY g.name ASC';

		$stmt = $db->query($sql);
		return $stmt->fetchAll(PDO::FETCH_ASSOC);

	}
	catch (PDOException $e) {
		error_log($e->getMessage());
		return [];
	}
}
function create_group($group, $desc)
{
	global $db;

	try {
		// 1. Preparar la inserción
		$stmt = $db->prepare('INSERT INTO groups (name, description) VALUES (?, ?)');

		// 2. Ejecutar
		$stmt->execute([$group, $desc]);

		return ['success' => true, 'id' => $db->lastInsertId()];

	}
	catch (PDOException $e) {
		// Error común: usuario o email ya existen (si tienes índices UNIQUE)
		return ['success' => false, 'error' => $e->getMessage()];
	}
}
function get_permissions_nested()
{
	global $db;

	$sql = 'SELECT id, permission, categoria, subcategoria, description
            FROM permissions 
            ORDER BY categoria ASC, subcategoria ASC, id ASC';

	$stmt = $db->query($sql);
	$raw_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

	$nested = [];

	foreach ($raw_data as $row) {
		$cat = $row['categoria'] ?: 'General';
		$sub = $row['subcategoria'] ?: 'Otros';

		// Creamos la estructura si no existe
		if (!isset($nested[$cat])) {
			$nested[$cat] = [];
		}
		if (!isset($nested[$cat][$sub])) {
			$nested[$cat][$sub] = [];
		}

		// Añadimos el permiso
		$nested[$cat][$sub][] = [
			'id' => $row['id'],
			'name' => $row['permission']
		];
	}

	return $nested;
}
function get_permissions_for_user_nested($user_id)
{
	global $db;


	try {

		$sql = "SELECT P.id as id, P.permission as permission, P.categoria as categoria, P.subcategoria as subcategoria, P.description as description
				FROM permissions P, user_permissions UP
				WHERE P.id = UP.permission_id AND UP.user_id = ?
				ORDER BY P.categoria ASC, P.subcategoria ASC, id ASC";
		$stmt = $db->prepare($sql);
		$stmt->execute([$user_id]);
		$raw_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

		$nested = [];

		foreach ($raw_data as $row) {
			$cat = $row['categoria'] ?: 'General';
			$sub = $row['subcategoria'] ?: 'Otros';

			// Creamos la estructura si no existe
			if (!isset($nested[$cat])) {
				$nested[$cat] = [];
			}
			if (!isset($nested[$cat][$sub])) {
				$nested[$cat][$sub] = [];
			}

			// Añadimos el permiso
			$nested[$cat][$sub][] = [
				'id' => $row['id'],
				'name' => $row['permission']
			];
		}
	}
	catch (PDOException $e) {
		// Error común: usuario o email ya existen (si tienes índices UNIQUE)
		echo $e->getMessage();
	}

	return $nested;
}
function get_permission_id($perm)
{
	global $db;

	try {
		// Asegúrate de que la columna se llame 'name' o 'permission' según tu DB
		$sql = 'SELECT id FROM permissions WHERE permission = ? LIMIT 1';

		$stmt = $db->prepare($sql);
		$stmt->execute([$perm]);

		// Obtenemos una sola columna (la ID)
		$id = $stmt->fetchColumn();

		// Si fetchColumn no encuentra nada, devuelve false. 
		// Retornamos el ID o -1 según tu requerimiento.
		return $id !== false ? (int)$id : -1;

	}
	catch (PDOException $e) {
		error_log($e->getMessage());
		return -1;
	}
}
function create_perm($perm, $cat, $sub, $desc)
{
	global $db;

	try {
		// 1. Preparar la inserción
		$stmt = $db->prepare('INSERT INTO permissions (permission, description, categoria, subcategoria ) VALUES (?, ?, ?, ?)');

		// 2. Ejecutar
		$stmt->execute([$perm, $desc, $cat, $sub]);

		return ['success' => true, 'id' => $db->lastInsertId()];

	}
	catch (PDOException $e) {
		// Error común: usuario o email ya existen (si tienes índices UNIQUE)
		return ['success' => false, 'error' => $e->getMessage()];
	}
}
function remove_perm($id)
{
	global $db;

	try {
		// 1. Preparar la inserción
		$stmt = $db->prepare('DELETE FROM permissions WHERE id = ?');

		// 2. Ejecutar
		$stmt->execute([$id]);

		return ['success' => true, 'id' => $db->lastInsertId()];

	}
	catch (PDOException $e) {
		// Error común: usuario o email ya existen (si tienes índices UNIQUE)
		return ['success' => false, 'error' => $e->getMessage()];
	}
}
function update_user_basic_data($id, $new_username, $new_email)
{
	global $db;

	try {
		$stmt = $db->prepare('UPDATE users SET username = ?, email = ? WHERE id = ?');
		$stmt->execute([$new_username, $new_email, $id]);

		// rowCount() devuelve cuántas filas se modificaron

		return $stmt->rowCount() > 0;

	}
	catch (PDOException $e) {
		error_log($e->getMessage());
		return false;
	}
}
function update_user_password($id, $new_password)
{
	global $db;

	try {
		$hashedPass = password_hash($new_password, PASSWORD_DEFAULT);
		$stmt = $db->prepare('UPDATE users SET password = ? WHERE id = ?');
		$stmt->execute([$hashedPass, $id]);

		// rowCount() devuelve cuántas filas se modificaron

		return $stmt->rowCount() > 0;

	}
	catch (PDOException $e) {
		error_log($e->getMessage());
		return false;
	}
}
function check_permision($perm)
{

	if (!isset($_SESSION['user_id']))
		return false;
	if (is_user_in_group("administradores"))
		return true;
	else {
		global $db;
		$user_id = $_SESSION['user_id'];
		$perm_id = get_permission_id($perm);

		try {
			$sql = 'SELECT count(*) FROM user_permissions WHERE user_id = ? AND permission = ?';

			$stmt = $db->prepare($sql);
			$stmt->execute([$_SESSION['user_id'], $perm]);

			$count = $stmt->fetchColumn();

			if ($count > 0)
				return true;

		}
		catch (PDOException $e) {
			error_log($e->getMessage());
			return false;
		}
	}

	return false;
}
function add_perm_to_user($perm, $user)
{
	global $db;

	try {
		// 1. Preparar la inserción
		$stmt = $db->prepare('INSERT INTO user_permissions (user_id, permission_id) VALUES (?, ?)');

		// 2. Ejecutar
		$stmt->execute([$user, $perm]);

		return ['success' => true, 'id' => $db->lastInsertId()];

	}
	catch (PDOException $e) {
		// Error común: usuario o email ya existen (si tienes índices UNIQUE)
		return ['success' => false, 'error' => $e->getMessage()];
	}
}
function remove_perm_from_user($perm, $user)
{
	global $db;

	try {
		// 1. Preparar la inserción
		$stmt = $db->prepare('DELETE FROM user_permissions WHERE user_id = ? AND permission_id = ?');

		// 2. Ejecutar
		$stmt->execute([$user, $perm]);

		return ['success' => true, 'id' => $db->lastInsertId()];

	}
	catch (PDOException $e) {
		// Error común: usuario o email ya existen (si tienes índices UNIQUE)
		return ['success' => false, 'error' => $e->getMessage()];
	}
}

/* FUNCIONES CONTROLADORAS*/
function install_database($path)
{ // Instala la bd si no existe
	global $ADMIN_NAME, $ADMIN_PASS, $db;
	try {
		// 1. Conectar/Crear el archivo SQLite
		$db = new PDO("sqlite:$path");
		$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

		// 2. Definir las tablas
		$queries = [
			"CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL UNIQUE,
                password TEXT NOT NULL, -- Usaremos password_hash() de PHP
                email TEXT NOT NULL UNIQUE,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
			"CREATE TABLE IF NOT EXISTS user_tokens (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				user_id INTEGER NOT NULL,
				selector CHAR(12),
				token_hash CHAR(64),
				expires DATETIME,
				FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
			)",
			"CREATE TABLE IF NOT EXISTS groups (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(50) NOT NULL UNIQUE,
                description TEXT
            )",

			"CREATE TABLE IF NOT EXISTS permissions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                permission VARCHAR(100) NOT NULL UNIQUE,
                categoria VARCHAR(100),
                subcategoria VARCHAR(100),
                description TEXT
            )",

			// Relación Usuarios <-> Grupos
			"CREATE TABLE IF NOT EXISTS user_groups (
                user_id INTEGER,
                group_id INTEGER,
                PRIMARY KEY (user_id, group_id),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE CASCADE
            )",

			// Relación Grupos <-> Permisos
			"CREATE TABLE IF NOT EXISTS group_permissions (
                group_id INTEGER,
                permission_id INTEGER,
                PRIMARY KEY (group_id, permission_id),
                FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE CASCADE,
                FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
            )",

			// Relación Directa Usuarios <-> Permisos (Excepciones)
			"CREATE TABLE IF NOT EXISTS user_permissions (
                user_id INTEGER,
                permission_id INTEGER,
                PRIMARY KEY (user_id, permission_id),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
            )"
		];

		foreach ($queries as $query) {
			$db->exec($query);
		}

		// 3. Crear grupo de Administradores
		$stmtGroup = $db->prepare("INSERT OR IGNORE INTO groups (name, description) VALUES (?, ?)");
		$stmtGroup->execute(['administradores', 'Acceso total al sistema']);
		$groupId = $db->lastInsertId() ?: $db->query("SELECT id FROM groups WHERE name = 'administradores'")->fetchColumn();

		// 4. Crear el usuario Administrador
		// Ciframos la contraseña de la variable global
		$hashedPass = password_hash($ADMIN_PASS, PASSWORD_DEFAULT);
		$stmtUser = $db->prepare("INSERT OR IGNORE INTO users (username, password, email) VALUES (?, ?, ?)");
		$stmtUser->execute([$ADMIN_NAME, $hashedPass, '']);
		$userId = $db->lastInsertId() ?: $db->query("SELECT id FROM users WHERE username = " . $db->quote($ADMIN_NAME))->fetchColumn();

		// 5. Relacionar Usuario con Grupo (si no están ya relacionados)
		$stmtRel = $db->prepare("INSERT OR IGNORE INTO user_groups (user_id, group_id) VALUES (?, ?)");
		$stmtRel->execute([$userId, $groupId]);

		return "Instalación completada con éxito en: " . realpath($path);

	}
	catch (PDOException $e) {
		return "Error en la instalación en $path: " . $e->getMessage();
	}
}
function login($username, $password)
{ //Intenta iniciar sesión y guarda los datos en $_SESSION //@return bool True si el login es correcto, False si falla.
	global $db; // Usamos la conexión PDO que creamos al inicio

	try {
		// 1. Buscamos al usuario y el nombre de su grupo en una sola consulta
		$sql = "SELECT u.*
                FROM users u
                WHERE u.username = :username 
                LIMIT 1";

		$stmt = $db->prepare($sql);
		$stmt->execute([':username' => $username]);
		$user = $stmt->fetch(PDO::FETCH_ASSOC);
		// 2. Verificar si el usuario existe y la contraseña coincide

		if ($user && password_verify($password, $user['password'])) {
			create_remember_me_token($user['id']);
			// 3. ¡Éxito! Regeneramos el ID de sesión por seguridad (evita Session Hijacking)
			if (session_status() === PHP_SESSION_NONE) {
				session_start();
			}
			session_regenerate_id(true);

			// 4. Guardamos solo lo necesario en la sesión
			$_SESSION['user_id'] = $user['id'];
			$_SESSION['username'] = $user['username'];
			$_SESSION['logged_in'] = true;

			return true;
		}
		else {
		}

		// Si llega aquí, es que el usuario o la contraseña fallaron
		return false;

	}
	catch (PDOException $e) {
		// En desarrollo puedes usar: error_log($e->getMessage());
		echo "error: $e";
		return false;
	}
}
function create_remember_me_token($user_id)
{ //Crea un token temporal para la cookie del navegador
	global $db;

	// 1. Generar tokens aleatorios seguros
	$selector = bin2hex(random_bytes(6)); // 12 caracteres
	$token = bin2hex(random_bytes(32)); // 64 caracteres
	$expires = date('Y-m-d H:i:s', time() + 86400 * 30); // 30 días

	// 2. Guardar el HASH del token en la DB (por seguridad)
	$token_hash = hash('sha256', $token);
	$stmt = $db->prepare("INSERT INTO user_tokens (user_id, selector, token_hash, expires) VALUES (?, ?, ?, ?)");
	$stmt->execute([$user_id, $selector, $token_hash, $expires]);

	// 3. Crear la cookie: "selector:token"
	// httponly y secure son vitales para evitar robos por JS
	setcookie(
		'remember_me',
		$selector . ':' . $token,
		time() + 86400 * 30,
		"/",
		"",
		false, // Cambiar a true si usas HTTPS
		true // httponly: impide que Javascript lea la cookie
	);
}
function login_from_cookie()
{ //Hace login desde la cookie si existe
	global $db;

	if (empty($_COOKIE['remember_me']))
		return false;

	// Separar selector y token
	list($selector, $token) = explode(':', $_COOKIE['remember_me']);

	$stmt = $db->prepare("SELECT * FROM user_tokens WHERE selector = ? AND expires > DATETIME('now') LIMIT 1");
	$stmt->execute([$selector]);
	$record = $stmt->fetch(PDO::FETCH_ASSOC);

	if ($record && hash_equals($record['token_hash'], hash('sha256', $token))) {
		// El token es válido. Iniciamos sesión.
		// Aquí llamarías a una versión simplificada de login() que use el ID
		$user_stmt = $db->prepare("SELECT u.*, g.name AS group_name FROM users u 
                                   LEFT JOIN user_groups ug ON u.id = ug.user_id 
                                   LEFT JOIN groups g ON ug.group_id = g.id 
                                   WHERE u.id = ?");
		$user_stmt->execute([$record['user_id']]);
		$user = $user_stmt->fetch(PDO::FETCH_ASSOC);

		if ($user) {
			//session_start();
			$_SESSION['user_id'] = $user['id'];
			$_SESSION['username'] = $user['username'];
			$_SESSION['user_role'] = $user['group_name'] ?? 'sin_grupo';
			$_SESSION['logged_in'] = true;

			// Opcional: Rotar el token (borrar el viejo y crear uno nuevo) por seguridad extra
			return true;
		}
	}
	return false;
}
function logout()
{
	session_start();
	session_unset(); // Vacía las variables
	session_destroy(); // Borra el archivo en el servidor
	setcookie('remember_me', '', time() - 3600, '/'); // Borra la cookie de "recuérdame"
}


/* UTILS */
function get_csrf_input()
{
	return '<input type="hidden" name="csrf_token" value="' . $_SESSION['csrf_token'] . '">';
}
function verify_csrf()
{
	if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
		die("Error de seguridad: Token CSRF inválido.");
	}
}

/* FUNCIONES VISTA */
function html_form_login($url_retorno)
{ //imprime formulario de login
	global $URL_LIBRERIA;
	echo "<form method='post' style='margin:auto;' action='" . $URL_LIBRERIA . "ena_login.php'> 
            " . get_csrf_input() . "
			<table style='width:auto; margin:auto;'> 
				<tr><td>Usuario: </td><td><input type='text' name='user' /></td></tr> 
				<tr><td>Password: </td><td><input type='password' name='password' /></td></tr> 
				<tr><td colspan='2' style='text-align:center;'><input type='hidden'  name='action' value='login' /><input type='hidden'  name='url_retorno' value='$url_retorno' /><input class='green-button' type='submit' value='Entrar' /></td></tr> 
			</table> 
		</form>";
}
function html_list_acciones_disponibles_usuario($url_retorno)
{ //imprime lista de links con las acciones disponibles para el usuario
	global $URL_LIBRERIA;
	if (!isset($_SESSION['logged_in']))
		return "Usuario no identificado.";
	$res = "<ul>";
	$res .= "<li><form id='ena_login_logout-form' action='" . $URL_LIBRERIA . "ena_login.php' method='POST' style='display: none;'>
				<input type='hidden' name='action' value='logout'>
				<input type='hidden' name='url_retorno' value='$url_retorno'>
			</form>
			<a href='#' onclick='event.preventDefault(); document.getElementById(\"ena_login_logout-form\").submit();'>
				Cerrar sesión
			</a></li>";


	if (is_user_in_group("administradores")) {
		$res .= "<li><form id='ena_login_panel_admin-form' action='" . $URL_LIBRERIA . "ena_login.php?content=panel_admin_users' method='POST' style='display: none;'>
				<input type='hidden' name='action' value='panel_admin'>
				<input type='hidden' name='url_retorno' value='$url_retorno'>
			</form>
			<a href='#' onclick='event.preventDefault(); document.getElementById(\"ena_login_panel_admin-form\").submit();'>
				Panel de administración
			</a></li>";
	}
	else {
		$res .= "<li><form id='ena_login_ajustes-form' action='" . $URL_LIBRERIA . "ena_login.php?content=panel_admin_user&id=" . $_SESSION['user_id'] . "' method='POST' style='display: none;'>
				<input type='hidden' name='url_retorno' value='$url_retorno'>
			</form>
			<a href='#' onclick='event.preventDefault(); document.getElementById(\"ena_login_ajustes-form\").submit();'>
				Ajustes
			</a></li>";
	}

	$res .= "</ul>";
	echo $res;
}
function html_header_admin()
{ //imprime el header del administrador
	echo "

    <!DOCTYPE html>
    <html lang='es'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <style>
            body { font-family: sans-serif; margin: 0; display: flex; flex-direction: column; height: 100vh; background: #f4f4f4; }
            header { background: #333; color: white; padding: 1rem; text-align: center; font-weight: bold; }
            
            .container { display: flex; flex: 1; flex-direction: row; overflow: hidden; }
            
            /* Menú Lateral */
            nav { background: #2c3e50; color: white; width: 250px; flex-shrink: 0; display: flex; flex-direction: column; }
            nav a { color: white; padding: 15px; text-decoration: none; border-bottom: 1px solid #34495e; transition: 0.3s; }
            nav a:hover { background: #34495e; }
            
            /* Zona Central */
            main { flex: 1; padding: 20px; overflow-y: auto; box-sizing: border-box; }
            .card { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); margin-bottom: 20px; overflow:auto; }

            /* Formularios Responsivos */
            .form-group { margin-bottom: 15px; display: flex; flex-direction: column; }
            label { margin-bottom: 5px; font-weight: bold; color: #555; }
            input, select, textarea { 
                padding: 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 16px; width: 100%; box-sizing: border-box; 
            }
            .button { background: #27ae60; color: white; border: none; padding: 10px 15px; border-radius: 4px; cursor: pointer; font-size: 16px; text-decoration:none; }
            .button:hover { background: #219150; }

            /* Ajustes Móvil */
            @media (max-width: 768px) {
                .container { flex-direction: column; }
                nav { width: 100%; flex-direction: row; flex-wrap: wrap; justify-content: space-around; }
                nav a { flex: 1; text-align: center; padding: 10px; font-size: 14px; }
                main { padding: 10px; }
            }
			/* Contenedor para scroll horizontal en móviles */
			.table-container { 
				width: 100%; 
				overflow-x: auto; 
				margin-top: 20px; 
				background: white; 
				border-radius: 8px;
			}

			table { 
				width: 100%; 
				border-collapse: collapse; 
				min-width: 600px; /* Asegura que en móvil no se amontone el texto */
			}

			th, td { 
				text-align: left; 
				padding: 12px 15px; 
				border-bottom: 1px solid #eee; 
			}

			th { 
				background: #f8f9fa; 
				color: #333; 
				text-transform: uppercase; 
				font-size: 12px; 
				letter-spacing: 1px; 
			}

			tr:hover { background: #f9f9f9; }

			/* Estilos para badges de grupos */
			.badge {
				background: #e1f5fe;
				color: #0288d1;
				padding: 4px 8px;
				border-radius: 4px;
				font-size: 12px;
				font-weight: bold;
				display: inline-block;
				margin: 2px;
			}

			/* Botones de acción en tabla */
			.btn-edit { background: #3498db; color: white; padding: 5px 10px; font-size: 12px; }
			.btn-delete { background: #e74c3c; color: white; padding: 5px 10px; font-size: 12px; margin-left: 5px; }

			/* Ajuste móvil: si la pantalla es muy pequeña, forzamos el scroll */
			@media (max-width: 768px) {
				th, td { padding: 10px; font-size: 14px; }
			}
			
			/* POPUP */
			/* El contenedor principal (fondo oscuro) */
			.modal-overlay {
				display: none; 
				position: fixed;
				top: 0; left: 0;
				width: 100%; height: 100%;
				background: rgba(0, 0, 0, 0.7);
				z-index: 1000;
				justify-content: center;
				align-items: center;
			}

			/* La caja blanca */
			.modal-content {
				background: white;
				padding: 20px;
				border-radius: 8px;
				width: 90%;
				max-width: 500px;
				position: relative;
				box-shadow: 0 5px 15px rgba(0,0,0,0.3);
			}

			/* Botón cerrar */
			.modal-close {
				position: absolute;
				top: 10px; right: 15px;
				font-size: 24px;
				cursor: pointer;
				color: #666;
			}
			.modal-close:hover { color: #000; }
			
        </style>
    </head>
    <body>

    <header>Administrador Login</header>
    <div class='container'>
        <nav>";
	if (is_user_in_group("administradores")) {
		echo "
            <a href='ena_login.php?content=panel_admin_users'>Usuarios</a>
            <a href='ena_login.php?content=panel_admin_groups'>Grupos</a>
            <a href='ena_login.php?content=panel_admin_perms'>Permisos</a>";
	}
	echo "<a href='" . ($_SESSION['url_retorno'] ?? "#") . "' style='background:#c0392b'>Salir</a>
        </nav>

        <main id='content-area'>
     
   ";

	if (isset($_GET['info'])) {
		echo "<div style='text-align:center; font-weight:bold; color:blue;'>" . htmlspecialchars($_GET['info']) . "</div>";
	}
	if (isset($_GET['err'])) {
		echo "<div style='text-align:center; font-weight:bold; color:red;'>" . htmlspecialchars($_GET['err']) . "</div>";
	}
	if (isset($_GET['success'])) {
		echo "<div style='text-align:center; font-weight:bold; color:green;'>" . htmlspecialchars($_GET['success']) . "</div>";
	}
}
function html_footer_admin()
{ //imprime el footer del administrador
	echo "
		   </main>
		</div>
		
		<div id='mi_modal' class='modal-overlay'>
			<div class='modal-content'>
				<span class='modal-close' onclick='cerrarPopup()'>&times;</span>
				<div id='contenido_popup'>
				</div>
			</div>
		</div>
		
	<script type='text/javascript'>
		function abrirPopup(html) {
			const modal = document.getElementById('mi_modal');
			const contenedor = document.getElementById('contenido_popup');
			
			contenedor.innerHTML = html;
			modal.style.display = 'flex'; // Usamos flex para centrarlo
			return contenedor;
		}

		function cerrarPopup() {
			document.getElementById('mi_modal').style.display = 'none';
		}

		// Cerrar si el usuario hace clic fuera de la caja blanca
		window.onclick = function(event) {
			const modal = document.getElementById('mi_modal');
			if (event.target == modal) {
				cerrarPopup();
			}
		}
	</script>	
		
    </body>
    </html>
	";

}

function mostrar_panel_admin_users()
{ //imprime la web de administrar usuarios

	html_header_admin();
	echo " 
	<div class='card'>
		<h2 style='text-align:center;'>Usuarios</h2>
	</div>
	<div class='card'>		
		<h3>Crear nuevo usuario:</h3>
		<form method='post' style='margin:auto;' action='ena_login.php?content=panel_admin_users'> 
            " . get_csrf_input() . "
			<table style='width:auto; margin:auto;'> 
				<tr><td>Usuario: </td><td><input type='text' name='user' /></td></tr> 
				<tr><td>Password: </td><td><input type='password' name='password' /></td></tr> 
				<tr><td>Email: </td><td><input type='text' name='email' /></td></tr> 
				<tr><td colspan='2' style='text-align:center;'><input type='hidden'  name='action' value='add_user' /><input type='hidden'  name='url_retorno' value='ena_login.php?content=panel_admin_users' /><input class='green-button' type='submit' value='Crear' /></td></tr> 
			</table> 
		</form>
    </div>";

	$usuarios = get_all_users_with_groups();

	echo "<div class='card'>";
	echo "<table style='width:90%; margin:auto;'>
    <thead>
        <tr>
            <th>Usuario</th>
            <th>Email</th>
            <th>Grupos</th>
            <th>Acciones</th>
        </tr>
    </thead>
    <tbody>";
	foreach ($usuarios as $u) {
		echo '<tr>
			<td>' . htmlspecialchars($u['username']) . '</td>
			<td>' . htmlspecialchars($u['email']) . '</td>
			<td>' . htmlspecialchars($u['groups_list'] ?? 'Sin grupo') . '</td>
			<td><a href="ena_login.php?content=panel_admin_user&id=' . htmlspecialchars($u['id']) . '" class="button">Editar</a></td>
		</tr>';
	}
	echo "</tbody></table>";
	echo "</div>";

	html_footer_admin();
}
function mostrar_panel_admin_groups()
{ //imprime la web de administrar grupos

	if (!is_user_in_group("administradores")) {
		echo "No tienes permiso para estar aquí!";
		exit();
	}
	html_header_admin();
	echo " 
	<div class='card'>
		<h2 style='text-align:center;'>Grupos</h2>
	</div>
	<div class='card'>		
		<h3>Crear nuevo grupo:</h3>
		<form method='post' style='margin:auto;' action='ena_login.php?content=panel_admin_groups'> 
            " . get_csrf_input() . "
			<table style='width:auto; margin:auto;'> 
				<tr><td>Grupo: </td><td><input type='text' name='grupo' /></td></tr> 
				<tr><td>Descripción: </td><td><textarea name='desc' ></textarea></td></tr> 
				<tr><td colspan='2' style='text-align:center;'><input type='hidden'  name='action' value='add_group' /><input type='hidden'  name='url_retorno' value='ena_login.php?content=panel_admin_groups' /><input class='green-button' type='submit' value='Crear' /></td></tr> 
			</table> 
		</form>
    </div>";

	$grupos = get_all_groups_with_users();

	echo "<div class='card'>";
	echo "<table style='width:90%; margin:auto;'>
    <thead>
        <tr>
            <th>Grupo</th>
            <th>Usuarios</th>
            <th>Acciones</th>
        </tr>
    </thead>
    <tbody>";

	foreach ($grupos as $g) {
		echo '<tr>
			<td title=\'' . htmlspecialchars($g['description'] ?? "Sin descripción") . '\'>' . htmlspecialchars($g['name']) . '</td>
			<td>' . htmlspecialchars($g['members_list'] ?? '(Sin miembros)') . '</td>
			<td><button>Ver</button></td>
		</tr>';
	}
	echo "</tbody></table>";
	echo "</div>";

	html_footer_admin();
}
function mostrar_panel_admin_perms()
{ //imprime la web de administrar permisos

	if (!is_user_in_group("administradores")) {
		echo "No tienes permiso para estar aquí!";
		exit();
	}
	html_header_admin();
	echo " 
	<div class='card'>
		<h2 style='text-align:center;'>Permisos</h2>
	</div>
	<div class='card'>		
		<h3>Crear nuevo permiso:</h3>
		<form method='post' style='margin:auto;' action='ena_login.php?content=panel_admin_perms'> 
            " . get_csrf_input() . "
			<table style='width:auto; margin:auto;'> 
				<tr><td>Permiso: </td><td><input type='text' name='perm' /></td></tr> 
				<tr><td>Categoría: </td><td><input type='text' name='cat' /></td></tr> 
				<tr><td>SubCategoría: </td><td><input type='text' name='sub' /></td></tr> 
				<tr><td>Descripción: </td><td><textarea name='desc' ></textarea></td></tr> 
				<tr><td colspan='2' style='text-align:center;'><input type='hidden'  name='action' value='add_perm' /><input type='hidden'  name='url_retorno' value='ena_login.php?content=panel_admin_perms' /><input class='green-button' type='submit' value='Crear' /></td></tr> 
			</table> 
		</form>
    </div>";

	$data = get_permissions_nested();

	foreach ($data as $catName => $subcategorias) {
		echo '<div class=\'card\'>';
		echo '<h2>Categoría: ' . htmlspecialchars($catName) . '</h2>';

		foreach ($subcategorias as $subName => $permisos) {
			echo '<div style=\'margin-left: 20px; border-left: 2px solid #eee; padding-left: 10px;\'>';
			echo '<h4>Subcategoría: ' . htmlspecialchars($subName) . '</h4>';

			foreach ($permisos as $p) {
				echo '<span class=\'permiso badge\' data-id=\'' . $p['id'] . '\' >' . htmlspecialchars($p['name']) . '</span>';
			}
			echo '</div>';
		}
		echo '</div>';
		echo "<script type='text/javascript'>
				document.querySelectorAll('.permiso').forEach(elemento => {
					elemento.addEventListener('click', function() {
						// Obtenemos el data-id de forma nativa
						const id = this.getAttribute('data-id'); 
						// O también puedes usar: const id = this.dataset.id;
						const titulo = this.getHTML();
						var contenedor = abrirPopup('<h2>'+titulo+'</h2>');
						contenedor.insertAdjacentHTML('beforeend','<a href=\"ena_login.php?action=remove_permision&id='+id+'&csrf_token=" . $_SESSION['csrf_token'] . "\" class=\"button\" style=\"background-color: #c0392b;\" >Eliminar</a>');
						
					});
				});
			</script>
		";
	}

	html_footer_admin();
}
function mostrar_panel_admin_user($id)
{ //imprime la web de administrar permisos

	html_header_admin();
	$u = get_user_by_id($id);
	echo " 
	<div class='card'>
		<h2 style='text-align:center;'>Usuario " . $u['username'] . "</h2>
	</div>
	<div class='card'>
		<h3>Modificar Datos:</h3>
		<form method='post' style='margin:auto;' action='ena_login.php?content=panel_admin_user&id=$id'> 
            " . get_csrf_input() . "
			<table style='width:auto; margin:auto;'> 
				<tr><td>Nombre: </td><td><input type='text' name='name' value='" . htmlspecialchars($u['username'], ENT_QUOTES) . "' /></td></tr> 
				<tr><td>Email: </td><td><input type='text' name='email' value='" . htmlspecialchars($u['email'], ENT_QUOTES) . "' /></td></tr> 
				<tr><td>Password actual: </td><td><input type='password' name='pass' /></td></tr>				
				<tr><td colspan='2' style='text-align:center;'><input type='hidden'  name='action' value='modify_user_details' /><input type='hidden'  name='id' value='$id' /><input type='hidden'  name='url_retorno' value='ena_login.php?content=panel_admin_user&id=$id' /><input class='green-button' type='submit' value='Modificar' /></td></tr> 
			</table> 
		</form>
    </div>
	
	<div class='card'>
		<h3>Modificar Password:</h3>
		<form method='post' style='margin:auto;' action='ena_login.php?content=panel_admin_user&id=$id'> 
            " . get_csrf_input() . "
			<table style='width:auto; margin:auto;'> 
				<tr><td>Password antiguo: </td><td><input type='password' name='old' /></td></tr> 
				<tr><td>Password Nuevo: </td><td><input type='password' name='new' /></td></tr> 
				<tr><td>Repite el nuevo password: </td><td><input type='password' name='repeat' /></td></tr>				
				<tr><td colspan='2' style='text-align:center;'><input type='hidden'  name='action' value='modify_user_psw' /><input type='hidden'  name='id' value='$id' /><input type='hidden'  name='url_retorno' value='ena_login.php?content=panel_admin_user&id=$id' /><input class='green-button' type='submit' value='Modificar' /></td></tr> 
			</table> 
		</form>
    </div>
	
	";

	if (is_user_in_group("administradores")) {
		$lista_permisos = get_permissions_nested();
		echo " 
		<div class='card'> 
			<h3>Roles del usuario:</h3>
		";
		$permisos_usuario = get_permissions_for_user_nested($id);

		foreach ($permisos_usuario as $catName => $subcategorias) {



			foreach ($subcategorias as $subName => $permisos) {


				foreach ($permisos as $p) {
					echo '<span class=\'permiso badge\' data-id=\'' . $p['id'] . '\' >' . htmlspecialchars($p['name']) . '</span>';
				}

			}

			echo "<script type='text/javascript'>
					document.querySelectorAll('.permiso').forEach(elemento => {
						elemento.addEventListener('click', function() {
							// Obtenemos el data-id de forma nativa
							const id = this.getAttribute('data-id'); 
							// O también puedes usar: const id = this.dataset.id;
							const titulo = this.getHTML();
							var contenedor = abrirPopup('<h2>Permiso <b>'+titulo+'</b> de <b>" . $u['username'] . "</b></h2>');
							contenedor.insertAdjacentHTML('beforeend','<a href=\"ena_login.php?action=remove_permision_from_user&user=" . $id . "&perm='+id+'&csrf_token=" . $_SESSION['csrf_token'] . "\" class=\"button\" style=\"background-color: #c0392b;\" >Eliminar</a>');
							
						});
					});
				</script>
			";
		}


		echo "<form method='post' action='ena_login.php' style='margin-top:30px;'>
                " . get_csrf_input() . "
				Añadir permiso a " . $u['username'] . ":
				<input type='hidden' name='action' value='add_perm_to_user' />
				<input type='hidden' name='user' value='$id' />
				<select name='perm'>";
		foreach ($lista_permisos as $catName => $subcategorias) {

			$cat = htmlspecialchars($catName);

			foreach ($subcategorias as $subName => $permisos) {

				$subcat = htmlspecialchars($subName);

				foreach ($permisos as $p) {
					echo "<option value='" . $p['id'] . "'>" . $cat . " > " . $subcat . " > " . $p['name'] . "</option>";
				}
			}

		}
		echo "</select>
				<input type='submit' value='+Asignar Permiso' />
			</form>
		</div>";
	}


	html_footer_admin();
}

//Aquí se resuelven las acciones
if ((php_sapi_name() !== 'cli') && ($_SERVER['REQUEST_METHOD'] === 'POST' || (isset($_GET['action'])))) {
	if (isset($_GET['action'])) {
		// Acciones críticas vía GET requieren token en URL
		$acciones_criticas = ['remove_permision', 'remove_permision_from_user', 'logout'];
		if (in_array($_GET['action'], $acciones_criticas)) {
			if (!isset($_GET['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_GET['csrf_token'])) {
				die("Error de seguridad: Token CSRF inválido.");
			}
		}
		$accion = $_GET['action'];
	}
	else if (isset($_POST['action'])) {
		verify_csrf(); // 🔒 Validar token en acciones POST
		$accion = $_POST['action'];
	}
	switch ($accion ?? '') {
		case 'login':
			$userInput = $_POST['user'] ?? '';
			$passInput = $_POST['password'] ?? '';

			if (login($userInput, $passInput)) {
				header("Location: " . $_POST['url_retorno']);
				exit;
			}
			else {
				$error = "Usuario o contraseña incorrectos.";
				echo $error;
			}
			break;
		case 'panel_admin':
			$_SESSION['url_retorno'] = $_POST['url_retorno'];
			break;
		case 'logout':
			logout();
			header("Location: " . $_POST['url_retorno']);
			break;
		case 'add_user':
			if (!is_user_in_group("administradores")) {
				echo "No tienes permiso para hacer esto!";
				break;
			}
			$res = create_user($_POST['user'], $_POST['email'], $_POST['password']);
			header("Location: " . $_POST['url_retorno']);
			break;
		case 'add_group':
			if (!is_user_in_group("administradores")) {
				echo "No tienes permiso para hacer esto!";
				break;
			}
			$res = create_group($_POST['grupo'], $_POST['desc']);
			header("Location: " . $_POST['url_retorno']);
			break;
		case 'add_perm':
			if (!is_user_in_group("administradores")) {
				echo "No tienes permiso para hacer esto!";
				break;
			}
			$res = create_perm($_POST['perm'], $_POST['cat'], $_POST['sub'], $_POST['desc']);
			header("Location: " . $_POST['url_retorno']);
			break;
		case 'modify_user_details':
			if (!is_user_in_group("administradores") && $_SESSION['user_id'] != $_GET['id']) {
				echo "No tienes permiso para hacer esto!";
				break;
			}
			if (!is_user_in_group("administradores") && !verify_user_password($_POST['id'], $_POST['pass'])) {
				header("Location: ena_login.php?content=panel_admin_user&err=Contraseña%20Incorrecta&id=" . $_POST['id']);
				;
				break;
			}

			if (update_user_basic_data($_POST['id'], $_POST['name'], $_POST['email']) > 0) {
				header("Location: ena_login.php?content=panel_admin_user&success=Modificado&id=" . $_POST['id']);

			}
			else {
				header("Location: ena_login.php?content=panel_admin_user&err=Error&id=" . $_POST['id']);
			}
			break;
		case 'modify_user_psw':
			if (!is_user_in_group("administradores") && $_SESSION['user_id'] != $_GET['id']) {
				echo "No tienes permiso para hacer esto!";
				break;
			}
			if (!is_user_in_group("administradores") && !verify_user_password($_POST['id'], $_POST['old'])) {
				header("Location: ena_login.php?content=panel_admin_user&err=Contraseña%20Incorrecta&id=" . $_POST['id']);
				break;
			}
			if (strlen($_POST['new']) < 8) {
				header("Location: ena_login.php?content=panel_admin_user&err=La%20contraseña%20es%20demasiado%20corta.&id=" . $_POST['id']);
				break;
			}
			if ($_POST['repeat'] != $_POST['new']) {
				header("Location: ena_login.php?content=panel_admin_user&err=Las%20contraseñas%20no%20coinciden.&id=" . $_POST['id']);
				break;
			}

			if (update_user_password($_POST['id'], $_POST['new'])) {
				header("Location: ena_login.php?content=panel_admin_user&success=Modificada&id=" . $_POST['id']);
			}
			else {
				header("Location: ena_login.php?content=panel_admin_user&err=Error&id=" . $_POST['id']);
			}
			break;
		case 'remove_permision':
			if (!is_user_in_group("administradores")) {
				echo "No tienes permiso para hacer esto!";
				break;
			}
			$id = $_GET['id'];
			remove_perm($id);
			header("Location: ena_login.php?content=panel_admin_perms");
			break;
		case 'add_perm_to_user':
			if (!is_user_in_group("administradores")) {
				echo "No tienes permiso para hacer esto!";
				break;
			}
			$user = $_GET['user'];
			$perm = $_GET['perm'];
			$res = add_perm_to_user($perm, $user);
			header("Location: ena_login.php?content=panel_admin_user&id=$user");
			break;
		case 'remove_permision_from_user':
			if (!is_user_in_group("administradores")) {
				echo "No tienes permiso para hacer esto!";
				break;
			}
			$user = $_GET['user'];
			$perm = $_GET['perm'];
			$res = remove_perm_from_user($perm, $user);
			header("Location: ena_login.php?content=panel_admin_user&id=$user");
			break;
		default:
			if (isset($_POST['url_retorno'])) {
				$_SESSION['url_retorno'] = $_POST['url_retorno'];
			}

	}
}


//Aquí se muestran las páginas de contenido
if (isset($_GET['content'])) {
	switch ($_GET['content']) {
		case 'panel_admin_users':
			if (!is_user_in_group("administradores")) {
				echo "No tienes permiso para estar aquí!";
				break;
			}
			mostrar_panel_admin_users();
			break;
		case 'panel_admin_user':
			if (!is_user_in_group("administradores") && $_SESSION['user_id'] != $_GET['id']) {
				echo "No tienes permiso para estar aquí!";
				break;
			}
			mostrar_panel_admin_user($_GET['id']);
			break;
		case 'panel_admin_groups':
			if (!is_user_in_group("administradores")) {
				echo "No tienes permiso para estar aquí!";
				break;
			}
			mostrar_panel_admin_groups();
			break;
		case 'panel_admin_perms':
			if (!is_user_in_group("administradores")) {
				echo "No tienes permiso para estar aquí!";
				break;
			}
			mostrar_panel_admin_perms();
			break;
		default:
			echo "Error al acceder a " . $_GET['content'];

	}
}

// --- ENRUTAMIENTO POR DEFECTO ---
if (!isset($_GET['content']) && !isset($_GET['action'])) {
	if (!isset($_SESSION['logged_in'])) {
		// 1. No logueado: Mostrar Formulario de Login
		html_header_admin();
		echo "<div class='card'><h2 style='text-align:center;'>Acceso al Sistema</h2>";
		html_form_login("ena_login.php");
		echo "</div>";
		html_footer_admin();
	}
	else {
		if (is_user_in_group("administradores")) {
			// 2. Administrador: Redirigir al Panel
			header("Location: ena_login.php?content=panel_admin_users");
			exit;
		}
		else {
			// 3. Usuario normal: Mostrar saludo y logout
			html_header_admin();
			echo "<div class='card' style='text-align:center;'>
                    <h2>¡Hola, " . htmlspecialchars($_SESSION['username']) . "!</h2>
                    <p>Has iniciado sesión correctamente en el sistema.</p>
                    <div style='margin-top:20px;'>
                        <a href='ena_login.php?action=logout&csrf_token=" . $_SESSION['csrf_token'] . "' class='button' style='background:#c0392b'>Cerrar Sesión</a>
                    </div>
                  </div>";
			html_footer_admin();
		}
	}
}
