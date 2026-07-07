# MercadoPago – Guía de Configuración

## 1. Obtener credenciales

1. Ir a [https://www.mercadopago.com.ar/developers/panel](https://www.mercadopago.com.ar/developers/panel)
2. Iniciar sesión con tu cuenta de MercadoPago
3. En el menú lateral: **Credenciales de desarrollo** o **Credenciales de producción**
4. Copiar:
   - **Access Token** (empieza con `APP_USR-` o `TEST-`)
   - **User ID** (número)

### Diferencias Sandbox vs Producción

| | Sandbox | Producción |
|---|---|---|
| Access Token | `TEST-xxxx-xxxx` | `APP_USR-xxxx-xxxx` |
| Dinero real | ❌ No | ✅ Sí |
| Uso recomendado | Testing | Tienda activa |

---

## 2. Configurar en el plugin

1. Ir a **WooCommerce → SPG Gateways**
2. En la sección **MercadoPago**:
   - Activar el toggle
   - Seleccionar ambiente: `Sandbox` (testing) o `Producción`
   - Pegar el **Access Token**
   - Ingresar el **User ID**
3. Click en **Verificar Credenciales** → deberías ver ✅
4. Click en **Crear / Verificar Webhook** → el plugin registra automáticamente la URL

---

## 3. Flujo de pago

```
Cliente elige MercadoPago en checkout
       ↓
Plugin crea preferencia en /checkout/preferences
       ↓
Cliente es redirigido a MercadoPago (o abre popup)
       ↓
Cliente elige método: tarjeta, billetera, transferencia, cuotas
       ↓
MercadoPago procesa el pago
       ↓
MercadoPago envía webhook a:
POST https://tutienda.com/wp-json/spg/v1/webhooks/mercadopago
       ↓
Plugin valida firma HMAC-SHA256
       ↓
Actualiza estado del pedido ✅
```

---

## 4. Sandbox – Tarjetas de prueba

| Tipo | Número | CVV | Vto | Resultado |
|------|--------|-----|-----|-----------|
| Visa | 4509 9535 6623 3704 | 123 | 11/25 | Aprobado |
| Mastercard | 5031 7557 3453 0604 | 123 | 11/25 | Aprobado |
| Visa rechazada | 4000 0000 0000 0002 | 123 | 11/25 | Rechazado |

Para más tarjetas de prueba: [https://www.mercadopago.com.ar/developers/es/docs/your-integrations/test/cards](https://www.mercadopago.com.ar/developers/es/docs/your-integrations/test/cards)

---

## 5. Solución de problemas

### "Access Token inválido"
- Verificar que no tenga espacios al inicio/fin
- Asegurarse de usar el token del ambiente correcto (TEST- para sandbox)

### "User ID no coincide"
- Obtener el User ID correcto desde el panel de MercadoPago
- Ir a `https://api.mercadopago.com/users/me` con tu token para verificarlo

### "Webhook no encontrado"
- Click en "Crear / Verificar Webhook" en la configuración
- Verificar que el sitio sea públicamente accesible (no funciona en localhost)
