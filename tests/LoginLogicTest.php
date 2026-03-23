<?php
use PHPUnit\Framework\TestCase;

class LoginLogicTest extends TestCase
{
    /**
     * Este método se ejecuta ANTES de cada test.
     * Ideal para limpiar la base de datos o resetear la sesión.
     */
    protected function setUp(): void
    {
        global $db, $DB_PATH;
        // Limpiamos tablas para tener un estado conocido
        $db->exec("DELETE FROM users");
        $db->exec("DELETE FROM groups");
        $db->exec("DELETE FROM user_groups");
        $db->exec("DELETE FROM permissions");
        $db->exec("DELETE FROM user_permissions");

        // Restaurar estado básico (admin, grupos)
        install_database($DB_PATH);

        is_user_in_group("", true); // Reseter cache estático
        
        $_SESSION = [];
        $_POST = [];
        $_GET = [];
    }

    public function testCheckPermisionReturnsFalseIfNotLoggedIn()
    {
        // No hay nada en $_SESSION
        $this->assertFalse(check_permision('ver_panel'));
    }

    public function testIsUserInGroupReturnsTrueIfInGroup()
    {
        global $db;

        // 1. Crear un usuario de prueba directamente en la DB de test
        create_user('testuser', 'test@example.com', 'password123');
        $userId = $db->lastInsertId();

        // 2. Crear un grupo
        create_group('Editores', 'Gente que edita');
        $groupId = $db->lastInsertId();

        // 3. Asignar usuario al grupo
        $db->prepare("INSERT INTO user_groups (user_id, group_id) VALUES (?, ?)")
            ->execute([$userId, $groupId]);

        // 4. Simular login en la sesión
        $_SESSION['logged_in'] = true;
        $_SESSION['user_id'] = $userId;
        $_SESSION['username'] = 'testuser';

        // 5. Verificar
        $this->assertTrue(is_user_in_group('Editores'));
    }

    public function testLoginActionWithPost()
    {
        global $db;
        
        // 1. Crear un usuario real en la base de datos de test
        create_user('victor', 'victor@test.com', 'mi_password_segura');
        
        // 2. Simulamos el envío del formulario de login
        $_POST['action'] = 'login';
        $_POST['user'] = 'victor';
        $_POST['password'] = 'mi_password_segura';
        $_POST['url_retorno'] = 'dashboard.php';
        
        // 3. Ejecutamos el controlador de acciones
        $resultado = handle_actions();
        
        // 4. Verificamos que el controlador nos diga que el login fue exitoso
        $this->assertEquals('login_success', $resultado);
        $this->assertTrue($_SESSION['logged_in']);
        $this->assertEquals('victor', $_SESSION['username']);
    }

    public function testLoginActionFailsWithWrongPassword()
    {
        // 1. Crear usuario
        create_user('pedro', 'pedro@test.com', '123456');
        
        // 2. Simular POST con password incorrecta
        $_POST['action'] = 'login';
        $_POST['user'] = 'pedro';
        $_POST['password'] = 'password_erronea';
        
        // 3. Ejecutar
        $resultado = handle_actions();
        
        // 4. Verificar fallo
        $this->assertEquals('login_failed', $resultado);
        $this->assertArrayNotHasKey('logged_in', $_SESSION);
    }

    public function testAddUserActionAsAdmin()
    {
        global $db;
        
        // 1. Somos el admin real creado en install_database
        $_SESSION['logged_in'] = true;
        $adminId = $db->query("SELECT id FROM users WHERE username = 'admin'")->fetchColumn();
        $_SESSION['user_id'] = $adminId;
        is_user_in_group("", true); // Forzar recarga de grupos
        
        // 2. Simular POST para crear un segundo usuario
        $_POST['action'] = 'add_user';
        $_POST['user'] = 'nuevo_empleado';
        $_POST['email'] = 'empleado@test.com';
        $_POST['password'] = 'emp12345';
        $_POST['url_retorno'] = 'admin.php';
        
        // 3. Ejecutar
        $resultado = handle_actions();
        
        // 4. Verificar
        $this->assertEquals('user_created', $resultado);
        
        // Comprobar que existe en la DB
        $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE username = 'nuevo_empleado'");
        $stmt->execute();
        $this->assertEquals(1, $stmt->fetchColumn());
    }
}
