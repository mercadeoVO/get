<?php
// Copia este archivo como drive_config.php (que NO se versiona) y ajusta los valores.
//
// Pasos en Google Cloud (una sola vez):
//  1. Crear un proyecto y habilitar la "Google Drive API".
//  2. IAM y administración → Cuentas de servicio → crear una → Claves → Agregar clave (JSON).
//  3. Guardar el JSON descargado como app/drive_service_account.json (tampoco se versiona).
//  4. En Drive, compartir la carpeta con el correo de la cuenta de servicio (client_email del JSON)
//     con permiso de "Lector". Sin ese paso, el listado sale vacío.
return [
    'sa_file'     => 'drive_service_account.json',                    // ruta absoluta o relativa a app/
    'root_folder' => '1r9iveO0fSwvB4I8eKZRhvpRHq0j7pxle',             // ID de la carpeta raíz (CAG-VO)
];
