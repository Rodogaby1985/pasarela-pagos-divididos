# Distribución del Plugin

## Para Clientes (Instalación)

### Requisitos

| Componente | Versión mínima |
|---|---|
| WordPress | 5.0+ |
| WooCommerce | 6.0+ |
| PHP | 7.4+ |

> ✅ **No se requiere:** Composer, Node.js, npm, acceso SSH ni línea de comandos.

---

### Pasos de Instalación

#### 1. Descargar

1. Ve a: **https://github.com/Rodogaby1985/pasarela-pagos-divididos/releases**
2. Abre la última versión (Latest release)
3. Descarga el archivo: `split-payment-plugin-READY.zip`

#### 2. Verificar integridad (opcional pero recomendado)

Descarga también `split-payment-plugin-READY.zip.sha256` y verifica:

```bash
# Linux / macOS — en la carpeta donde descargaste el archivo
sha256sum -c split-payment-plugin-READY.zip.sha256

# Windows PowerShell
(Get-FileHash split-payment-plugin-READY.zip -Algorithm SHA256).Hash
# Compara el hash con el contenido del archivo .sha256
```

Si el resultado es `OK` (Linux/macOS) o los hashes coinciden (Windows), el archivo es íntegro.

#### 3. Subir a WordPress

1. Ingresa a **WordPress Admin → Plugins → Agregar nuevo**
2. Haz clic en **"Subir plugin"** (parte superior)
3. Selecciona el archivo `split-payment-plugin-READY.zip`
4. Haz clic en **"Instalar ahora"**

#### 4. Activar

1. Haz clic en **"Activar plugin"**
2. El plugin creará automáticamente las tablas de base de datos necesarias

#### 5. Configurar

1. Ve a **WooCommerce → Split Payment → Gateways**
2. Configura MercadoPago (o QR Transfer)
3. Guarda los cambios

#### 6. Listo ✅

El plugin está instalado y funcionando. No se necesita ningún paso adicional.

---

## Para Desarrolladores (Build)

### Generar un Release localmente

#### Linux / macOS

```bash
git clone https://github.com/Rodogaby1985/pasarela-pagos-divididos.git
cd pasarela-pagos-divididos
chmod +x build.sh
./build.sh
```

Esto genera: `split-payment-plugin-READY.zip`

#### Windows

```batch
git clone https://github.com/Rodogaby1985/pasarela-pagos-divididos.git
cd pasarela-pagos-divididos
build.bat
```

> Requiere 7-Zip instalado en `C:\Program Files\7-Zip\7z.exe`

---

### Publicar un Release en GitHub

1. **Crear y empujar un tag semántico**

```bash
git tag v1.0.0
git push origin v1.0.0
```

2. **GitHub Actions se encarga automáticamente de:**
   - Instalar dependencias PHP y Node.js
   - Compilar los assets (webpack)
   - Crear el ZIP pre-compilado
   - Generar el checksum SHA-256
   - Crear el GitHub Release
   - Subir el ZIP y el checksum al Release

3. **Verificar en GitHub**

   Ve a: https://github.com/Rodogaby1985/pasarela-pagos-divididos/releases

   Confirma que los archivos `split-payment-plugin-READY.zip` y `.sha256` están disponibles.

---

### Versionado Semántico

Se sigue [Semantic Versioning (semver.org)](https://semver.org/):

| Tag | Cuándo usarlo |
|-----|---------------|
| `v1.0.0` | Cambios mayores (incompatibles con versiones anteriores) |
| `v1.0.1` | Corrección de errores (bugfix) |
| `v1.1.0` | Nueva funcionalidad compatible con versiones anteriores |

---

### Contenido del ZIP distribuido

El archivo pre-compilado incluye sólo los archivos necesarios para producción:

```
split-payment-plugin/
├── split-payment-plugin.php
├── uninstall.php
├── vendor/              ← Dependencias PHP ya instaladas
├── includes/
├── admin/
└── assets/
    ├── js/*.min.js      ← Assets ya compilados
    └── css/*.min.css    ← Assets ya compilados
```

**No se incluye:** `node_modules/`, archivos de desarrollo, tests, configuración de build, `.git/`.

---

## Solución de Problemas

### "No se puede subir el plugin: el archivo supera el tamaño máximo"

Solución: Aumentar el límite en WordPress. Edita `wp-config.php`:

```php
@ini_set( 'upload_max_filesize', '64M' );
@ini_set( 'post_max_size', '64M' );
```

O contacta a tu hosting para que aumente el límite.

### "Error al activar: clase no encontrada"

El ZIP puede estar incompleto. Intenta:
1. Descargarlo nuevamente
2. Verificar el checksum SHA-256
3. Subir nuevamente

### "El plugin se activa pero WooCommerce no aparece la opción Split Payment"

Asegúrate de que WooCommerce esté instalado y activo antes de activar este plugin.

### Soporte

Para reportar problemas, abre un issue en:
https://github.com/Rodogaby1985/pasarela-pagos-divididos/issues
