# Solución de Problemas

## Problemas comunes y soluciones

---

### 1. El plugin no aparece en WooCommerce

**Síntoma:** No hay menú "Split Payment" bajo WooCommerce.

**Soluciones:**
- Verificar que WooCommerce esté instalado y activo
- Desactivar y reactivar el plugin
- Revisar el log de errores PHP (`wp-content/debug.log`)

---

### 2. Error "Access Token inválido" en MercadoPago

**Síntoma:** Al verificar credenciales aparece un error de autenticación.

**Soluciones:**
- Copiar el token nuevamente desde el panel de MercadoPago (sin espacios)
- Verificar que el ambiente sea correcto: `TEST-` para sandbox, `APP_USR-` para producción
- Revisar si el token fue revocado en el panel

---

### 3. Webhook no se crea automáticamente

**Síntoma:** Al hacer click en "Crear Webhook" aparece error.

**Causas posibles:**
- El sitio no es accesible públicamente (localhost, staging detrás de firewall)
- El Access Token no tiene permisos para webhooks
- MercadoPago devuelve error 422 (URL duplicada con diferente configuración)

**Soluciones:**
1. Usar ngrok o similar para exponer localhost temporalmente
2. Crear el webhook manualmente desde el panel de MercadoPago
3. Verificar en `https://api.mercadopago.com/v1/webhooks` si ya existe

---

### 4. Los pagos no se confirman (estado "pending" para siempre)

**Síntoma:** El pedido queda en "Pago pendiente" aunque el cliente pagó.

**Causas posibles:**
- El webhook no está configurado o no llega al servidor
- Error en la validación de firma del webhook
- El servidor devuelve error 500 al procesar el webhook

**Diagnóstico:**
1. Revisar **WooCommerce → Split Payment → Dashboard** → Webhook Logs
2. Verificar que la URL del webhook sea correcta en el panel de la pasarela
3. Revisar `wp-content/debug.log` con WP_DEBUG activo

---

### 5. QR se muestra como texto en lugar de imagen

**Síntoma:** El QR aparece como JSON text en vez de una imagen escaneable.

**Solución:**
Incluir la librería `qrcode-generator` antes del script del modal:

```html
<script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js"></script>
```

O via WordPress:
```php
wp_enqueue_script(
    'qrcode-generator',
    'https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js',
    array(),
    '1.4.4',
    true
);
```

---

### 6. Error "Encryption key not available"

**Síntoma:** Las credenciales no se pueden guardar o descifrar.

**Solución:**
Verificar que la extensión OpenSSL de PHP esté habilitada:

```php
// En wp-config.php o un plugin de diagnóstico:
var_dump(extension_loaded('openssl')); // debe ser true
```

Opcionalmente, definir una clave de cifrado explícita:
```php
// wp-config.php
define('SPG_ENCRYPTION_KEY', 'tu-clave-aleatoria-de-32-caracteres');
```

---

### 7. El modal no aparece en el checkout

**Síntoma:** La página de checkout no muestra el modal de pago dividido.

**Causas:**
- El gateway "Split Payment Gateway" no está habilitado en WooCommerce
- Errores JavaScript en la consola del navegador
- Conflicto con el tema o plugins de cache

**Soluciones:**
1. Ir a **WooCommerce → Ajustes → Pagos** → Activar "Split Payment Gateway"
2. Revisar la consola del navegador (F12) por errores JS
3. Desactivar temporalmente plugins de cache/optimización
4. Vaciar cachés de CDN

---

### 8. Logs de depuración

Para activar logs detallados, agregar en `wp-config.php`:

```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

Los logs del plugin se escriben en `wp-content/debug.log` con el prefijo `[SPG]`.

---

## Contacto y soporte

- 📁 GitHub Issues: [https://github.com/Rodogaby1985/pasarela-pagos-divididos/issues](https://github.com/Rodogaby1985/pasarela-pagos-divididos/issues)
- 📖 Documentación: Ver otros archivos en `docs/`
