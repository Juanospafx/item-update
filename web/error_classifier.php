<?php
/**
 * error_classifier.php - Clasificador de Errores Amigables para el Usuario
 * 
 * Convierte excepciones de base de datos (PDO/SQLite), errores de subida de archivos,
 * violaciones de unicidad (UNIQUE constraint failed) y reglas de negocio en mensajes
 * explicativos, humanos y con recomendaciones prácticas.
 */

if (!function_exists('classify_error')) {
    /**
     * Clasifica un error técnico y devuelve un arreglo estructurado listo para la UI.
     * 
     * @param Throwable|string $error La excepción o cadena de error original.
     * @param PDO|null $pdo Conexión PDO activa para consultas diagnósticas (opcional).
     * @param array $context Datos adicionales (url, category, action, etc.).
     * @return array [
     *     'title'   => string,
     *     'message' => string,
     *     'tip'     => string,
     *     'type'    => 'error'|'warning'|'info',
     *     'code'    => string
     * ]
     */
    function classify_error($error, ?PDO $pdo = null, array $context = []): array
    {
        $raw_msg = ($error instanceof Throwable) ? $error->getMessage() : (string)$error;
        
        $url = trim($context['url'] ?? '');
        $category = trim($context['category'] ?? '');

        // 1. UNIQUE CONSTRAINT: scraping_urls.url
        if (str_contains($raw_msg, 'UNIQUE constraint failed: scraping_urls.url') || 
            (str_contains($raw_msg, 'UNIQUE constraint failed') && str_contains($raw_msg, 'url')) ||
            (str_contains($raw_msg, '23000') && str_contains(strtolower($raw_msg), 'scraping_urls'))) {
            
            $existing_category = null;
            if ($pdo && !empty($url)) {
                try {
                    $st = $pdo->prepare("SELECT category FROM scraping_urls WHERE url = ?");
                    $st->execute([$url]);
                    $existing_category = $st->fetchColumn();
                } catch (Throwable $t) {}
            }
            
            if ($existing_category) {
                return [
                    'title'   => 'URL Ya Registrada',
                    'message' => "La URL de Rexel que intentas añadir ya existe en el sistema y se encuentra asignada a la categoría '{$existing_category}'. Cada enlace de búsqueda debe ser único en el catálogo.",
                    'tip'     => "Si deseas utilizar esta URL en la categoría actual, primero elimínala de '{$existing_category}' en la lista inferior, o ingresa un enlace con filtros de búsqueda diferentes.",
                    'type'    => 'warning',
                    'code'    => 'duplicate_url_found'
                ];
            }

            return [
                'title'   => 'URL Duplicada en el Catálogo',
                'message' => "Esta URL de Rexel ya está registrada en la base de datos para otra categoría o producto. No se pueden registrar dos enlaces idénticos.",
                'tip'     => "Revisa los enlaces activos en la lista inferior. Para reasignar el enlace, elimínalo primero de la categoría donde ya está en uso.",
                'type'    => 'warning',
                'code'    => 'duplicate_url'
            ];
        }

        // 2. UNIQUE CONSTRAINT: mapeo_procore.nombre_procore
        if (str_contains($raw_msg, 'UNIQUE constraint failed: mapeo_procore.nombre_procore') ||
            (str_contains($raw_msg, 'UNIQUE constraint failed') && str_contains($raw_msg, 'mapeo_procore'))) {
            return [
                'title'   => 'Ítem Procore Duplicado',
                'message' => "El archivo de mapeo/diccionario contiene un nombre o código de Procore que ya se encuentra registrado en el sistema.",
                'tip'     => "Verifica la Columna A de tu archivo Excel. Cada código o descripción de Procore debe ser único dentro del catálogo.",
                'type'    => 'error',
                'code'    => 'duplicate_procore_item'
            ];
        }

        // 3. UNIQUE CONSTRAINT: app_config.key
        if (str_contains($raw_msg, 'UNIQUE constraint failed: app_config.key')) {
            return [
                'title'   => 'Configuración Duplicada',
                'message' => "Ya existe una entrada de configuración registrada con esta misma clave.",
                'tip'     => "Actualiza el valor existente en lugar de intentar crear una clave duplicada.",
                'type'    => 'warning',
                'code'    => 'duplicate_config_key'
            ];
        }

        // 4. OTROS UNIQUE CONSTRAINTS GENÉRICOS
        if (str_contains($raw_msg, 'UNIQUE constraint failed')) {
            return [
                'title'   => 'Registro Duplicado',
                'message' => "La información que intentas guardar entra en conflicto con otro registro ya existente en la base de datos.",
                'tip'     => "Verifica que no estés intentando crear un elemento con el mismo nombre, código o enlace que ya existe en el sistema.",
                'type'    => 'warning',
                'code'    => 'unique_violation'
            ];
        }

        // 5. DOMINIO NO PERMITIDO / NO REXEL
        if (str_contains($raw_msg, 'Solo se permiten URLs legítimas del dominio rexelusa.com') ||
            str_contains(strtolower($raw_msg), 'rexelusa.com')) {
            return [
                'title'   => 'Dominio Web No Permitido',
                'message' => "El enlace ingresado no pertenece al sitio oficial de Rexel USA. El actualizador de precios solo puede extraer datos de enlaces alojados en 'rexelusa.com'.",
                'tip'     => "Asegúrate de copiar el enlace directamente de tu navegador mientras navegas por Rexel (ejemplo: https://www.rexelusa.com/c/...).",
                'type'    => 'error',
                'code'    => 'invalid_domain'
            ];
        }

        // 6. FORMATO DE URL INVÁLIDO
        if (str_contains($raw_msg, 'URL válida son requeridas') || str_contains($raw_msg, 'URL no es válida') || str_contains($raw_msg, 'FILTER_VALIDATE_URL')) {
            return [
                'title'   => 'Formato de Enlace Inválido',
                'message' => "La dirección introducida no tiene el formato estándar de una URL web válida o le falta el protocolo.",
                'tip'     => "Verifica que la dirección comience con 'https://' y contenga una ruta web completa sin espacios ni caracteres extraños.",
                'type'    => 'error',
                'code'    => 'invalid_url_format'
            ];
        }

        // 7. NOMBRE DE CATEGORÍA INVÁLIDO O CARACTERES PROHIBIDOS
        if (str_contains($raw_msg, 'caracteres no permitidos') || str_contains($raw_msg, 'caracteres inválidos') || str_contains($raw_msg, 'Nombre de categoría inválido')) {
            return [
                'title'   => 'Nombre de Categoría No Válido',
                'message' => "El nombre de la categoría contiene caracteres no admitidos por el sistema de archivos.",
                'tip'     => "Utiliza únicamente letras, números, espacios y guiones simples (evita símbolos como / \\ : * ? \" < > |).",
                'type'    => 'error',
                'code'    => 'invalid_category_name'
            ];
        }

        // 8. CATEGORÍA YA EXISTENTE AL CREAR NUEVA
        if (str_contains($raw_msg, 'La categoría ya existe') || str_contains($raw_msg, 'categoría ya existe')) {
            return [
                'title'   => 'Categoría Ya Existente',
                'message' => "Ya existe una categoría creada con este nombre en el sistema.",
                'tip'     => "No es necesario crear la categoría nuevamente. Puedes añadir más enlaces o actualizar sus plantillas directamente en la tarjeta de esa categoría.",
                'type'    => 'info',
                'code'    => 'category_already_exists'
            ];
        }

        // 9. ARCHIVOS FALTANTES AL CREAR NUEVA CATEGORÍA
        if (str_contains($raw_msg, 'plantilla Excel') && (str_contains($raw_msg, 'obligatorio') || str_contains($raw_msg, 'requerida'))) {
            return [
                'title'   => 'Plantilla Excel Requerida',
                'message' => "Para dar de alta una nueva categoría, es obligatorio adjuntar el archivo de Plantilla Excel (.xlsx).",
                'tip'     => "Sube la plantilla de Excel base donde el sistema escribirá los precios cotizados de Procore.",
                'type'    => 'error',
                'code'    => 'missing_template_file'
            ];
        }

        if (str_contains($raw_msg, 'Diccionario') && (str_contains($raw_msg, 'obligatorio') || str_contains($raw_msg, 'requerido'))) {
            return [
                'title'   => 'Diccionario / Mapeo Requerido',
                'message' => "Para registrar una nueva categoría, debes proporcionar el archivo Excel (.xlsx) con los nombres de Procore y Rexel a mapear.",
                'tip'     => "Descarga la plantilla base desde el botón en el formulario, complétala con tus productos y adjúntala.",
                'type'    => 'error',
                'code'    => 'missing_dictionary_file'
            ];
        }

        // 10. FORMATO DE ARCHIVO INVÁLIDO (NO XLSX)
        if (str_contains($raw_msg, 'extensión .xlsx') || str_contains($raw_msg, 'debe ser un archivo de Excel')) {
            return [
                'title'   => 'Formato de Archivo No Compatible',
                'message' => "Uno de los archivos adjuntos no tiene el formato Excel (.xlsx) requerido por el sistema.",
                'tip'     => "Asegúrate de guardar tus archivos en formato 'Libro de Excel (*.xlsx)' antes de subirlos. Archivos .csv, .xls antiguos o .pdf no son admitidos.",
                'type'    => 'error',
                'code'    => 'invalid_file_extension'
            ];
        }

        // 11. ERRORES DE TAMAÑO O SUBIDA DE ARCHIVOS
        if (str_contains($raw_msg, 'tamaño máximo permitido') || str_contains($raw_msg, 'UPLOAD_ERR_INI_SIZE')) {
            return [
                'title'   => 'Archivo Demasiado Grande',
                'message' => "El archivo seleccionado supera el límite de tamaño permitido por el servidor web.",
                'tip'     => "Reduce el tamaño del libro de Excel eliminando imágenes, hojas vacías o formatos innecesarios.",
                'type'    => 'error',
                'code'    => 'file_too_large'
            ];
        }

        // 12. ERROR EN DICCIONARIO / PYTHON EXECUTION
        if (str_contains($raw_msg, 'Error en Diccionario:') || str_contains($raw_msg, 'import_dictionary_single')) {
            $detail = str_replace(['Error en Diccionario:', '[ERROR]', '[ERROR CRITICO]'], '', $raw_msg);
            return [
                'title'   => 'Error en el Archivo de Diccionario',
                'message' => "Ocurrió un problema al leer las columnas del Excel: " . trim($detail),
                'tip'     => "Asegúrate de que la hoja de cálculo tenga al menos 2 columnas con datos: Columna A (Nombre Procore) y Columna B o C (Nombre o Búsqueda Rexel).",
                'type'    => 'error',
                'code'    => 'dictionary_parse_error'
            ];
        }

        // 13. BASE DE DATOS BLOQUEADA / CONCURRENCIA
        if (str_contains($raw_msg, 'database is locked') || str_contains($raw_msg, 'busy timeout') || (str_contains($raw_msg, 'HY000') && str_contains($raw_msg, '5'))) {
            return [
                'title'   => 'Base de Datos Ocupada',
                'message' => "La base de datos se encuentra momentáneamente bloqueada por otro proceso activo (como una ejecución de scraping o sincronización de precios).",
                'tip'     => "Espera unos segundos a que termine la sincronización activa y vuelve a presionar el botón.",
                'type'    => 'warning',
                'code'    => 'db_locked'
            ];
        }

        // 14. BASE DE DATOS SOLO LECTURA O DISCO LLENO
        if (str_contains($raw_msg, 'readonly database') || str_contains($raw_msg, 'disk I/O error')) {
            return [
                'title'   => 'Error de Almacenamiento',
                'message' => "No se pudo escribir en la base de datos de SQLite debido a restricciones de permisos en el disco o almacenamiento lleno.",
                'tip'     => "Verifica los permisos de escritura en la carpeta 'database/' del proyecto.",
                'type'    => 'error',
                'code'    => 'db_storage_error'
            ];
        }

        // 15. CREDENCIALES DE PROCORE INCORRECTAS
        if (str_contains($raw_msg, 'Credenciales de Procore incorrectas') || str_contains($raw_msg, 'Verificación fallida')) {
            return [
                'title'   => 'Autenticación Procore Fallida',
                'message' => "El inicio de sesión de prueba en Procore fue rechazado con el correo y contraseña proporcionados.",
                'tip'     => "Revisa que el correo y contraseña sean los mismos que utilizas para ingresar a app.procore.com.",
                'type'    => 'error',
                'code'    => 'procore_auth_failed'
            ];
        }

        // 16. TOKEN CSRF O SESIÓN CADUCADA
        if (str_contains($raw_msg, 'CSRF Token inválido') || str_contains($raw_msg, 'Token CSRF')) {
            return [
                'title'   => 'Sesión de Seguridad Caducada',
                'message' => "Tu token de seguridad o sesión en el navegador ha expirado.",
                'tip'     => "Por favor recarga la página en tu navegador (F5 o Ctrl+R) y vuelve a enviar el formulario.",
                'type'    => 'warning',
                'code'    => 'csrf_expired'
            ];
        }

        // 17. RESTRICCIÓN DE INTEGRIDAD GENÉRICA (SQLSTATE[23000])
        if (str_contains($raw_msg, 'SQLSTATE[23000]') || str_contains($raw_msg, 'Integrity constraint violation')) {
            return [
                'title'   => 'Conflicto de Integridad en Base de Datos',
                'message' => "La base de datos rechazó la operación porque los datos ingresados violan una regla de unicidad o relación del sistema.",
                'tip'     => "Comprueba que la URL o el nombre de categoría no estén ya registrados en el catálogo.",
                'type'    => 'error',
                'code'    => 'integrity_violation'
            ];
        }

        // 18. FALLBACK LIMPIO: SI EL MENSAJE ES LEGIBLE, MOSTRARLO SIN RUIDO
        $clean_msg = preg_replace('/SQLSTATE\[.*?\]:\s*/', '', $raw_msg);
        return [
            'title'   => 'Aviso del Sistema',
            'message' => htmlspecialchars($clean_msg, ENT_QUOTES, 'UTF-8'),
            'tip'     => "Revisa los datos ingresados e intenta nuevamente.",
            'type'    => 'error',
            'code'    => 'generic_error'
        ];
    }
}
