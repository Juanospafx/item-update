<?php
session_start();

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $action !== 'download_template') {
    header('Location: configuracion.php');
    exit();
}

// Asegurar token CSRF en la sesión
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Verificación CSRF obligatoria para todas las solicitudes POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted_token = $_POST['csrf_token'] ?? '';
    if (empty($submitted_token) || !hash_equals($_SESSION['csrf_token'], $submitted_token)) {
        http_response_code(403);
        die("Error de Seguridad: Solicitud no autorizada (CSRF Token inválido o expirado).");
    }
}

// --- HELPER: Python Executable ---
function get_python_bin() {
    $venv_win = realpath(__DIR__ . '/../.venv/Scripts/python.exe');
    $venv_nix = realpath(__DIR__ . '/../.venv/bin/python');
    if ($venv_win && file_exists($venv_win)) {
        return '"' . $venv_win . '"';
    }
    if ($venv_nix && file_exists($venv_nix)) {
        return '"' . $venv_nix . '"';
    }
    return 'python';
}

// --- HELPER: Procesar Archivos ---
function process_category_files($category, $files, $is_new_category = false) {
    $messages = [];
    
    // 1. Procesar Plantilla (Template)
    if (isset($files['template_file']) && $files['template_file']['error'] !== UPLOAD_ERR_NO_FILE) {
        $err = $files['template_file']['error'];
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            throw new Exception("El archivo de plantilla supera el tamaño máximo permitido por el servidor.");
        }
        if ($err !== UPLOAD_ERR_OK) {
            throw new Exception("Error al subir el archivo de plantilla (código de error: $err).");
        }

        $ext = strtolower(pathinfo($files['template_file']['name'], PATHINFO_EXTENSION));
        if ($ext !== 'xlsx') {
            throw new Exception("La plantilla debe ser un archivo de Excel con extensión .xlsx.");
        }
        
        $target_dir = __DIR__ . '/../data/excel_templates/';
        if (!file_exists($target_dir)) mkdir($target_dir, 0777, true);
        
        $filename = "Plantilla_" . $category . ".xlsx";
        $target_path = $target_dir . $filename;
        
        if (move_uploaded_file($files['template_file']['tmp_name'], $target_path)) {
            $messages[] = "Plantilla guardada.";
        } else {
            throw new Exception("No se pudo guardar la plantilla en el servidor. Verifica los permisos de 'data/excel_templates'.");
        }
    } elseif ($is_new_category) {
        throw new Exception("Es obligatorio adjuntar la plantilla Excel (.xlsx) para crear una categoría nueva.");
    }

    // 2. Procesar Diccionario (Importar a DB)
    if (isset($files['dictionary_file']) && $files['dictionary_file']['error'] !== UPLOAD_ERR_NO_FILE) {
        $err = $files['dictionary_file']['error'];
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            throw new Exception("El archivo de diccionario supera el tamaño máximo permitido por el servidor.");
        }
        if ($err !== UPLOAD_ERR_OK) {
            throw new Exception("Error al subir el archivo de diccionario (código de error: $err).");
        }

        $ext = strtolower(pathinfo($files['dictionary_file']['name'], PATHINFO_EXTENSION));
        if ($ext !== 'xlsx') {
            throw new Exception("El archivo de diccionario debe tener extensión .xlsx.");
        }

        $tmp_path = $files['dictionary_file']['tmp_name'];
        $cmd = get_python_bin() . " \"../scripts/import_dictionary_single.py\" " . escapeshellarg($tmp_path) . " " . escapeshellarg($category);
        $output = [];
        $return_var = 0;
        exec($cmd . " 2>&1", $output, $return_var);
        
        if ($return_var !== 0) {
            $err_text = "Error al procesar el archivo Excel del diccionario.";
            foreach ($output as $line) {
                if (str_contains($line, '[ERROR]') || str_contains($line, '[ERROR CRITICO]')) {
                    $err_text = trim(str_replace(['[ERROR]', '[ERROR CRITICO]'], '', $line));
                    break;
                }
            }
            throw new Exception("Error en Diccionario: " . $err_text);
        }
        
        $messages[] = "Diccionario importado.";
    } elseif ($is_new_category) {
        throw new Exception("Es obligatorio adjuntar el archivo de Diccionario Excel (.xlsx) para crear una categoría nueva.");
    }

    return implode(" ", $messages);
}

require_once __DIR__ . '/db.php';

// --- HELPER: Guardar Configuración ---
function save_config($pdo, $key, $value) {
    set_config_value($key, $value);
}

try {
    $pdo = get_db_connection();

    switch ($action) {
        case 'save_procore':
            $email = trim($_POST['email'] ?? '');
            $pass = trim($_POST['password'] ?? '');
            $is_headless = isset($_POST['headless']) && ($_POST['headless'] === '1' || $_POST['headless'] === 'true');
            $headless_arg = $is_headless ? ' --headless' : '';

            if (empty($email) || empty($pass)) throw new Exception("Email y contraseña requeridos.");

            // Verificar con el script de procore (soporta headless o ventana visible)
            $cmd = get_python_bin() . " \"../scripts/verify_procore_login.py\" " . escapeshellarg($email) . " " . escapeshellarg($pass) . $headless_arg;
            $out = [];
            $ret = -1;
            exec($cmd, $out, $ret);
            
            if ($ret !== 0) {
                $err_detail = '';
                foreach ($out as $line) {
                    if (str_contains($line, '[ERROR]')) {
                        $err_detail = trim(str_replace('[ERROR]', '', $line));
                        break;
                    }
                }
                $msg = $err_detail ? "Verificación fallida: $err_detail" : "Verificación fallida: Credenciales de Procore incorrectas o error de conexión.";
                throw new Exception($msg);
            }
            
            save_config($pdo, 'procore_email', $email);
            save_config($pdo, 'procore_password', $pass);
            
            header('Location: configuracion.php?success=Cuenta Procore verificada y guardada exitosamente.');
            break;

        case 'delete_procore':
            $stmt = $pdo->prepare("DELETE FROM app_config WHERE key IN ('procore_email', 'procore_password')");
            $stmt->execute();
            header('Location: configuracion.php?success=Credenciales de Procore eliminadas correctamente.');
            break;

        case 'add':
            // Este caso maneja TANTO añadir nueva categoría (con archivos) COMO añadir URL a existente
            $category_raw = trim($_POST['category'] ?? '');
            $url = trim($_POST['url'] ?? '');
            if (empty($category_raw) || empty($url)) {
                throw new Exception("Categoría y URL son obligatorias.");
            }

            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                throw new Exception("La URL ingresada no tiene un formato web válido.");
            }
            
            // Validar que la URL sea legítima del dominio rexelusa.com
            $parsed_host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
            if (!$parsed_host || ($parsed_host !== 'rexelusa.com' && !str_ends_with($parsed_host, '.rexelusa.com'))) {
                throw new Exception("Solo se permiten URLs legítimas del dominio rexelusa.com");
            }

            // Validar caracteres de la categoría
            if (preg_match('/[\/\\\\:\*\?"<>\|]/', $category_raw)) {
                throw new Exception("El nombre de la categoría contiene caracteres no permitidos (evita / \\ : * ? \" < > |).");
            }

            // Normalizar el nombre de la categoría para consistencia con el scraper
            if (strtolower($category_raw) === 'wires') {
                $category = 'Wires';
            } else {
                $category = strtoupper($category_raw);
            }

            // Detectar si proviene del formulario de "Nueva Categoría" (tiene campos de archivo en el form)
            $is_new_category_form = isset($_FILES['template_file']) || isset($_FILES['dictionary_file']);

            if ($is_new_category_form) {
                // Verificar si la categoría ya existe en el sistema
                $stmt_cat = $pdo->prepare("SELECT COUNT(*) FROM scraping_urls WHERE category = ?");
                $stmt_cat->execute([$category]);
                if ($stmt_cat->fetchColumn() > 0) {
                    throw new Exception("La categoría ya existe: Ya hay un catálogo configurado con el nombre '{$category}'. Puedes añadir más URLs directamente en su tarjeta en la lista inferior.");
                }
            }

            // Verificar si la URL ya existe en la base de datos antes de insertar
            $stmt_url = $pdo->prepare("SELECT category FROM scraping_urls WHERE url = ?");
            $stmt_url->execute([$url]);
            $existing_owner = $stmt_url->fetchColumn();
            if ($existing_owner) {
                throw new Exception("UNIQUE constraint failed: scraping_urls.url");
            }

            // Iniciar transacción para garantizar consistencia atómica
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("INSERT INTO scraping_urls (category, url) VALUES (?, ?)");
                $stmt->execute([$category, $url]);

                // Procesar archivos si corresponden a una nueva categoría
                $msg_files = '';
                if ($is_new_category_form) {
                    $msg_files = process_category_files($category, $_FILES, true);
                }

                $pdo->commit();
            } catch (Throwable $t) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                // Si se alcanzó a mover la plantilla y falló el diccionario, limpiar archivo residual
                if ($is_new_category_form) {
                    $orphan_tpl = __DIR__ . '/../data/excel_templates/Plantilla_' . $category . '.xlsx';
                    if (file_exists($orphan_tpl)) @unlink($orphan_tpl);
                }
                throw $t;
            }
            
            $success_msg = $is_new_category_form ? "Nueva categoría '{$category}' creada correctamente. {$msg_files}" : "URL añadida a {$category} correctamente.";
            header('Location: configuracion.php?success=' . urlencode(trim($success_msg)) . '&open_dict=' . urlencode($category));
            break;

        case 'upload_files':
            $category_raw = trim($_POST['category'] ?? '');
            if (empty($category_raw)) throw new Exception("Categoría requerida.");
            
            // Normalizar (aunque debería venir bien del hidden input)
            $category = (strtolower($category_raw) === 'wires') ? 'Wires' : strtoupper($category_raw);

            $msg = process_category_files($category, $_FILES, false);
            header('Location: configuracion.php?success=Archivos procesados: ' . urlencode($msg) . '&open_dict=' . urlencode($category));
            break;

        case 'delete':
            $url_id = filter_var($_POST['url_id'] ?? '', FILTER_VALIDATE_INT);
            if (!$url_id) {
                throw new Exception("ID de URL inválido.");
            }
            $stmt = $pdo->prepare("DELETE FROM scraping_urls WHERE id = ?");
            $stmt->execute([$url_id]);
            header('Location: configuracion.php?success=URL eliminada correctamente.');
            break;

        case 'delete_category':
            $category_raw = trim($_POST['category'] ?? '');
            $delete_dict = isset($_POST['delete_dictionary']) && $_POST['delete_dictionary'] === '1';

            if (empty($category_raw)) {
                throw new Exception("Nombre de categoría requerido.");
            }

            $category = (strtolower($category_raw) === 'wires') ? 'Wires' : strtoupper($category_raw);

            // 1. Borrar URLs de scraping para esta categoría
            $stmt = $pdo->prepare("DELETE FROM scraping_urls WHERE category = ?");
            $stmt->execute([$category]);
            
            // 2. Borrar Diccionario (Mapeo y Catálogo) si se solicita (Borrar Todo)
            if ($delete_dict) {
                $pdo->prepare("DELETE FROM mapeo_procore WHERE web_item_id IN (SELECT id FROM catalogo_web WHERE categoria = ?)")->execute([$category]);
                $pdo->prepare("DELETE FROM catalogo_web WHERE categoria = ?")->execute([$category]);
            }

            // Opcional: Borrar plantilla asociada para mantener limpieza
            $template_path = __DIR__ . '/../data/excel_templates/Plantilla_' . $category . '.xlsx';
            if (file_exists($template_path)) unlink($template_path);
            
            $msg = $delete_dict ? "Categoría y diccionario eliminados correctamente." : "Categoría eliminada (URLs y plantilla borradas).";
            header('Location: configuracion.php?success=' . urlencode($msg));
            break;

        case 'toggle':
            $url_id = filter_var($_POST['url_id'] ?? '', FILTER_VALIDATE_INT);
            if (!$url_id) {
                throw new Exception("ID de URL inválido.");
            }
            $stmt = $pdo->prepare("UPDATE scraping_urls SET is_active = 1 - is_active WHERE id = ?");
            $stmt->execute([$url_id]);
            header('Location: configuracion.php?success=Estado de la URL actualizado.');
            break;
            
        case 'download_template':
            // Generar un nombre temporal seguro
            $temp_file = tempnam(sys_get_temp_dir(), 'dict_tpl_');
            $xlsx_file = $temp_file . '.xlsx';
            
            // Generar Excel con Python y Pandas para asegurar formato correcto
            // Columnas solicitadas: Procore_List (A), Rexel_List (B)
            $cmd = get_python_bin() . " -c \"import pandas as pd; df = pd.DataFrame(columns=['Procore_List', 'Rexel_List']); df.to_excel(r'$xlsx_file', index=False)\"";
            exec($cmd);
            
            if (file_exists($xlsx_file)) {
                header('Content-Description: File Transfer');
                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header('Content-Disposition: attachment; filename="Plantilla_Diccionario_Base.xlsx"');
                header('Expires: 0');
                header('Cache-Control: must-revalidate');
                header('Pragma: public');
                header('Content-Length: ' . filesize($xlsx_file));
                readfile($xlsx_file);
                
                // Limpieza
                unlink($xlsx_file);
                if (file_exists($temp_file)) unlink($temp_file);
                exit;
            } else {
                throw new Exception("Error al generar la plantilla de Excel.");
            }
            break;

        default:
            throw new Exception("Acción no reconocida.");
    }

} catch (Throwable $e) {
    $classified = classify_error($e, $pdo ?? null, [
        'url' => $_POST['url'] ?? '',
        'category' => $_POST['category'] ?? '',
        'action' => $action ?? ''
    ]);
    $_SESSION['flash_error'] = $classified;
    header('Location: configuracion.php?error=' . urlencode($classified['message']));
}

exit();