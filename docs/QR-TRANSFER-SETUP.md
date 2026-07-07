# QR Transfer – Guía de Configuración (Argentina)

## ¿Qué es QR Transfer?

El módulo QR Transfer genera un código QR dinámico que el cliente escanea con su app bancaria
(Mercado Pago, MODO, homebanking, etc.) para realizar una transferencia bancaria directamente
a la cuenta del vendedor/operador logístico.

**Ventajas:**
- Sin comisión de pasarela
- El dinero llega directamente a la cuenta bancaria
- Compatible con cualquier banco argentino (BCRA interoperabilidad)
- Soporte para CBU, CVU y Alias

---

## 1. Requisitos previos

- Tener una cuenta bancaria argentina con alias configurado
- (Opcional) CBU o CVU como alternativa si el alias falla
- Opcionalmente: un sistema que envíe webhooks de confirmación (banco, agregador, BCRA)

---

## 2. Configurar en el plugin

Ir a **WooCommerce → SPG Gateways** → sección **QR Transfer (Argentina)**

### Subtotal – Cuenta Tienda

| Campo | Descripción | Ejemplo |
|-------|-------------|---------|
| Alias | Alias bancario de la cuenta de la tienda | `tienda.empresa.ar` |
| CBU/CVU | Fallback si el alias no es reconocido | `0000000000000000000000` |
| Titular | Nombre del titular de la cuenta | `Empresa S.R.L.` |

### Envío – Cuenta Operador Logístico

| Campo | Descripción | Ejemplo |
|-------|-------------|---------|
| Alias | Alias bancario del operador logístico | `operador.logistico.ar` |
| CBU/CVU | Fallback | `0000000000000000000000` |
| Titular | Nombre del titular | `Logística S.A.` |

---

## 3. Formato del QR

El QR codifica el siguiente JSON:

```json
{
  "v":       "1",
  "alias":   "tienda.empresa.ar",
  "amount":  "100.00",
  "currency": "ARS",
  "concept": "Orden #123",
  "ref":     "123-total",
  "exp":     1700000000,
  "hash":    "sha256-hmac..."
}
```

- **Validez:** 15 minutos
- **hash:** HMAC-SHA256 generado por el plugin para validar integridad

---

## 4. Flujo de pago

```
Cliente escanea QR con app bancaria
       ↓
App pre-completa: alias, monto, concepto
       ↓
Cliente confirma la transferencia
       ↓
Banco notifica via webhook:
POST https://tutienda.com/wp-json/spg/v1/webhooks/qr-transfer
Body: {
  "event":          "qr.payment.confirmed",
  "transaction_id": "<qr_hash>",
  "order_ref":      "123-total",
  "amount":         "100.00",
  "status":         "confirmed"
}
       ↓
Plugin valida hash y actualiza el estado del pedido ✅
```

---

## 5. Configurar webhook del banco

Si tu banco o proveedor soporta webhooks de confirmación:

1. **URL del webhook:** `https://tutienda.com/wp-json/spg/v1/webhooks/qr-transfer`
2. **Método:** `POST`
3. **Header requerido:** `X-SPG-Signature: sha256=HMAC_SHA256(body, webhook_secret)`
4. Configurar el **Webhook Secret** en la misma página de configuración

Si tu banco no envía webhooks, el administrador puede confirmar manualmente los pagos.

---

## 6. Solución de problemas

### "Alias no configurado"
- Verificar que el alias esté ingresado en la configuración
- El alias debe ser exactamente como aparece en tu banco (sin espacios)

### "QR expirado"
- El QR tiene validez de 15 minutos
- El cliente puede hacer click en "Renovar QR" para obtener uno nuevo

### "Webhook no validado"
- Verificar que el Webhook Secret sea correcto en ambos lados
- En desarrollo con `WP_DEBUG=true`, la validación de firma es omitida para facilitar pruebas
