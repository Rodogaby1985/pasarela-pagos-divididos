# Webhooks – Guía de Configuración

## ¿Cómo funcionan los webhooks?

Los webhooks son notificaciones HTTP que las pasarelas de pago envían a tu sitio
cuando ocurre un evento (pago aprobado, rechazado, reembolso, etc.).

El plugin recibe estos webhooks, valida la firma criptográfica y actualiza el estado
del pedido automáticamente.

---

## URLs de webhooks

| Gateway | URL |
|---------|-----|
| MercadoPago | `POST https://tutienda.com/wp-json/spg/v1/webhooks/mercadopago` |
| QR Transfer | `POST https://tutienda.com/wp-json/spg/v1/webhooks/qr-transfer` |
| Nave | `POST https://tutienda.com/wp-json/spg/v1/webhooks/nave` |
| Stripe | `POST https://tutienda.com/wp-json/spg/v1/webhooks/stripe` |
| PayPal | `POST https://tutienda.com/wp-json/spg/v1/webhooks/paypal` |

---

## MercadoPago – Creación automática de webhook

El plugin puede **crear el webhook automáticamente** en MercadoPago:

1. Ir a **WooCommerce → SPG Gateways**
2. Configurar el Access Token de MercadoPago
3. Click en **Crear / Verificar Webhook**
4. El plugin llama a la API de MercadoPago y registra la URL

### ¿Qué hace el plugin automáticamente?

1. Consulta los webhooks existentes en MercadoPago (`GET /v1/webhooks`)
2. Si ya existe uno con la misma URL, lo reporta como activo
3. Si no existe, crea uno nuevo (`POST /v1/webhooks`)
4. Guarda el `webhook_id` en WordPress options para referencia

### Alternativa manual

Si la creación automática falla, podés configurarlo manualmente:

1. Ir a [https://www.mercadopago.com.ar/developers/panel](https://www.mercadopago.com.ar/developers/panel)
2. Ir a **Webhooks** → **Configurar notificaciones**
3. Agregar la URL: `https://tutienda.com/wp-json/spg/v1/webhooks/mercadopago`
4. Seleccionar eventos: `Pagos`

---

## Validación de firmas

### MercadoPago (HMAC-SHA256)
MercadoPago envía el header `X-Signature` con la firma del payload.

El plugin verifica: `HMAC_SHA256(raw_body, webhook_secret) == X-Signature`

### QR Transfer (HMAC-SHA256)
El banco/agregador debe enviar el header `X-SPG-Signature` con:
`sha256=HMAC_SHA256(raw_body, webhook_secret)`

---

## API REST para administradores

Estos endpoints requieren autenticación de administrador (`manage_woocommerce`):

### Crear webhook en MercadoPago
```
POST /wp-json/spg/v1/admin/webhook/create
{
  "gateway": "mercadopago"
}
```

Respuesta:
```json
{
  "success": true,
  "webhook_id": "12345",
  "message": "Webhook created successfully."
}
```

### Verificar webhook activo
```
GET /wp-json/spg/v1/admin/webhook/verify?gateway=mercadopago
```

Respuesta:
```json
{
  "active": true,
  "webhook_id": "12345",
  "message": "Webhook is active."
}
```

---

## Requisitos del servidor

Para que los webhooks funcionen correctamente:

- ✅ El sitio debe ser **públicamente accesible** (no localhost)
- ✅ HTTPS habilitado (la mayoría de pasarelas lo requieren)
- ✅ El firewall no debe bloquear las IPs de las pasarelas
- ✅ `wp-json` debe ser accesible (verificar configuración de permalinks)

---

## Testing de webhooks en desarrollo

Para testear webhooks en localhost, usar herramientas como:

- [ngrok](https://ngrok.com/) - tunneling a localhost
- [cloudflared tunnel](https://developers.cloudflare.com/cloudflare-one/connections/connect-apps/)

Con WP_DEBUG=true, la validación de firma de QR Transfer se omite para facilitar el desarrollo.
