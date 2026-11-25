<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Strict'
]);
session_start();

function respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function readJsonBody(): array
{
    $rawBody = file_get_contents('php://input');
    if ($rawBody === false || strlen($rawBody) > 16384) {
        respond(400, ['error' => 'A solicitação está vazia ou excede o tamanho permitido.']);
    }

    try {
        $body = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        respond(400, ['error' => 'Os dados enviados não são um JSON válido.']);
    }

    if (!is_array($body)) {
        respond(400, ['error' => 'O formato dos dados enviados é inválido.']);
    }

    return $body;
}

function requiredText(array $data, string $field, int $maxLength, string $label): string
{
    $value = $data[$field] ?? null;
    if (!is_string($value)) {
        respond(422, ['error' => "Informe $label."]);
    }

    $value = trim($value);
    if ($value === '' || preg_match('/^.{1,' . $maxLength . '}$/us', $value) !== 1) {
        respond(422, ['error' => "$label deve ter entre 1 e $maxLength caracteres."]);
    }

    return $value;
}

function formatCpf(string $cpf): string
{
    return preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $cpf);
}

function requireEmployee(): void
{
    if (!isset($_SESSION['employee_id'])) {
        respond(401, ['error' => 'Entre com sua conta de funcionário para continuar.']);
    }
}

function validatePassword(array $data): string
{
    $password = $data['password'] ?? null;
    if (!is_string($password) || strlen($password) < 8 || strlen($password) > 72) {
        respond(422, ['error' => 'A senha deve ter entre 8 e 72 caracteres.']);
    }

    return $password;
}

function saveProductImage(): string
{
    $file = $_FILES['image'] ?? null;
    if (!is_array($file) || !isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        respond(422, ['error' => 'Selecione uma imagem de produto com até 5 MB.']);
    }

    if (!isset($file['tmp_name'], $file['size']) || !is_uploaded_file($file['tmp_name'])
        || $file['size'] < 1 || $file['size'] > 5 * 1024 * 1024) {
        respond(422, ['error' => 'A imagem deve ter no máximo 5 MB.']);
    }

    $imageInfo = getimagesize($file['tmp_name']);
    $extensions = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_WEBP => 'webp',
        IMAGETYPE_GIF => 'gif'
    ];
    if ($imageInfo === false || !isset($extensions[$imageInfo[2]])) {
        respond(422, ['error' => 'Use uma imagem JPG, PNG, WEBP ou GIF válida.']);
    }

    $uploadDirectory = __DIR__ . DIRECTORY_SEPARATOR . 'uploads';
    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
        throw new RuntimeException('Não foi possível criar a pasta de imagens.');
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$imageInfo[2]];
    if (!move_uploaded_file($file['tmp_name'], $uploadDirectory . DIRECTORY_SEPARATOR . $filename)) {
        throw new RuntimeException('Não foi possível salvar a imagem enviada.');
    }

    return 'uploads/' . $filename;
}

function removeProductImage(?string $imagePath): void
{
    if ($imagePath === null || preg_match('/\Auploads\/[a-f0-9]{32}\.(?:jpg|png|webp|gif)\z/', $imagePath) !== 1) {
        return;
    }

    $filename = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . basename($imagePath);
    if (is_file($filename) && !unlink($filename)) {
        error_log('Não foi possível remover a imagem do produto: ' . $filename);
    }
}

try {
    $configuredPath = getenv('MINI_LOJA_DB_PATH');
    $databasePath = $configuredPath !== false && $configuredPath !== ''
        ? $configuredPath
        : dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Mini-Loja-data' . DIRECTORY_SEPARATOR . 'loja.sqlite';
    $databaseDirectory = dirname($databasePath);

    if (!is_dir($databaseDirectory) && !mkdir($databaseDirectory, 0770, true) && !is_dir($databaseDirectory)) {
        throw new RuntimeException('Não foi possível criar o diretório do banco de dados.');
    }

    $database = new PDO('sqlite:' . $databasePath);
    $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $database->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $database->exec('PRAGMA foreign_keys = ON');
    $database->exec(
        'CREATE TABLE IF NOT EXISTS products (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            price_cents INTEGER NOT NULL CHECK (price_cents > 0),
            description TEXT NOT NULL,
            illustration TEXT NOT NULL
        )'
    );
    $database->exec(
        'CREATE TABLE IF NOT EXISTS employees (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            cpf TEXT NOT NULL UNIQUE,
            email TEXT NOT NULL,
            password_hash TEXT
        )'
    );
    $productColumns = $database->query('PRAGMA table_info(products)')->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('image_path', $productColumns, true)) {
        $database->exec('ALTER TABLE products ADD COLUMN image_path TEXT');
    }
    $employeeColumns = $database->query('PRAGMA table_info(employees)')->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('password_hash', $employeeColumns, true)) {
        $database->exec('ALTER TABLE employees ADD COLUMN password_hash TEXT');
    }
    $database->exec('CREATE TABLE IF NOT EXISTS app_settings (setting TEXT PRIMARY KEY, value TEXT NOT NULL)');

    $database->beginTransaction();
    $seedMarker = $database->prepare(
        "INSERT OR IGNORE INTO app_settings (setting, value) VALUES ('catalog_seeded', '1')"
    );
    $seedMarker->execute();
    if ($seedMarker->rowCount() > 0) {
        $starterProducts = [
            ['Camiseta Aurora', 4590, 'Camiseta macia com estampa inspirada no nascer do sol.', 'shirt'],
            ['Caneca Horizonte', 2890, 'Caneca de cerâmica para acompanhar suas pausas.', 'mug'],
            ['Caderno Botânico', 2490, 'Caderno pautado para ideias, planos e anotações.', 'notebook'],
            ['Garrafa Nômade', 7290, 'Garrafa térmica para manter sua bebida na temperatura ideal.', 'bottle'],
            ['Bolsa Feira Livre', 3390, 'Bolsa reutilizável, resistente e pronta para o dia a dia.', 'tote'],
            ['Estojo Duo', 1990, 'Estojo compacto com espaço para seus itens essenciais.', 'case'],
            ['Boné Brisa', 4890, 'Boné ajustável com acabamento bordado.', 'cap'],
            ['Chaveiro Estrela', 1290, 'Chaveiro leve para dar um toque especial às suas chaves.', 'keyring'],
            ['Planner Semanal', 4290, 'Organize compromissos e metas ao longo da semana.', 'calendar'],
            ['Mochila Trilho', 10990, 'Mochila versátil para estudos, trabalho e passeios.', 'backpack']
        ];
        $insertProduct = $database->prepare(
            'INSERT INTO products (name, price_cents, description, illustration) VALUES (?, ?, ?, ?)'
        );
        foreach ($starterProducts as $product) {
            $insertProduct->execute($product);
        }
    }
    $database->commit();

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'GET') {
        $products = $database->query(
            'SELECT id, name, price_cents, description, illustration, image_path FROM products ORDER BY id'
        )->fetchAll();
        foreach ($products as &$product) {
            $product['id'] = (int) $product['id'];
            $product['price'] = (int) $product['price_cents'] / 100;
            unset($product['price_cents']);
        }
        unset($product);

        $authenticatedEmployee = null;
        $employees = [];
        if (isset($_SESSION['employee_id'])) {
            $findEmployee = $database->prepare('SELECT id, name, email FROM employees WHERE id = ?');
            $findEmployee->execute([$_SESSION['employee_id']]);
            $authenticatedEmployee = $findEmployee->fetch();
            if ($authenticatedEmployee === false) {
                $_SESSION = [];
                session_destroy();
                $authenticatedEmployee = null;
            } else {
                $authenticatedEmployee['id'] = (int) $authenticatedEmployee['id'];
                $employees = $database->query('SELECT id, name, cpf, email FROM employees ORDER BY id')->fetchAll();
                foreach ($employees as &$employee) {
                    $employee['id'] = (int) $employee['id'];
                    $employee['cpf'] = formatCpf($employee['cpf']);
                }
                unset($employee);
            }
        }

        respond(200, [
            'products' => $products,
            'authenticated' => $authenticatedEmployee !== null,
            'employee' => $authenticatedEmployee,
            'employees' => $employees
        ]);
    }

    if ($method === 'POST') {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        $body = str_starts_with($contentType, 'multipart/form-data')
            ? $_POST
            : readJsonBody();
        $type = $body['type'] ?? '';

        if ($type === 'login') {
            $email = requiredText($body, 'email', 120, 'o e-mail');
            $password = validatePassword($body);
            $findEmployee = $database->prepare(
                'SELECT id, name, email, password_hash FROM employees WHERE lower(email) = lower(?) LIMIT 1'
            );
            $findEmployee->execute([$email]);
            $employee = $findEmployee->fetch();
            if ($employee === false || $employee['password_hash'] === null
                || !password_verify($password, $employee['password_hash'])) {
                respond(401, ['error' => 'E-mail ou senha inválidos.']);
            }

            session_regenerate_id(true);
            $_SESSION['employee_id'] = (int) $employee['id'];
            respond(200, [
                'authenticated' => true,
                'employee' => ['id' => (int) $employee['id'], 'name' => $employee['name'], 'email' => $employee['email']]
            ]);
        }

        if ($type === 'employee') {
            $name = requiredText($body, 'name', 100, 'o nome do funcionário');
            $email = strtolower(requiredText($body, 'email', 120, 'o e-mail'));
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                respond(422, ['error' => 'Informe um e-mail válido.']);
            }
            $password = validatePassword($body);
            $cpfValue = $body['cpf'] ?? null;
            if (!is_string($cpfValue)) {
                respond(422, ['error' => 'Informe um CPF com 11 números.']);
            }
            $cpf = preg_replace('/\D/', '', $cpfValue);
            if (strlen($cpf) !== 11) {
                respond(422, ['error' => 'Informe um CPF com 11 números.']);
            }

            $existingEmployee = $database->prepare(
                'SELECT id, name, cpf, email, password_hash FROM employees WHERE cpf = ? OR lower(email) = lower(?) LIMIT 1'
            );
            $existingEmployee->execute([$cpf, $email]);
            $existing = $existingEmployee->fetch();
            if ($existing !== false) {
                if ($existing['password_hash'] === null && $existing['cpf'] === $cpf
                    && strcasecmp($existing['email'], $email) === 0) {
                    $updatePassword = $database->prepare('UPDATE employees SET password_hash = ? WHERE id = ?');
                    $updatePassword->execute([password_hash($password, PASSWORD_DEFAULT), $existing['id']]);
                    $employeeId = (int) $existing['id'];
                    session_regenerate_id(true);
                    $_SESSION['employee_id'] = $employeeId;
                    respond(200, ['id' => $employeeId]);
                }
                respond(409, ['error' => 'Este CPF ou e-mail já está cadastrado.']);
            }

            $insert = $database->prepare(
                'INSERT INTO employees (name, cpf, email, password_hash) VALUES (?, ?, ?, ?)'
            );
            $insert->execute([$name, $cpf, $email, password_hash($password, PASSWORD_DEFAULT)]);
            $employeeId = (int) $database->lastInsertId();
            session_regenerate_id(true);
            $_SESSION['employee_id'] = $employeeId;
            respond(201, ['id' => $employeeId]);
        }

        if ($type === 'logout') {
            $_SESSION = [];
            session_destroy();
            setcookie(session_name(), '', ['expires' => time() - 42000, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict']);
            respond(200, ['authenticated' => false]);
        }

        if ($type === 'product') {
            requireEmployee();
            $name = requiredText($body, 'name', 80, 'o nome do produto');
            $description = requiredText($body, 'description', 240, 'a descrição do produto');
            $price = $body['price'] ?? null;
            if (!is_numeric($price) || !is_finite((float) $price) || (float) $price <= 0 || (float) $price > 9999999.99) {
                respond(422, ['error' => 'Informe um preço maior que zero e menor que R$ 10.000.000,00.']);
            }

            $priceCents = (int) round((float) $price * 100);
            $imagePath = saveProductImage();
            $insert = $database->prepare(
                'INSERT INTO products (name, price_cents, description, illustration, image_path) VALUES (?, ?, ?, ?, ?)'
            );
            try {
                $insert->execute([$name, $priceCents, $description, 'package', $imagePath]);
            } catch (Throwable $error) {
                unlink(__DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $imagePath));
                throw $error;
            }
            respond(201, ['id' => (int) $database->lastInsertId()]);
        }

        respond(400, ['error' => 'Tipo de cadastro inválido.']);
    }

    if ($method === 'DELETE') {
        requireEmployee();
        $type = $_GET['type'] ?? '';
        $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
        if (!in_array($type, ['product', 'employee'], true) || $id === false || $id < 1) {
            respond(400, ['error' => 'O cadastro solicitado para remoção é inválido.']);
        }

        $table = $type === 'product' ? 'products' : 'employees';
        $imagePath = null;
        if ($type === 'product') {
            $findProduct = $database->prepare('SELECT image_path FROM products WHERE id = ?');
            $findProduct->execute([$id]);
            $product = $findProduct->fetch();
            if ($product === false) {
                respond(404, ['error' => 'O cadastro não foi encontrado.']);
            }
            $imagePath = $product['image_path'];
        }

        $delete = $database->prepare("DELETE FROM $table WHERE id = ?");
        $delete->execute([$id]);
        if ($delete->rowCount() === 0) {
            respond(404, ['error' => 'O cadastro não foi encontrado.']);
        }

        if ($type === 'product') {
            removeProductImage($imagePath);
        }

        respond(200, ['deleted' => true]);
    }

    header('Allow: GET, POST, DELETE');
    respond(405, ['error' => 'Método não permitido.']);
} catch (PDOException $error) {
    error_log('Falha no banco da loja: ' . $error->getMessage());
    if ($error->getCode() === '23000') {
        respond(409, ['error' => 'Este CPF ou e-mail já está cadastrado.']);
    }
    respond(500, ['error' => 'Não foi possível acessar o banco de dados. Verifique a configuração do PHP e do SQLite.']);
} catch (Throwable $error) {
    error_log('Falha na API da loja: ' . $error->getMessage());
    respond(500, ['error' => 'Ocorreu um erro interno ao processar a solicitação.']);
}